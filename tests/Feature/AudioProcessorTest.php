<?php

namespace Tests\Feature;

use App\Exceptions\AudioProcessingException;
use App\Services\Audio\AudioProcessor;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AudioProcessorTest extends TestCase
{
    public function test_merge_command_concatenates_in_order_with_silence_and_loudness_normalisation(): void
    {
        $cmd = (new AudioProcessor)->buildCommand(['/a/0.mp3', '/a/1.mp3'], ['X'], '/out.mp3', ['bitrate' => 192000]);

        $this->assertSame(['-i', '/a/0.mp3', '-i', '/a/1.mp3'], array_slice($cmd, 5, 4));
        $this->assertContains('libmp3lame', $cmd);
        $this->assertContains('192k', $cmd);
        $this->assertSame('/out.mp3', end($cmd));
    }

    public function test_merge_builds_filter_graph_without_time_stretching(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'boom', exitCode: 1));
        $dir = sys_get_temp_dir().'/kk-'.uniqid();
        mkdir($dir);
        touch("$dir/0.mp3");
        touch("$dir/1.mp3");

        try {
            (new AudioProcessor)->merge(["$dir/0.mp3", "$dir/1.mp3"], "$dir/out.mp3", ['silence_ms' => 400, 'normalize' => true]);
        } catch (AudioProcessingException) {
        }

        Process::assertRan(function ($process) {
            $graph = $process->command[array_search('-filter_complex', $process->command) + 1];

            return str_contains($graph, '[a0][s1][a1]concat=n=3:v=0:a=1')
                && str_contains($graph, 'atrim=0:0.4')
                && str_contains($graph, 'loudnorm')
                && ! str_contains($graph, 'atempo')
                && ! str_contains($graph, 'rubberband');
        });
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function test_real_ffmpeg_merge_produces_an_mp3_with_the_expected_duration(): void
    {
        $audio = new AudioProcessor;
        if (! $audio->isAvailable()) {
            $this->markTestSkipped('FFmpeg is not installed on this machine.');
        }

        $dir = sys_get_temp_dir().'/kk-'.uniqid();
        mkdir($dir);
        foreach ([0, 1] as $i) {
            exec(sprintf('%s -hide_banner -loglevel error -f lavfi -i "sine=frequency=%d:duration=1" -ac 1 -c:a libmp3lame %s', escapeshellarg($audio->ffmpeg()), 440 + $i * 220, escapeshellarg("$dir/$i.mp3")));
        }

        $audio->merge(["$dir/0.mp3", "$dir/1.mp3"], "$dir/out.mp3", ['silence_ms' => 500, 'normalize' => true]);
        $duration = $audio->probeDuration("$dir/out.mp3");

        $this->assertEqualsWithDelta(2.5, $duration, 0.2);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function test_real_ffmpeg_timeline_places_clips_at_their_start_times(): void
    {
        $audio = new AudioProcessor;
        if (! $audio->isAvailable()) {
            $this->markTestSkipped('FFmpeg is not installed on this machine.');
        }

        $dir = sys_get_temp_dir().'/kk-'.uniqid();
        mkdir($dir);
        foreach ([0, 1] as $i) {
            exec(sprintf('%s -hide_banner -loglevel error -f lavfi -i "sine=frequency=440:duration=1" -ac 1 -c:a libmp3lame %s', escapeshellarg($audio->ffmpeg()), escapeshellarg("$dir/$i.mp3")));
        }

        // Second clip starts at 3 s and lasts ~1 s, so the timeline is ~4 s long; nothing is stretched.
        $audio->timeline([['path' => "$dir/0.mp3", 'start_ms' => 0], ['path' => "$dir/1.mp3", 'start_ms' => 3000]], "$dir/timeline.mp3", ['normalize' => false]);

        $this->assertEqualsWithDelta(4.0, $audio->probeDuration("$dir/timeline.mp3"), 0.2);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
