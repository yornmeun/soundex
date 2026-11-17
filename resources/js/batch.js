import { api, formatDuration } from './helpers';
import { detectSpeakers, voiceExtras } from './speakers';

/** Batch page: upload many SRT files, share one voice configuration, queue them all. */
export function batchForm(config) {
    return {
        ...voiceExtras(config),
        voices: config.voices,
        items: [], // {key, file, name, status: uploading|ready|error, upload, error}
        dragging: false,
        submitting: false,
        submitError: null,
        name: 'Batch ' + new Date().toLocaleDateString(),
        mode: 'continuous',
        segmentStrategy: 'sentence',
        form: {
            voice_profile_id: config.defaultVoiceProfileId,
            language: config.defaults['generation.language'] ?? 'en',
            model: config.defaults['generation.model'],
            output_format: config.defaults['generation.output_format'] ?? 'mp3',
            mp3_bitrate: config.defaults['generation.mp3_bitrate'] ?? 128000,
            speed: 1.0,
            silence_between_ms: config.defaults['generation.silence_between_ms'] ?? 350,
            normalize_loudness: !!config.defaults['generation.normalize_loudness'],
            trim_chunk_silence: !!config.defaults['generation.trim_chunk_silence'],
        },

        get ready() { return this.items.filter((i) => i.status === 'ready'); },
        get uploading() { return this.items.some((i) => i.status === 'uploading'); },
        get selectedVoice() { return this.voices.find((v) => v.id === Number(this.form.voice_profile_id)) || null; },
        get allScripts() { return this.ready.map((i) => i.upload.script).join('\n'); },
        get speakers() { return detectSpeakers(this.allScripts); },
        get matchedTerms() { return this.matchingTerms(this.allScripts); },
        get totalSeconds() { return this.ready.reduce((sum, i) => sum + (i.upload.estimated_seconds || 0), 0); },
        get turboWithProfessional() { return this.anyProfessional(this.selectedVoice) && this.form.model !== 'heygen-voice-1'; },
        get pronunciationSupported() { return this.form.model !== 'heygen-voice-1-turbo-free' && this.selectedVoice?.mode !== 'professional'; },
        get blockReason() {
            if (!config.ffmpegAvailable) return 'FFmpeg is not installed, so audio cannot be merged.';
            if (!this.ready.length) return 'Add at least one SRT file.';
            if (this.uploading) return 'Wait for all files to finish uploading.';
            if (this.ready.length > 50) return 'A batch can contain at most 50 files.';
            if (!this.name.trim()) return 'Give the batch a name.';
            if (!this.selectedVoice) return 'Choose a voice.';
            if (!this.selectedVoice.active) return `The voice “${this.selectedVoice.name}” is not active in HeyGen.`;
            if (this.turboWithProfessional) return 'Professional voice clones only work with the "HeyGen Voice 1" model.';
            if (this.ready.some((i) => !i.name.trim())) return 'Every file needs a project name.';
            return null;
        },
        fmt(s) { return formatDuration(s); },

        onDrop(e) { this.dragging = false; this.addFiles(e.dataTransfer?.files); },
        onPick(e) { this.addFiles(e.target.files); e.target.value = ''; },

        async addFiles(fileList) {
            const files = [...(fileList || [])];
            for (const file of files) {
                const item = { key: Date.now() + Math.random(), file, name: file.name.replace(/\.srt$/i, ''), status: 'uploading', upload: null, error: null };
                this.items.push(item);
                const ref = this.items[this.items.length - 1];
                if (!/\.srt$/i.test(file.name)) { ref.status = 'error'; ref.error = 'Not an .srt file'; continue; }
                if (file.size > config.maxUploadKb * 1024) { ref.status = 'error'; ref.error = `Larger than ${config.maxUploadKb} KB`; continue; }
                const fd = new FormData();
                fd.append('file', file);
                try {
                    ref.upload = await api(config.uploadUrl, { method: 'POST', body: fd });
                    ref.status = 'ready';
                } catch (e) {
                    ref.status = 'error';
                    ref.error = e.message;
                }
            }
        },
        remove(item) { this.items = this.items.filter((i) => i.key !== item.key); },

        async submit() {
            this.submitError = null;
            this.submitting = true;
            try {
                const res = await api(config.storeUrl, {
                    method: 'POST',
                    body: {
                        ...this.form,
                        name: this.name,
                        mode: this.mode,
                        segment_strategy: this.mode === 'segments' ? this.segmentStrategy : null,
                        speed: this.anyProfessional(this.selectedVoice) ? this.form.speed : null,
                        mp3_bitrate: this.form.output_format === 'mp3' ? this.form.mp3_bitrate : null,
                        items: this.ready.map((i) => ({ uploaded_file_id: i.upload.id, project_name: i.name })),
                        ...this.extrasPayload(this.speakers),
                    },
                });
                window.location = res.show_url;
            } catch (e) {
                this.submitError = e.message;
            } finally {
                this.submitting = false;
            }
        },
    };
}

/** Live status list for a batch. */
export function batchStatus(url, initial) {
    return {
        s: initial,
        timer: null,
        init() { this.poll(); },
        destroy() { clearTimeout(this.timer); },
        get active() { return (this.s?.items || []).some((i) => ['pending', 'processing'].includes(i.status)); },
        item(id) { return (this.s?.items || []).find((i) => i.id === id) || {}; },
        async poll() {
            try { this.s = await api(url); } catch { /* keep last state */ }
            if (this.active) this.timer = setTimeout(() => this.poll(), 3000);
        },
    };
}
