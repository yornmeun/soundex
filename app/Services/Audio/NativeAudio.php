<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;

/**
 * Pure-PHP audio handling used when FFmpeg is not installed ("basic mode").
 *
 * - MP3 (MPEG Layer III): clips are joined frame by frame without re-encoding; gaps are
 *   filled with silent MP3 frames. Loudness normalisation and silence trimming are skipped
 *   because PHP cannot decode MP3.
 * - WAV (16-bit PCM): clips are joined sample-accurately, with optional silence trimming and
 *   loudness normalisation (RMS-based, peak-limited).
 * - Mixing music / sound effects is not supported: it needs FFmpeg.
 *
 * Narration is never time-stretched or sped up.
 */
class NativeAudio
{
    private const BITRATES_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];

    private const BITRATES_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];

    private const SAMPLE_RATES = [
        3 => [44100, 48000, 32000], // MPEG 1
        2 => [22050, 24000, 16000], // MPEG 2
        0 => [11025, 12000, 8000],  // MPEG 2.5
    ];

    /** Samples per processing block for WAV work (keeps memory flat for long narrations). */
    private const BLOCK = 65536;

    // ------------------------------------------------------------------ public API

    public function probeDuration(string $path): float
    {
        return match ($this->detect($path)) {
            'mp3' => round($this->mp3Info($path)['samples'] / $this->mp3Info($path)['sample_rate'], 3),
            'wav' => round($this->wavInfo($path)['frames'] / $this->wavInfo($path)['sample_rate'], 3),
            default => throw new AudioProcessingException('Could not read audio duration: only MP3 and WAV files can be read without FFmpeg.'),
        };
    }

    public function isAudioFile(string $path): bool
    {
        try {
            return $this->detect($path) !== null && $this->probeDuration($path) > 0;
        } catch (AudioProcessingException) {
            return false;
        }
    }

    /**
     * @param  list<string>  $inputs
     * @param  array{silence_ms?:int,normalize?:bool,trim_silence?:bool,format?:string}  $options
     */
    public function merge(array $inputs, string $output, array $options = []): void
    {
        if ($inputs === []) {
            throw new AudioProcessingException('No audio files to merge.');
        }

        $silenceMs = max(0, (int) ($options['silence_ms'] ?? 0));
        $items = [];
        foreach (array_values($inputs) as $i => $input) {
            if ($i > 0 && $silenceMs > 0) {
                $items[] = ['silence_ms' => $silenceMs];
            }
            $items[] = ['path' => $input];
        }

        $this->render($items, $output, $options);
    }

    /**
     * Place clips at their start times. Gaps become silence; a clip that would overlap the
     * previous one is played right after it instead (mixing needs FFmpeg). The timing report
     * already flags overlapping segments.
     *
     * @param  list<array{path:string,start_ms:int}>  $clips
     */
    public function timeline(array $clips, string $output, array $options = []): void
    {
        if ($clips === []) {
            throw new AudioProcessingException('No audio clips to place on the timeline.');
        }

        usort($clips, fn ($a, $b) => $a['start_ms'] <=> $b['start_ms']);
        $items = [];
        foreach ($clips as $clip) {
            $items[] = ['path' => $clip['path'], 'at_ms' => max(0, (int) $clip['start_ms'])];
        }

        $this->render($items, $output, ['trim_silence' => false] + $options);
    }

    // ------------------------------------------------------------------ rendering

    /** @param list<array{path?:string,silence_ms?:int,at_ms?:int}> $items */
    private function render(array $items, string $output, array $options): void
    {
        foreach ($items as $item) {
            if (isset($item['path']) && ! is_file($item['path'])) {
                throw new AudioProcessingException('Missing audio file: '.basename($item['path']));
            }
        }

        $first = collect($items)->firstWhere(fn ($i) => isset($i['path']))['path'];
        $format = $this->detect($first);
        $wanted = $options['format'] ?? $format;

        if ($format === null) {
            throw new AudioProcessingException('Only MP3 and WAV audio can be merged without FFmpeg.');
        }
        if ($wanted !== $format) {
            throw new AudioProcessingException("Converting {$format} to {$wanted} needs FFmpeg. Choose {$format} as the output format.");
        }

        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }

        $format === 'mp3'
            ? $this->renderMp3($items, $output)
            : $this->renderWav($items, $output, (bool) ($options['trim_silence'] ?? false), (bool) ($options['normalize'] ?? true));

        if (! is_file($output) || filesize($output) === 0) {
            throw new AudioProcessingException('Merging the audio failed: the output file is empty.');
        }
    }

    // ------------------------------------------------------------------ MP3

    private function renderMp3(array $items, string $output): void
    {
        $reference = null;
        $out = fopen($output, 'wb');
        $cursor = 0; // samples written so far

        try {
            foreach ($items as $item) {
                if (isset($item['path'])) {
                    $info = $this->mp3Info($item['path']);
                    $reference ??= $info;
                    if ([$info['version'], $info['sample_rate'], $info['mono']] !== [$reference['version'], $reference['sample_rate'], $reference['mono']]) {
                        throw new AudioProcessingException('The audio clips use different MP3 settings (sample rate / channels), so they cannot be joined without FFmpeg.');
                    }

                    if (isset($item['at_ms'])) {
                        $gap = (int) round($item['at_ms'] / 1000 * $info['sample_rate']) - $cursor;
                        $cursor += $this->writeMp3Silence($out, $reference, $gap);
                    }

                    $data = file_get_contents($item['path']);
                    foreach ($info['frames'] as [$offset, $length]) {
                        fwrite($out, substr($data, $offset, $length));
                    }
                    $cursor += $info['samples'];
                } else {
                    // silence_ms items only occur between clips, so a reference exists.
                    $cursor += $this->writeMp3Silence($out, $reference, (int) round($item['silence_ms'] / 1000 * $reference['sample_rate']));
                }
            }
        } finally {
            fclose($out);
        }
    }

    /** Writes silent frames covering roughly $samples samples; returns the samples written. */
    private function writeMp3Silence($out, array $ref, int $samples): int
    {
        $count = (int) round($samples / $ref['samples_per_frame']);
        if ($count <= 0) {
            return 0;
        }

        // Same MPEG version / layer / sample rate / channel mode, no CRC, lowest bitrate, no padding.
        // An all-zero side-info block (global gain 0, no main data) decodes to digital silence.
        $header = $ref['header'];
        $b1 = ord($header[1]) | 0x01;
        $b2 = (1 << 4) | ($ref['sample_rate_index'] << 2);
        $length = $this->mp3FrameLength($ref['version'], 1, $ref['sample_rate'], 0);
        $frame = chr(0xFF).chr($b1).chr($b2).$header[3].str_repeat("\0", $length - 4);

        fwrite($out, str_repeat($frame, $count));

        return $count * $ref['samples_per_frame'];
    }

    /**
     * Scan an MP3 file: every audio frame's offset/length plus format details.
     * ID3 tags and the Xing/Info/VBRI header frame are left out so the joined file
     * reports its real length.
     */
    private function mp3Info(string $path): array
    {
        static $cache = [];
        $key = $path.'|'.@filemtime($path).'|'.@filesize($path);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $data = (string) file_get_contents($path);
        $size = strlen($data);
        $pos = 0;

        if (substr($data, 0, 3) === 'ID3' && $size >= 10) {
            $tagSize = ((ord($data[6]) & 0x7F) << 21) | ((ord($data[7]) & 0x7F) << 14) | ((ord($data[8]) & 0x7F) << 7) | (ord($data[9]) & 0x7F);
            $pos = 10 + $tagSize + ((ord($data[5]) & 0x10) ? 10 : 0);
        }
        if ($size >= 128 && substr($data, -128, 3) === 'TAG') {
            $size -= 128;
        }

        $frames = [];
        $first = null;
        $samples = 0;

        while ($pos + 4 <= $size) {
            $h = $this->mp3Header($data, $pos);
            if ($h === null || $pos + $h['length'] > $size + 1
                || ($first !== null && [$h['version'], $h['sample_rate']] !== [$first['version'], $first['sample_rate']])
                || ($pos + $h['length'] + 4 <= $size && $this->mp3Header($data, $pos + $h['length']) === null && $frames === [])) {
                $pos++; // not a frame (or a false sync before the first real one): resync

                continue;
            }

            $first ??= $h + ['header' => substr($data, $pos, 4)];
            if (! ($frames === [] && $this->isVbrHeaderFrame($data, $pos, $h))) {
                $frames[] = [$pos, min($h['length'], $size - $pos)];
                $samples += $h['samples_per_frame'];
            }
            $pos += $h['length'];
        }

        if ($first === null || $frames === []) {
            throw new AudioProcessingException('No MP3 audio frames found in '.basename($path).'.');
        }

        return $cache[$key] = $first + ['frames' => $frames, 'samples' => $samples];
    }

    private function mp3Header(string $data, int $pos): ?array
    {
        if (! isset($data[$pos + 3]) || ord($data[$pos]) !== 0xFF || (ord($data[$pos + 1]) & 0xE0) !== 0xE0) {
            return null;
        }

        $b1 = ord($data[$pos + 1]);
        $b2 = ord($data[$pos + 2]);
        $b3 = ord($data[$pos + 3]);
        $version = ($b1 >> 3) & 0x03;   // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
        $layer = ($b1 >> 1) & 0x03;     // 1 = Layer III
        $bitrateIndex = ($b2 >> 4) & 0x0F;
        $srIndex = ($b2 >> 2) & 0x03;

        if ($version === 1 || $layer !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $srIndex === 3) {
            return null;
        }

        $sampleRate = self::SAMPLE_RATES[$version][$srIndex];

        return [
            'version' => $version,
            'sample_rate' => $sampleRate,
            'sample_rate_index' => $srIndex,
            'crc' => ($b1 & 0x01) === 0,
            'mono' => (($b3 >> 6) & 0x03) === 3,
            'samples_per_frame' => $version === 3 ? 1152 : 576,
            'length' => $this->mp3FrameLength($version, $bitrateIndex, $sampleRate, ($b2 >> 1) & 0x01),
        ];
    }

    private function mp3FrameLength(int $version, int $bitrateIndex, int $sampleRate, int $padding): int
    {
        $kbps = ($version === 3 ? self::BITRATES_V1 : self::BITRATES_V2)[$bitrateIndex];

        return intdiv(($version === 3 ? 144000 : 72000) * $kbps, $sampleRate) + $padding;
    }

    private function isVbrHeaderFrame(string $data, int $pos, array $h): bool
    {
        $sideInfo = $h['version'] === 3 ? ($h['mono'] ? 17 : 32) : ($h['mono'] ? 9 : 17);
        $tag = substr($data, $pos + 4 + ($h['crc'] ? 2 : 0) + $sideInfo, 4);

        return $tag === 'Xing' || $tag === 'Info' || substr($data, $pos + 36, 4) === 'VBRI';
    }

    // ------------------------------------------------------------------ WAV

    private function renderWav(array $items, string $output, bool $trim, bool $normalize): void
    {
        $reference = null;
        $plan = []; // list of [path, byteFrom, byteTo] or ['silence', bytes]
        $cursor = 0; // frames

        foreach ($items as $item) {
            if (! isset($item['path'])) {
                $plan[] = ['silence', (int) round($item['silence_ms'] / 1000 * $reference['sample_rate']) * $reference['block_align']];
                $cursor += (int) round($item['silence_ms'] / 1000 * $reference['sample_rate']);

                continue;
            }

            $info = $this->wavInfo($item['path']);
            $reference ??= $info;
            if ([$info['sample_rate'], $info['channels'], $info['bits']] !== [$reference['sample_rate'], $reference['channels'], $reference['bits']]) {
                throw new AudioProcessingException('The audio clips use different WAV settings (sample rate / channels), so they cannot be joined without FFmpeg.');
            }
            if ($info['bits'] !== 16 && ($trim || $normalize)) {
                $trim = $normalize = false; // only 16-bit PCM is processed sample by sample
            }

            if (isset($item['at_ms'])) {
                $gap = (int) round($item['at_ms'] / 1000 * $info['sample_rate']) - $cursor;
                if ($gap > 0) {
                    $plan[] = ['silence', $gap * $info['block_align']];
                    $cursor += $gap;
                }
            }

            [$from, $to] = $trim ? $this->wavTrimRange($item['path'], $info) : [$info['data_offset'], $info['data_offset'] + $info['data_size']];
            $plan[] = [$item['path'], $from, $to];
            $cursor += intdiv($to - $from, $info['block_align']);
        }

        $gain = $normalize ? $this->wavNormalizeGain($plan) : 1.0;
        $total = array_sum(array_map(fn ($p) => $p[0] === 'silence' ? $p[1] : $p[2] - $p[1], $plan));

        $out = fopen($output, 'wb');
        try {
            fwrite($out, $this->wavHeader($reference, $total));
            foreach ($plan as $part) {
                if ($part[0] === 'silence') {
                    for ($left = $part[1]; $left > 0; $left -= self::BLOCK * 2) {
                        fwrite($out, str_repeat("\0", min($left, self::BLOCK * 2)));
                    }

                    continue;
                }

                $this->eachWavBlock($part[0], $part[1], $part[2], function (string $bytes) use ($out, $gain) {
                    if (abs($gain - 1.0) < 0.001) {
                        fwrite($out, $bytes);

                        return;
                    }
                    $samples = unpack('s*', $bytes);
                    foreach ($samples as $i => $s) {
                        $samples[$i] = max(-32768, min(32767, (int) round($s * $gain)));
                    }
                    fwrite($out, pack('s*', ...$samples));
                });
            }
        } finally {
            fclose($out);
        }
    }

    private function wavInfo(string $path): array
    {
        $fh = fopen($path, 'rb');
        try {
            $riff = fread($fh, 12);
            if (strlen($riff) < 12 || substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
                throw new AudioProcessingException(basename($path).' is not a WAV file.');
            }

            $fmt = null;
            $fileSize = filesize($path);
            while (! feof($fh)) {
                $chunk = fread($fh, 8);
                if (strlen($chunk) < 8) {
                    break;
                }
                ['id' => $id, 'size' => $size] = unpack('a4id/Vsize', $chunk);
                $start = ftell($fh);

                if ($id === 'fmt ') {
                    $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', fread($fh, 16));
                } elseif ($id === 'data') {
                    if ($fmt === null || ! in_array($fmt['format'], [1, 0xFFFE], true)) {
                        throw new AudioProcessingException(basename($path).' is not an uncompressed PCM WAV file.');
                    }
                    $size = min($size, $fileSize - $start); // streamed WAVs may report a bogus size
                    $size -= $size % $fmt['align'];

                    return [
                        'sample_rate' => $fmt['rate'],
                        'channels' => $fmt['channels'],
                        'bits' => $fmt['bits'],
                        'block_align' => $fmt['align'],
                        'data_offset' => $start,
                        'data_size' => $size,
                        'frames' => intdiv($size, $fmt['align']),
                    ];
                }

                fseek($fh, $start + $size + ($size % 2));
            }
        } finally {
            fclose($fh);
        }

        throw new AudioProcessingException(basename($path).' has no audio data.');
    }

    private function wavHeader(array $info, int $dataSize): string
    {
        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, $info['channels'], $info['sample_rate'], $info['sample_rate'] * $info['block_align'], $info['block_align'], $info['bits'])
            .'data'.pack('V', $dataSize);
    }

    /** Calls $fn with consecutive raw byte blocks between two file offsets. */
    private function eachWavBlock(string $path, int $from, int $to, callable $fn): void
    {
        $fh = fopen($path, 'rb');
        try {
            fseek($fh, $from);
            for ($left = $to - $from; $left > 0;) {
                $bytes = fread($fh, min($left, self::BLOCK * 2));
                if ($bytes === '' || $bytes === false) {
                    break;
                }
                $left -= strlen($bytes);
                $fn($bytes);
            }
        } finally {
            fclose($fh);
        }
    }

    /** Leading/trailing silence quieter than -50 dB is cut, keeping ~80 ms of natural tail. */
    private function wavTrimRange(string $path, array $info): array
    {
        $threshold = 32768 * 10 ** (-50 / 20);
        $firstLoud = null;
        $lastLoud = null;
        $index = 0; // sample index

        $this->eachWavBlock($path, $info['data_offset'], $info['data_offset'] + $info['data_size'], function (string $bytes) use (&$firstLoud, &$lastLoud, &$index, $threshold) {
            foreach (unpack('s*', $bytes) as $s) {
                if (abs($s) > $threshold) {
                    $firstLoud ??= $index;
                    $lastLoud = $index;
                }
                $index++;
            }
        });

        if ($firstLoud === null) {
            return [$info['data_offset'], $info['data_offset'] + $info['data_size']]; // all silence: keep as is
        }

        $channels = $info['channels'];
        $keep = (int) round(0.08 * $info['sample_rate']);
        $startFrame = max(0, intdiv($firstLoud, $channels) - $keep);
        $endFrame = min($info['frames'], intdiv($lastLoud, $channels) + 1 + $keep);

        return [$info['data_offset'] + $startFrame * $info['block_align'], $info['data_offset'] + $endFrame * $info['block_align']];
    }

    /** Gain that brings speech to about -18 dBFS RMS, never pushing peaks above -1.5 dBFS. */
    private function wavNormalizeGain(array $plan): float
    {
        $sumSquares = 0.0;
        $count = 0;
        $peak = 0;

        foreach ($plan as $part) {
            if ($part[0] === 'silence') {
                continue;
            }
            $this->eachWavBlock($part[0], $part[1], $part[2], function (string $bytes) use (&$sumSquares, &$count, &$peak) {
                foreach (unpack('s*', $bytes) as $s) {
                    $abs = abs($s);
                    // Ignore near-silence so pauses don't make the voice louder than intended.
                    if ($abs > 328) {
                        $sumSquares += $s * $s;
                        $count++;
                    }
                    if ($abs > $peak) {
                        $peak = $abs;
                    }
                }
            });
        }

        if ($count === 0 || $peak === 0) {
            return 1.0;
        }

        $rms = sqrt($sumSquares / $count);
        $gain = (32768 * 10 ** (-18 / 20)) / $rms;
        $maxGain = (32767 * 10 ** (-1.5 / 20)) / $peak;

        return max(0.05, min($gain, $maxGain, 8.0));
    }

    // ------------------------------------------------------------------ detection

    /** 'mp3', 'wav' or null. */
    public function detect(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 12);
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
            return 'wav';
        }
        if (str_starts_with($head, 'ID3') || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
            return 'mp3';
        }

        return null;
    }
}
