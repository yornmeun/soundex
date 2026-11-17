<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use Illuminate\Support\Facades\Process;

/**
 * FFmpeg-based audio processing. Narration is never time-stretched or sped up.
 */
class AudioProcessor
{
    private const SAMPLE_RATE = 44100;

    public function ffmpeg(): string
    {
        return $this->resolveBinary((string) config('kikitoons.ffmpeg.ffmpeg_binary'));
    }

    public function ffprobe(): string
    {
        return $this->resolveBinary((string) config('kikitoons.ffmpeg.ffprobe_binary'));
    }

    /**
     * "ffmpeg" is looked up on PATH; "bin/ffmpeg" is relative to the project root;
     * "/usr/bin/ffmpeg" is used as is.
     */
    private function resolveBinary(string $binary): string
    {
        if ($binary !== '' && ! str_starts_with($binary, '/') && str_contains($binary, '/')) {
            return base_path($binary);
        }

        return $binary;
    }

    public function isAvailable(): bool
    {
        try {
            return Process::timeout(15)->run([$this->ffmpeg(), '-version'])->successful()
                && Process::timeout(15)->run([$this->ffprobe(), '-version'])->successful();
        } catch (\Throwable) {
            return false;
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

        $result = Process::timeout((int) config('kikitoons.ffmpeg.timeout'))
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
