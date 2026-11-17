<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Illuminate\Support\Facades\Process;

/**
 * Audio processing. Uses FFmpeg when it is installed; otherwise falls back to a pure-PHP
 * "basic mode" (see NativeAudio) that joins MP3/WAV clips but cannot mix music or effects.
 * Narration is never time-stretched or sped up.
 */
class AudioProcessor
{
    private const SAMPLE_RATE = 44100;

    private ?bool $ffmpegFound = null;

    private ?NativeAudio $nativeAudio = null;

    public function native(): NativeAudio
    {
        return $this->nativeAudio ??= new NativeAudio;
    }

    /** Music and sound-effect mixing needs FFmpeg. */
    public function canMix(): bool
    {
        return $this->isAvailable();
    }

    public function ffmpeg(): string
    {
        return $this->resolveBinary((string) config('soundex.ffmpeg.ffmpeg_binary'));
    }

    public function ffprobe(): string
    {
        return $this->resolveBinary((string) config('soundex.ffmpeg.ffprobe_binary'));
    }

    /**
     * "ffmpeg" is looked up on PATH; "bin/ffmpeg" is relative to the project root;
     * "/usr/bin/ffmpeg" and Windows paths like "C:/ffmpeg/bin/ffmpeg.exe" are used as is.
     */
    private function resolveBinary(string $binary): string
    {
        $isAbsolute = str_starts_with($binary, '/')
            || str_starts_with($binary, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $binary) === 1;

        if ($binary !== '' && ! $isAbsolute && (str_contains($binary, '/') || str_contains($binary, '\\'))) {
            return base_path($binary);
        }

        return $binary;
    }

    /** True when FFmpeg and ffprobe can be run. Without them the app works in basic mode. */
    public function isAvailable(): bool
    {
        if ($this->ffmpegFound !== null) {
            return $this->ffmpegFound;
        }

        try {
            return $this->ffmpegFound = Process::timeout(15)->run([$this->ffmpeg(), '-version'])->successful()
                && Process::timeout(15)->run([$this->ffprobe(), '-version'])->successful();
        } catch (\Throwable) {
            return $this->ffmpegFound = false;
        }
    }

    public function version(): ?string
    {
        try {
            $result = Process::timeout(15)->run([$this->ffmpeg(), '-version']);

            return $result->successful() ? strtok($result->output(), "\n") : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function probeDuration(string $path): float
    {
        if (! $this->isAvailable()) {
            return $this->native()->probeDuration($path);
        }

        $result = Process::timeout(60)->run([
            $this->ffprobe(), '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);

        if (! $result->successful() || ! is_numeric(trim($result->output()))) {
            throw new AudioProcessingException('Could not read audio duration: '.$this->tail($result->errorOutput()));
        }

        return round((float) trim($result->output()), 3);
    }

    /**
     * Concatenate audio files in order into one output file.
     *
     * @param  list<string>  $inputs
     * @param  array{silence_ms?:int,normalize?:bool,trim_silence?:bool,format?:string,bitrate?:int}  $options
     */
    public function merge(array $inputs, string $output, array $options = []): void
    {
        if (! $this->isAvailable()) {
            $this->native()->merge($inputs, $output, $options);

            return;
        }

        if ($inputs === []) {
            throw new AudioProcessingException('No audio files to merge.');
        }

        $silenceMs = max(0, (int) ($options['silence_ms'] ?? 0));
        $filters = [];
        $labels = [];

        foreach ($inputs as $i => $input) {
            $filters[] = "[{$i}:a]".$this->prepareChain((bool) ($options['trim_silence'] ?? false))."[a{$i}]";
            if ($i > 0 && $silenceMs > 0) {
                $filters[] = 'anullsrc=r='.self::SAMPLE_RATE.':cl=mono,atrim=0:'.($silenceMs / 1000)
                    .',aformat=sample_fmts=fltp:channel_layouts=mono'."[s{$i}]";
                $labels[] = "[s{$i}]";
            }
            $labels[] = "[a{$i}]";
        }

        $filters[] = implode('', $labels).'concat=n='.count($labels).':v=0:a=1[cat]';
        $filters[] = '[cat]'.$this->finalChain((bool) ($options['normalize'] ?? true)).'[out]';

        $this->run($inputs, $filters, $output, $options);
    }

    /**
     * Place clips on a timeline at their original subtitle start times (no stretching).
     * Overlapping clips are mixed; the timing report flags them.
     *
     * @param  list<array{path:string,start_ms:int}>  $clips
     */
    public function timeline(array $clips, string $output, array $options = []): void
    {
        if (! $this->isAvailable()) {
            $this->native()->timeline($clips, $output, $options);

            return;
        }

        if ($clips === []) {
            throw new AudioProcessingException('No audio clips to place on the timeline.');
        }

        $filters = [];
        $labels = '';
        foreach ($clips as $i => $clip) {
            $delay = max(0, (int) $clip['start_ms']);
            $filters[] = "[{$i}:a]".$this->prepareChain(false).",adelay=delays={$delay}:all=1[d{$i}]";
            $labels .= "[d{$i}]";
        }

        $filters[] = count($clips) === 1
            ? "{$labels}anull[mix]"
            : $labels.'amix=inputs='.count($clips).':normalize=0:dropout_transition=0[mix]';
        $filters[] = '[mix]'.$this->finalChain((bool) ($options['normalize'] ?? true)).'[out]';

        $this->run(array_column($clips, 'path'), $filters, $output, $options);
    }

    /**
     * Mix background music and sound effects under the narration.
     * The narration is never altered in timing; the output has the narration's length.
     *
     * @param  array{path:string,volume_db:float,duck:bool,fade_in:float,fade_out:float}|null  $music
     * @param  list<array{path:string,at_ms:int,volume_db:float}>  $sfx
     */
    public function mix(string $voice, ?array $music, array $sfx, string $output, float $voiceDuration, array $options = []): void
    {
        if (! $this->isAvailable()) {
            throw new AudioProcessingException('Background music and sound effects need FFmpeg, which is not installed. Remove the music/effects, or install FFmpeg.');
        }

        $cmd = $this->buildMixCommand($voice, $music, $sfx, $output, $voiceDuration, $options);

        foreach (array_filter([$voice, $music['path'] ?? null, ...array_column($sfx, 'path')]) as $input) {
            if (! is_file($input)) {
                throw new AudioProcessingException('Missing audio file: '.basename($input));
            }
        }
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }

        $result = Process::timeout((int) config('soundex.ffmpeg.timeout'))->run($cmd);
        if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
            throw new AudioProcessingException('FFmpeg mixing failed: '.$this->tail($result->errorOutput()));
        }
    }

    /** @return list<string> */
    public function buildMixCommand(string $voice, ?array $music, array $sfx, string $output, float $voiceDuration, array $options = []): array
    {
        $fmt = 'aresample='.self::SAMPLE_RATE.',aformat=sample_fmts=fltp:channel_layouts=stereo';
        $duration = max(0.1, round($voiceDuration, 3));

        $cmd = [$this->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y', '-i', $voice];
        $filters = [];
        $mixInputs = [];
        $index = 1;

        $duck = $music !== null && ($music['duck'] ?? true);
        $filters[] = "[0:a]{$fmt}".($duck ? ',asplit=2[voice][vkey]' : '[voice]');
        $mixInputs[] = '[voice]';

        if ($music !== null) {
            // Loop the music for as long as the narration lasts.
            array_push($cmd, '-stream_loop', '-1', '-i', $music['path']);
            $fadeIn = max(0, (float) ($music['fade_in'] ?? 2));
            $fadeOut = min(max(0, (float) ($music['fade_out'] ?? 3)), $duration);
            $chain = "[{$index}:a]{$fmt},atrim=0:{$duration},asetpts=PTS-STARTPTS"
                .',volume='.$this->db((float) ($music['volume_db'] ?? -18)).'dB'
                .($fadeIn > 0 ? ",afade=t=in:st=0:d={$fadeIn}" : '')
                .($fadeOut > 0 ? ',afade=t=out:st='.round($duration - $fadeOut, 3).":d={$fadeOut}" : '');
            if ($duck) {
                $filters[] = $chain.'[mraw]';
                // Lower the music automatically while the voice is speaking.
                $filters[] = '[mraw][vkey]sidechaincompress=threshold=0.02:ratio=10:attack=15:release=350[music]';
            } else {
                $filters[] = $chain.'[music]';
            }
            $mixInputs[] = '[music]';
            $index++;
        }

        foreach ($sfx as $i => $effect) {
            array_push($cmd, '-i', $effect['path']);
            $delay = max(0, (int) $effect['at_ms']);
            $filters[] = "[{$index}:a]{$fmt},volume=".$this->db((float) ($effect['volume_db'] ?? 0))."dB,adelay=delays={$delay}:all=1[sfx{$i}]";
            $mixInputs[] = "[sfx{$i}]";
            $index++;
        }

        $filters[] = implode('', $mixInputs).'amix=inputs='.count($mixInputs).':normalize=0:duration=first:dropout_transition=0,alimiter=limit=0.95[out]';

        array_push($cmd, '-filter_complex', implode(';', $filters), '-map', '[out]', '-ar', (string) self::SAMPLE_RATE, '-ac', '2');
        if (($options['format'] ?? 'mp3') === 'wav') {
            array_push($cmd, '-c:a', 'pcm_s16le');
        } else {
            array_push($cmd, '-c:a', 'libmp3lame', '-b:a', ((int) (($options['bitrate'] ?? 128000) / 1000)).'k');
        }
        $cmd[] = $output;

        return $cmd;
    }

    /** True when ffprobe finds an audio stream in the file. */
    public function isAudioFile(string $path): bool
    {
        if (! $this->isAvailable()) {
            return $this->native()->isAudioFile($path);
        }

        $result = Process::timeout(60)->run([
            $this->ffprobe(), '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=codec_type',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);

        return $result->successful() && trim($result->output()) === 'audio';
    }

    private function db(float $value): string
    {
        return (string) round(max(-60, min(12, $value)), 1);
    }

    /** Build the ffmpeg argument list (public for testing). */
    public function buildCommand(array $inputs, array $filters, string $output, array $options): array
    {
        $cmd = [$this->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y'];
        foreach ($inputs as $input) {
            array_push($cmd, '-i', $input);
        }

        $script = implode(';', $filters);
        array_push($cmd, '-filter_complex', $script, '-map', '[out]', '-ar', (string) self::SAMPLE_RATE, '-ac', '1');

        if (($options['format'] ?? 'mp3') === 'wav') {
            array_push($cmd, '-c:a', 'pcm_s16le');
        } else {
            array_push($cmd, '-c:a', 'libmp3lame', '-b:a', ((int) (($options['bitrate'] ?? 128000) / 1000)).'k');
        }

        $cmd[] = $output;

        return $cmd;
    }

    private function run(array $inputs, array $filters, string $output, array $options): void
    {
        foreach ($inputs as $input) {
            if (! is_file($input)) {
                throw new AudioProcessingException('Missing audio file: '.basename($input));
            }
        }

        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }

        $result = Process::timeout((int) config('soundex.ffmpeg.timeout'))
            ->run($this->buildCommand($inputs, $filters, $output, $options));

        if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
            throw new AudioProcessingException('FFmpeg failed: '.$this->tail($result->errorOutput()));
        }
    }

    private function prepareChain(bool $trimSilence): string
    {
        $chain = 'aresample='.self::SAMPLE_RATE.',aformat=sample_fmts=fltp:channel_layouts=mono';

        if ($trimSilence) {
            // Trim leading and trailing silence quieter than -50 dB, keeping ~80 ms of natural tail.
            $trim = 'silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.08';
            $chain .= ",{$trim},areverse,{$trim},areverse";
        }

        return $chain;
    }

    private function finalChain(bool $normalize): string
    {
        // EBU R128 single-pass loudness normalisation to -16 LUFS (spoken word).
        return $normalize
            ? 'loudnorm=I=-16:TP=-1.5:LRA=11,aresample='.self::SAMPLE_RATE
            : 'anull';
    }

    private function tail(string $text): string
    {
        $text = trim($text);

        return $text === '' ? 'no error output' : mb_substr($text, -500);
    }
}
