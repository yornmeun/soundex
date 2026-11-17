<?php

namespace App\Services;

use App\Models\VoiceProfile;
use App\Services\HeyGen\HeyGenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the local voice_profiles table in sync with the HeyGen workspace.
 * This service never creates or clones voices.
 */
class VoiceService
{
    public function __construct(
        private readonly HeyGenService $heygen,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{total:int,active:int,default_voice_name:?string,auto_selected:bool,default_missing:bool}
     */
    public function sync(): array
    {
        $remote = $this->heygen->listPrivateVoices();
        $now = now();

        DB::transaction(function () use ($remote, $now) {
            $seen = [];
            foreach ($remote as $voice) {
                if (empty($voice['voice_id'])) {
                    continue;
                }
                $seen[] = $voice['voice_id'];
                $this->upsert($voice, $now);
            }

            VoiceProfile::query()->whereNotIn('voice_id', $seen)->update(['is_available' => false]);
        });

        $autoSelected = false;
        if (blank($this->settings->defaultVoiceId())) {
            // First-time setup only: pick the existing "kikitoons" voice if present.
            $kikitoons = $this->findByName((string) config('kikitoons.heygen.default_voice_name'));
            if ($kikitoons) {
                $this->setDefault($kikitoons);
                $autoSelected = true;
            }
        }

        $default = $this->defaultVoice();
        $this->markDefaultFlag();

        return [
            'total' => VoiceProfile::query()->where('is_available', true)->count(),
            'active' => VoiceProfile::query()->usable()->count(),
            'default_voice_name' => $default?->name,
            'auto_selected' => $autoSelected,
            'default_missing' => filled($this->settings->defaultVoiceId()) && (! $default || ! $default->isActive()),
        ];
    }

    /** Register a voice by ID after verifying it exists in the HeyGen workspace. */
    public function addById(string $voiceId): VoiceProfile
    {
        $voice = $this->heygen->getVoice($voiceId);
        $voice['voice_id'] ??= $voiceId;

        return $this->upsert($voice, now());
    }

    public function findByName(string $name): ?VoiceProfile
    {
        return VoiceProfile::query()
            ->usable()
            ->get()
            ->first(fn (VoiceProfile $v) => mb_strtolower(trim($v->name)) === mb_strtolower(trim($name)));
    }

    public function defaultVoice(): ?VoiceProfile
    {
        $id = $this->settings->defaultVoiceId();

        return $id ? VoiceProfile::query()->where('voice_id', $id)->first() : null;
    }

    public function setDefault(VoiceProfile $voice): void
    {
        $this->settings->setDefaultVoiceId($voice->voice_id);
        $this->markDefaultFlag();
    }

    private function markDefaultFlag(): void
    {
        $id = $this->settings->defaultVoiceId();
        VoiceProfile::query()->where('is_default', true)->where('voice_id', '!=', (string) $id)->update(['is_default' => false]);
        if ($id) {
            VoiceProfile::query()->where('voice_id', $id)->update(['is_default' => true]);
        }
    }

    private function upsert(array $voice, Carbon $now): VoiceProfile
    {
        return VoiceProfile::query()->updateOrCreate(
            ['voice_id' => $voice['voice_id']],
            [
                'name' => $voice['name'] ?? $voice['voice_id'],
                'mode' => $voice['mode'] ?? null,
                'language' => $voice['language'] ?? null,
                'gender' => $voice['gender'] ?? null,
                'status' => strtoupper((string) ($voice['status'] ?? 'ACTIVE')),
                'failure_reason' => $voice['failure_reason'] ?? null,
                'is_available' => true,
                'heygen_created_at' => isset($voice['created_at']) && is_numeric($voice['created_at'])
                    ? Carbon::createFromTimestamp((int) $voice['created_at']) : null,
                'last_synced_at' => $now,
            ],
        );
    }
}
