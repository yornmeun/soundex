import { api } from './helpers';

/** Polls a generation's status endpoint while it is pending/processing. */
export function generationStatus(statusUrl, initial = null) {
    return {
        statusUrl,
        s: initial,
        timer: null,
        error: null,

        init() {
            this.poll();
        },

        destroy() { clearTimeout(this.timer); },

        get active() { return !this.s || ['pending', 'processing'].includes(this.s.status); },

        async poll() {
            try {
                this.s = await api(this.statusUrl);
                this.error = null;
                this.$dispatch('generation-updated', this.s);
            } catch (e) {
                this.error = 'Lost connection to the server. Retrying…';
            }
            if (this.active) this.timer = setTimeout(() => this.poll(), 2000);
        },

        stageState(value) {
            if (!this.s) return 'todo';
            const order = this.s.stages.map((st) => st.value);
            const current = this.s.status === 'completed' ? order.length : order.indexOf(this.s.stage);
            const idx = order.indexOf(value);
            if (this.s.status === 'completed' || idx < current) return 'done';
            if (idx === current) return ['failed', 'cancelled'].includes(this.s.status) ? 'error' : 'current';
            return 'todo';
        },

        async cancel(url) {
            try {
                const res = await api(url, { method: 'POST' });
                Alpine.store('toasts').push(res.message, 'success');
                this.poll();
            } catch (e) {
                Alpine.store('toasts').push(e.message, 'error');
            }
        },
    };
}
