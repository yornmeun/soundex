import { api, formatDuration } from './helpers';
import { detectSpeakers, voiceExtras } from './speakers';

/** Five-step "New Generation" wizard. */
export function generationWizard(config) {
    return {
        ...voiceExtras(config),
        step: 1,
        steps: ['Upload SRT', 'Review script', 'Configure voice', 'Generate', 'Preview & download'],

        // Step 1
        dragging: false,
        uploading: false,
        uploadError: null,
        upload: null, // parsed upload summary from the server

        // Step 2
        script: '',
        originalScript: '',
        mode: 'continuous',
        segmentStrategy: 'sentence',
        segments: [],
        segmentsLoading: false,

        // Step 3
        voices: config.voices,
        form: {
            project_name: '',
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
        chunkMaxChars: Number(config.defaults['generation.chunk_max_chars'] ?? 3000),
        submitting: false,
        submitError: null,

        // Step 4/5
        generation: null,

        get selectedVoice() { return this.voices.find((v) => v.id === Number(this.form.voice_profile_id)) || null; },
        get isProfessional() { return this.selectedVoice?.mode === 'professional'; },
        get scriptModified() { return this.script !== this.originalScript; },
        get wordCount() { return (this.script.trim().match(/[^\s​]+/g) || []).length; },
        get charCount() { return [...this.script].length; },
        get containsKhmer() { return /[ក-៿]/.test(this.script); },
        get estimatedSeconds() {
            if (this.containsKhmer) return Math.ceil(this.script.replace(/\s+/g, '').length / 12);
            return Math.ceil((this.wordCount / 150) * 60);
        },
        get estimatedChunks() {
            if (this.mode === 'segments') return this.segments.length;
            return Math.max(1, Math.ceil(this.charCount / this.chunkMaxChars));
        },
        /** Why "Generate narration" is disabled (null = ready). Shown next to the button. */
        get blockReason() {
            if (!config.ffmpegAvailable) return 'Generate is disabled because FFmpeg is not installed on this computer (needed to merge the audio). Run "sudo apt install ffmpeg", then reload this page.';
            if (!this.form.project_name.trim()) return 'Enter a project name to continue.';
            if (!this.selectedVoice) return 'Choose a voice to continue.';
            if (!this.selectedVoice.active) return `The voice “${this.selectedVoice.name}” is not active in HeyGen.`;
            if (this.turboWithProfessional) return 'Professional voice clones only work with the "HeyGen Voice 1" model.';
            return null;
        },
        get turboWithProfessional() { return this.anyProfessional(this.selectedVoice) && this.form.model !== 'heygen-voice-1'; },
        get speakerSource() { return this.mode === 'segments' ? this.segments.map((s) => s.text).join('\n') : this.script; },
        get speakers() { return detectSpeakers(this.speakerSource); },
        get matchedTerms() { return this.matchingTerms(this.speakerSource); },
        get pronunciationSupported() {
            return this.form.model !== 'heygen-voice-1-turbo-free' && this.selectedVoice?.mode !== 'professional';
        },

        /** Download the edited script as an SRT on the original timings (no generation needed). */
        async exportCorrectedSrt() {
            if (!this.upload) return;
            const res = await fetch(config.exportUrl.replace('__ID__', this.upload.id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ script: this.script }),
                credentials: 'same-origin',
            });
            if (!res.ok) { Alpine.store('toasts').push('Could not export the SRT.', 'error'); return; }
            const blob = await res.blob();
            const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: this.upload.filename.replace(/\.srt$/i, '') + '-corrected.srt' });
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        },
        fmt(s) { return formatDuration(s); },

        // ---- Step 1: upload
        onDrop(e) {
            this.dragging = false;
            const file = e.dataTransfer?.files?.[0];
            if (file) this.uploadFile(file);
        },
        onPick(e) {
            const file = e.target.files?.[0];
            if (file) this.uploadFile(file);
            e.target.value = '';
        },
        async uploadFile(file) {
            this.uploadError = null;
            if (!/\.srt$/i.test(file.name)) {
                this.uploadError = 'Only .srt subtitle files are accepted.';
                return;
            }
            if (file.size > config.maxUploadKb * 1024) {
                this.uploadError = `The file is too large (maximum ${config.maxUploadKb} KB).`;
                return;
            }
            const fd = new FormData();
            fd.append('file', file);
            this.uploading = true;
            try {
                this.upload = await api(config.uploadUrl, { method: 'POST', body: fd });
                this.script = this.upload.script;
                this.originalScript = this.upload.script;
                this.form.project_name = this.upload.filename.replace(/\.srt$/i, '');
                this.segments = [];
                if (this.upload.contains_khmer) this.form.language = 'auto';
                this.step = 2;
            } catch (e) {
                this.uploadError = e.message;
            } finally {
                this.uploading = false;
            }
        },

        // ---- Step 2: review
        resetScript() { this.script = this.originalScript; },
        async loadSegments() {
            if (!this.upload) return;
            this.segmentsLoading = true;
            try {
                const url = config.segmentsUrl.replace('__ID__', this.upload.id) + `?strategy=${this.segmentStrategy}`;
                const res = await api(url);
                this.segments = res.segments.map((s) => ({ ...s, original: s.text }));
            } catch (e) {
                Alpine.store('toasts').push(e.message, 'error');
            } finally {
                this.segmentsLoading = false;
            }
        },
        async setMode(mode) {
            this.mode = mode;
            if (mode === 'segments' && !this.segments.length) await this.loadSegments();
        },
        timecode(ms) {
            const t = Math.max(0, ms);
            const h = Math.floor(t / 3600000), m = Math.floor((t % 3600000) / 60000), s = Math.floor((t % 60000) / 1000), r = t % 1000;
            return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')},${String(r).padStart(3, '0')}`;
        },
        canContinueReview() {
            return this.mode === 'continuous' ? this.script.trim().length > 0 : this.segments.some((s) => s.text.trim());
        },

        // ---- Step 3/4: generate
        async generate() {
            this.submitError = null;
            if (!this.selectedVoice) { this.submitError = 'Please choose a voice.'; return; }
            if (!this.selectedVoice.active) { this.submitError = `The voice “${this.selectedVoice.name}” is not active in HeyGen.`; return; }
            this.submitting = true;
            try {
                const body = {
                    ...this.form,
                    uploaded_file_id: this.upload?.id ?? null,
                    mode: this.mode,
                    script: this.mode === 'continuous' ? this.script : null,
                    segment_strategy: this.mode === 'segments' ? this.segmentStrategy : null,
                    segment_texts: this.mode === 'segments' ? this.segments.map((s) => s.text) : null,
                    speed: this.anyProfessional(this.selectedVoice) ? this.form.speed : null,
                    mp3_bitrate: this.form.output_format === 'mp3' ? this.form.mp3_bitrate : null,
                    ...this.extrasPayload(this.speakers),
                };
                this.generation = await api(config.storeUrl, { method: 'POST', body });
                this.step = 4;
            } catch (e) {
                this.submitError = e.message;
            } finally {
                this.submitting = false;
            }
        },

        onGenerationUpdate(s) {
            if (s.status === 'completed' && this.step === 4) {
                this.step = 5;
                Alpine.store('toasts').push('Your narration is ready!', 'success');
            }
        },

        startOver() {
            Object.assign(this, { step: 1, upload: null, script: '', originalScript: '', segments: [], generation: null, mode: 'continuous' });
        },
    };
}
