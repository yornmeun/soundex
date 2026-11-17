<?php

namespace Tests\Feature;

use App\Exceptions\AudioProcessingException;
use App\Services\Audio\AudioProcessor;
use App\Services\Audio\NativeAudio;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Basic mode: audio handling without FFmpeg. */
class NativeAudioTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/kk-native-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    /** A valid MPEG-1 Layer III mono 44.1 kHz 128 kbps file of silent frames, with ID3 + Info header frame. */
    private function mp3(string $name, int $frames): string
    {
        $length = intdiv(144000 * 128, 44100); // 417 bytes, no padding
        $header = "\xFF\xFB\x90\xC4"; // MPEG1 L3, no CRC, 128 kbps, 44.1 kHz, mono
        $frame = $header.str_repeat("\0", $length - 4);
        $info = $header.str_repeat("\0", 17).'Info'.str_repeat("\0", $length - 25);
        $id3 = 'ID3'."\x03\x00\x00".pack('N', 0); // empty tag (synchsafe size 0)

        file_put_contents($path = "{$this->dir}/{$name}", $id3.$info.str_repeat($frame, $frames));

        return $path;
    }

    private function wav(string $name, float $seconds, float $amplitude = 0.1, float $leadingSilence = 0): string
    {
        $rate = 44100;
        $samples = [];
        for ($i = 0; $i < (int) ($leadingSilence * $rate); $i++) {
            $samples[] = 0;
        }
        for ($i = 0; $i < (int) ($seconds * $rate); $i++) {
            $samples[] = (int) round(32767 * $amplitude * sin(2 * M_PI * 440 * $i / $rate));
        }
        $data = pack('s*', ...$samples);
        $header = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data));
        file_put_contents($path = "{$this->dir}/{$name}", $header.$data);

        return $path;
    }

    public function test_mp3_duration_ignores_tags_and_the_info_frame(): void
    {
        $path = $this->mp3('a.mp3', 100);

        $this->assertEqualsWithDelta(100 * 1152 / 44100, (new NativeAudio)->probeDuration($path), 0.001);
        $this->assertTrue((new NativeAudio)->isAudioFile($path));
    }

    public function test_mp3_merge_joins_clips_with_silence_in_order(): void
    {
        $native = new NativeAudio;
        $native->merge([$this->mp3('0.mp3', 50), $this->mp3('1.mp3', 80)], $out = "{$this->dir}/out.mp3", ['silence_ms' => 500, 'format' => 'mp3']);

        $expected = (130 * 1152 + round(0.5 * 44100 / 1152) * 1152) / 44100;
        $this->assertEqualsWithDelta($expected, $native->probeDuration($out), 0.001);
        $this->assertStringNotContainsString('Info', file_get_contents($out), 'VBR/Info header frames must be dropped.');
        $this->assertRealFfprobeAgrees($out, $expected);
    }

    public function test_mp3_timeline_inserts_gaps_at_start_times(): void
    {
        $native = new NativeAudio;
        $native->timeline([
            ['path' => $this->mp3('1.mp3', 40), 'start_ms' => 3000],
            ['path' => $this->mp3('0.mp3', 40), 'start_ms' => 0],
        ], $out = "{$this->dir}/timeline.mp3", ['format' => 'mp3']);

        // Second clip starts at ~3 s and lasts 40 frames.
        $this->assertEqualsWithDelta(3.0 + 40 * 1152 / 44100, $native->probeDuration($out), 0.03);
    }

    public function test_wav_merge_trims_and_normalises(): void
    {
        $native = new NativeAudio;
        $native->merge(
            [$this->wav('0.wav', 1.0, 0.05, leadingSilence: 1.0), $this->wav('1.wav', 1.0, 0.05)],
            $out = "{$this->dir}/out.wav",
            ['silence_ms' => 250, 'trim_silence' => true, 'normalize' => true, 'format' => 'wav'],
        );

        // 1 s of leading silence trimmed (keeping 80 ms) + 2 × 1 s of tone + 0.25 s gap.
        $this->assertEqualsWithDelta(2.33, $native->probeDuration($out), 0.02);

        $samples = unpack('s*', substr(file_get_contents($out), 44));
        $peak = max(array_map('abs', $samples));
        $this->assertGreaterThan(32767 * 0.05 * 2, $peak, 'Quiet speech is made louder.');
        $this->assertLessThanOrEqual(32767 * 10 ** (-1.5 / 20) + 1, $peak, 'Peaks stay below -1.5 dBFS.');
    }

    public function test_format_conversion_and_mixing_need_ffmpeg(): void
    {
        $native = new NativeAudio;
        $this->expectException(AudioProcessingException::class);
        $native->merge([$this->mp3('0.mp3', 10)], "{$this->dir}/out.wav", ['format' => 'wav']);
    }

    public function test_audio_processor_falls_back_to_basic_mode_without_ffmpeg(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'not found', exitCode: 1));
        $audio = new AudioProcessor;

        $this->assertFalse($audio->isAvailable());
        $this->assertFalse($audio->canMix());

        $audio->merge([$this->mp3('0.mp3', 20), $this->mp3('1.mp3', 20)], $out = "{$this->dir}/out.mp3", ['format' => 'mp3']);
        $this->assertEqualsWithDelta(40 * 1152 / 44100, $audio->probeDuration($out), 0.001);

        file_put_contents("{$this->dir}/text.mp3", 'hello');
        $this->assertFalse($audio->isAudioFile("{$this->dir}/text.mp3"));

        $this->expectException(AudioProcessingException::class);
        $audio->mix($out, null, [], "{$this->dir}/mixed.mp3", 1.0);
    }

    private function assertRealFfprobeAgrees(string $path, float $expected): void
    {
        $ffprobe = (new AudioProcessor)->ffprobe();
        exec(sprintf('%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1', escapeshellarg($ffprobe), escapeshellarg($path)), $out, $code);
        if ($code !== 0) {
            return; // FFmpeg not installed: nothing to cross-check
        }
        $this->assertEqualsWithDelta($expected, (float) trim($out[0] ?? '0'), 0.05, 'The joined MP3 must be readable by real decoders.');
    }
}
