import Alpine from 'alpinejs';
import { generationWizard } from './wizard';
import { generationStatus } from './status';
import { waveformPlayer } from './player';
import { batchForm, batchStatus } from './batch';
import { mixEditor } from './mixer';
import { api, formatDuration } from './helpers';

window.api = api;
window.formatDuration = formatDuration;

document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],
        push(message, type = 'success', timeout = 6000) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type });
            if (timeout) setTimeout(() => this.remove(id), timeout);
        },
        remove(id) { this.items = this.items.filter((t) => t.id !== id); },
    });

    Alpine.store('confirm', {
        open: false, title: '', message: '', confirmLabel: 'Confirm', danger: false, onConfirm: null,
        ask({ title, message, confirmLabel = 'Confirm', danger = false }, onConfirm) {
            Object.assign(this, { open: true, title, message, confirmLabel, danger, onConfirm });
        },
        accept() { const cb = this.onConfirm; this.open = false; this.onConfirm = null; cb && cb(); },
        cancel() { this.open = false; this.onConfirm = null; },
    });

    // Global "only one audio plays at a time".
    document.addEventListener('play', (e) => {
        document.querySelectorAll('audio').forEach((a) => { if (a !== e.target) a.pause(); });
    }, true);

    Alpine.data('generationWizard', generationWizard);
    Alpine.data('generationStatus', generationStatus);
    Alpine.data('waveformPlayer', waveformPlayer);
    Alpine.data('batchForm', batchForm);
    Alpine.data('batchStatus', batchStatus);
    Alpine.data('mixEditor', mixEditor);
});

window.Alpine = Alpine;
Alpine.start();
