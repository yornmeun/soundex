import { formatDuration } from './helpers';

/** Music + sound effect editor on the generation page. */
export function mixEditor(config) {
    const mix = config.mix || {};
    return {
        musicAssetId: mix.music?.asset_id ?? '',
        musicVolumeDb: mix.music?.volume_db ?? -18,
        musicDuck: mix.music?.duck ?? true,
        musicFadeIn: mix.music?.fade_in ?? 2,
        musicFadeOut: mix.music?.fade_out ?? 3,
        sfx: (mix.sfx || []).map((e, i) => ({ id: i + 1, asset_id: e.asset_id, at: msToTime(e.at_ms), volume_db: e.volume_db ?? 0 })),
        sfxAssets: config.sfxAssets,
        nextId: 1000,
        duration: config.duration || 0,

        addEffect(atSeconds = null) {
            if (!this.sfxAssets.length) return;
            const at = atSeconds ?? this.currentPlayerTime();
            this.sfx.push({ id: this.nextId++, asset_id: this.sfxAssets[0].id, at: msToTime(Math.round(at * 1000)), volume_db: 0 });
        },
        removeEffect(e) { this.sfx = this.sfx.filter((x) => x.id !== e.id); },
        currentPlayerTime() {
            const audio = document.querySelector('[data-main-player] audio');
            return audio ? audio.currentTime : 0;
        },
        invalid(e) {
            const ms = timeToMs(e.at);
            return ms === null || (this.duration && ms >= this.duration * 1000);
        },
        get hasErrors() { return this.sfx.some((e) => this.invalid(e)); },
        payload() {
            return {
                music_asset_id: this.musicAssetId ? Number(this.musicAssetId) : null,
                music_volume_db: Number(this.musicVolumeDb),
                music_duck: this.musicDuck,
                music_fade_in: Number(this.musicFadeIn),
                music_fade_out: Number(this.musicFadeOut),
                sfx: this.sfx.map((e) => ({ asset_id: Number(e.asset_id), at_ms: timeToMs(e.at), volume_db: Number(e.volume_db) })),
            };
        },
        fmt(s) { return formatDuration(s); },
    };
}

export function msToTime(ms) {
    const t = Math.max(0, Math.round(ms));
    const m = Math.floor(t / 60000), s = Math.floor((t % 60000) / 1000), r = t % 1000;
    return `${m}:${String(s).padStart(2, '0')}.${String(r).padStart(3, '0')}`;
}

/** Accepts "m:ss.mmm", "m:ss", "ss.mmm" or plain seconds. */
export function timeToMs(value) {
    const v = String(value ?? '').trim();
    const m = v.match(/^(?:(\d+):)?(\d{1,2}(?:\.\d{1,3})?|\d+(?:\.\d{1,3})?)$/);
    if (!m) return null;
    return Math.round(((Number(m[1] || 0) * 60) + Number(m[2])) * 1000);
}
