// Mirrors App\Services\Text\SpeakerParser (labels only at the start of a line).
const BRACKET = /^\s*\[([^\]\n]{1,40})\]\s*/u;
const COLON = /^\s*([\p{L}\p{M}][\p{L}\p{M}\p{N}'’.\-]*(?: [\p{L}\p{M}\p{N}'’.\-]+){0,2})\s*:\s+/u;

export function speakerKey(label) {
    return label.trim().replace(/\s+/gu, ' ').toLowerCase();
}

export function detectLine(line) {
    for (const re of [BRACKET, COLON]) {
        const m = line.match(re);
        if (m && m[1].trim() && line.slice(m[0].length).trim()) {
            return { key: speakerKey(m[1]), label: m[1].trim() };
        }
    }
    return null;
}

/** @returns {{key:string,label:string,lines:number}[]} */
export function detectSpeakers(text) {
    const found = new Map();
    for (const line of (text || '').split(/\r?\n/)) {
        const hit = detectLine(line);
        if (!hit) continue;
        if (!found.has(hit.key)) found.set(hit.key, { ...hit, lines: 0 });
        found.get(hit.key).lines++;
    }
    return [...found.values()];
}

/** Case-insensitive whole-term match (mirrors PronunciationService). */
export function termAppears(term, text) {
    const esc = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp(`(?<![\\p{L}\\p{M}\\p{N}])${esc}(?![\\p{L}\\p{M}\\p{N}])`, 'iu').test(text || '');
}

/**
 * Shared state + helpers for character voices, pronunciation, folder and music
 * options. Mixed into the single wizard and the batch page.
 */
export function voiceExtras(config) {
    return {
        speakerMap: {}, // key -> voice_profile_id ('' = not a character, read as written)
        stripLabels: true,
        usePronunciations: true,
        folderId: config.defaultFolderId ?? '',
        newFolderName: '',
        musicAssetId: '',
        musicVolumeDb: -18,
        musicDuck: true,
        pronunciationTerms: config.pronunciationTerms ?? [],

        syncSpeakerMap(speakers) {
            const next = {};
            for (const sp of speakers) next[sp.key] = this.speakerMap[sp.key] ?? '';
            this.speakerMap = next;
        },
        speakerPayload(speakers) {
            return speakers.map((sp) => ({ key: sp.key, label: sp.label, voice_profile_id: this.speakerMap[sp.key] ? Number(this.speakerMap[sp.key]) : null }));
        },
        mappedVoices() {
            return Object.values(this.speakerMap).filter(Boolean).map((id) => this.voices.find((v) => v.id === Number(id))).filter(Boolean);
        },
        anyProfessional(baseVoice) {
            return [baseVoice, ...this.mappedVoices()].some((v) => v && v.mode === 'professional');
        },
        matchingTerms(text) {
            return this.pronunciationTerms.filter((t) => termAppears(t, text));
        },
        extrasPayload(speakers) {
            return {
                speakers: this.speakerPayload(speakers),
                strip_speaker_labels: this.stripLabels,
                use_pronunciations: this.usePronunciations,
                folder_id: this.folderId ? Number(this.folderId) : null,
                music_asset_id: this.musicAssetId ? Number(this.musicAssetId) : null,
                music_volume_db: Number(this.musicVolumeDb),
                music_duck: this.musicDuck,
            };
        },
    };
}
