<?php

namespace App\Services\Audio;

use App\Exceptions\AudioProcessingException;
use App\Models\AudioAsset;
use App\Models\Generation;
use App\Services\Generation\GenerationService;
use Illuminate\Support\Facades\Storage;

/**
 * Applies a generation's background music / sound effects to its voice-only audio.
 * Never calls HeyGen: remixing is free.
 */
class NarrationMixer
{
    public function __construct(private readonly AudioProcessor $audio) {}

    /** Produce the final audio and update audio_path / duration. */
    public function apply(Generation $generation): void
    {
        $disk = Storage::disk(config('soundex.storage_disk'));
        $voicePath = $generation->voiceAudioPath();

        if (! $voicePath || ! $disk->exists($voicePath)) {
            throw new AudioProcessingException('The voice audio for this generation is missing.');
        }

        if (! $generation->hasMix()) {
            $generation->update(['audio_path' => $voicePath, 'voice_audio_path' => $voicePath, 'duration_seconds' => $this->audio->probeDuration($disk->path($voicePath))]);

            return;
        }

        $settings = $generation->mix_settings;
        $assets = AudioAsset::query()->where('user_id', $generation->user_id)->get()->keyBy('id');
        $resolve = function ($id) use ($assets, $disk): string {
            $asset = $assets->get((int) $id);
            if (! $asset || ! $disk->exists($asset->path)) {
                throw new AudioProcessingException('A sound used in the mix was deleted from the Sound Library. Remove it from the mix and try again.');
            }

            return $disk->path($asset->path);
        };

        $voiceDuration = $this->audio->probeDuration($disk->path($voicePath));

        $music = null;
        if (! empty($settings['music']['asset_id'])) {
            $music = [
                'path' => $resolve($settings['music']['asset_id']),
                'volume_db' => (float) ($settings['music']['volume_db'] ?? -18),
                'duck' => (bool) ($settings['music']['duck'] ?? true),
                'fade_in' => (float) ($settings['music']['fade_in'] ?? 2),
                'fade_out' => (float) ($settings['music']['fade_out'] ?? 3),
            ];
        }

        $sfx = [];
        foreach ((array) ($settings['sfx'] ?? []) as $effect) {
            if ((int) $effect['at_ms'] >= $voiceDuration * 1000) {
                continue; // beyond the end of the narration
            }
            $sfx[] = ['path' => $resolve($effect['asset_id']), 'at_ms' => (int) $effect['at_ms'], 'volume_db' => (float) ($effect['volume_db'] ?? 0)];
        }

        $final = GenerationService::directory($generation).'/final.'.$generation->output_format;
        $this->audio->mix($disk->path($voicePath), $music, $sfx, $disk->path($final), $voiceDuration, [
            'format' => $generation->output_format,
            'bitrate' => $generation->mp3_bitrate ?? 128000,
        ]);

        $generation->update([
            'voice_audio_path' => $voicePath,
            'audio_path' => $final,
            'duration_seconds' => $this->audio->probeDuration($disk->path($final)),
        ]);
    }
}
