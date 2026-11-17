import { formatDuration } from './helpers';

const MAX_WAVEFORM_BYTES = 25 * 1024 * 1024;
const COLOR_PLAYED = '#7c3aed';
const COLOR_REMAINING = '#cbd5e1';

/**
 * Audio player with a lightweight waveform (decoded client-side, skipped for very large files).
 *
 * Options:
 *  - lazy:     don't download the audio until the first play (for lists with many players)
 *  - duration: known length in seconds, shown before the audio has loaded
 */
export function waveformPlayer(src, options = {}) {
    return {
        src,
        lazy: !!options.lazy,
        playing: false,
        current: 0,
        duration: Number(options.duration) > 0 ? Number(options.duration) : 0,
        exactDuration: false,
        peaks: [],
        waveRequested: false,
        audio: null,
        frame: null,

        init() {
            this.audio = this.$refs.audio;

            // Metadata may already be loaded (cached) before Alpine starts, so read it now
            // too; durationchange also covers MP3s whose length is only known later.
            const syncDuration = () => this.setDuration(this.audio.duration);
            ['loadedmetadata', 'durationchange', 'canplay'].forEach((e) => this.audio.addEventListener(e, syncDuration));
            if (this.audio.readyState >= 1) syncDuration();

            // timeupdate only fires ~4x/second; it keeps a paused/seeked player in sync,
            // while the animation loop below handles smooth movement during playback.
            this.audio.addEventListener('timeupdate', () => { if (!this.playing) this.tick(); });
            this.audio.addEventListener('seeked', () => this.tick());
            this.audio.addEventListener('play', () => { this.playing = true; this.loop(); });
            this.audio.addEventListener('pause', () => { this.playing = false; this.tick(); });
            this.audio.addEventListener('ended', () => { this.playing = false; this.tick(); });

            new ResizeObserver(() => this.draw()).observe(this.$refs.canvas);
            if (!this.lazy) this.loadWaveform();
            this.draw();
        },

        destroy() { cancelAnimationFrame(this.frame); },

        loop() {
            cancelAnimationFrame(this.frame);
            const step = () => {
                this.tick();
                if (this.playing) this.frame = requestAnimationFrame(step);
            };
            this.frame = requestAnimationFrame(step);
        },

        tick() {
            this.current = this.audio.currentTime || 0;
            this.draw();
        },

        async loadWaveform() {
            if (this.waveRequested || (!window.AudioContext && !window.webkitAudioContext)) return;
            this.waveRequested = true;
            try {
                const res = await fetch(this.src, { credentials: 'same-origin' });
                const len = Number(res.headers.get('content-length') || 0);
                if (!res.ok || len > MAX_WAVEFORM_BYTES) return;
                const buf = await res.arrayBuffer();
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const decoded = await ctx.decodeAudioData(buf);
                // Exact length from the decoded audio (works even when the <audio> reports Infinity).
                this.setDuration(decoded.duration, true);
                const data = decoded.getChannelData(0);
                const bars = 200;
                const size = Math.floor(data.length / bars) || 1;
                const peaks = [];
                for (let i = 0; i < bars; i++) {
                    let max = 0;
                    for (let j = i * size; j < Math.min((i + 1) * size, data.length); j += 16) max = Math.max(max, Math.abs(data[j]));
                    peaks.push(max);
                }
                const top = Math.max(...peaks, 0.01);
                this.peaks = peaks.map((p) => p / top);
                ctx.close();
            } catch (e) {
                this.peaks = [];
            } finally {
                this.draw();
            }
        },

        /** Placeholder shape shown until the real waveform is decoded. */
        placeholderPeaks(count) {
            return Array.from({ length: count }, (_, i) => 0.25 + 0.2 * Math.abs(Math.sin(i * 0.9) * Math.cos(i * 0.37)));
        },

        draw() {
            const canvas = this.$refs.canvas;
            if (!canvas) return;
            const dpr = window.devicePixelRatio || 1;
            const w = canvas.clientWidth, h = canvas.clientHeight;
            if (!w || !h) return;
            if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) {
                canvas.width = Math.round(w * dpr);
                canvas.height = Math.round(h * dpr);
            }
            const g = canvas.getContext('2d');
            g.setTransform(dpr, 0, 0, dpr, 0, 0);
            g.clearRect(0, 0, w, h);

            // One bar per ~4px keeps compact players readable.
            const count = Math.max(24, Math.floor(w / 4));
            const source = this.peaks.length ? this.peaks : this.placeholderPeaks(count);
            const barW = w / count;
            const bars = (color) => {
                g.fillStyle = color;
                for (let i = 0; i < count; i++) {
                    const p = source[Math.floor((i / count) * source.length)];
                    const bh = Math.max(2, p * (h - 4));
                    g.fillRect(i * barW + 0.5, (h - bh) / 2, Math.max(1, barW - 1.5), bh);
                }
            };

            bars(COLOR_REMAINING);

            // Smooth progress: clip the "played" colour to the exact playhead position.
            const progress = this.duration ? Math.min(1, this.current / this.duration) : 0;
            if (progress > 0) {
                g.save();
                g.beginPath();
                g.rect(0, 0, w * progress, h);
                g.clip();
                bars(COLOR_PLAYED);
                g.restore();
            }
        },

        setDuration(value, authoritative = false) {
            if (!Number.isFinite(value) || value <= 0) return;
            if (this.exactDuration && !authoritative) return; // decoded length wins
            if (authoritative) this.exactDuration = true;
            this.duration = value;
            this.draw();
        },

        toggle() {
            if (this.audio.paused) {
                this.loadWaveform(); // no-op unless lazy and not loaded yet
                this.audio.play().catch(() => (this.playing = false));
            } else {
                this.audio.pause();
            }
        },

        seek(event) {
            if (!this.duration) return;
            const rect = this.$refs.canvas.getBoundingClientRect();
            const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
            this.audio.currentTime = ratio * this.duration;
            this.loadWaveform();
            this.tick();
        },

        seekKey(event) {
            if (!this.duration) return;
            if (event.key === 'ArrowRight') this.audio.currentTime = Math.min(this.duration, this.audio.currentTime + 5);
            else if (event.key === 'ArrowLeft') this.audio.currentTime = Math.max(0, this.audio.currentTime - 5);
            else if (event.key === ' ' || event.key === 'Enter') this.toggle();
            else return;
            event.preventDefault();
            this.tick();
        },

        get timeLabel() { return `${formatDuration(this.current)} / ${formatDuration(this.duration)}`; },
    };
}
