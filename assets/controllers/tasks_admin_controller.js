import { Controller } from '@hotwired/stimulus';
import { Translator } from '../translator.js';

export default class extends Controller {
    static targets = ['workerBadge', 'workerLabel'];
    static values = { locale: { type: String, default: 'en' } };

    async connect() {
        this.translator = await Translator.get(this.localeValue);
        this._pollInterval = setInterval(() => this._pollWorker(), 5000);
        this._pollWorker();
    }

    disconnect() {
        if (this._pollInterval) {
            clearInterval(this._pollInterval);
        }
    }

    async _pollWorker() {
        try {
            const response = await fetch('/api/worker/status');
            const data = await response.json();
            if (!this.hasWorkerBadgeTarget) return;
            this.workerBadgeTarget.classList.toggle('badge-success', data.alive);
            this.workerBadgeTarget.classList.toggle('badge-danger', !data.alive);
            const key = data.alive ? 'js.tasks_admin.worker_active' : 'js.tasks_admin.worker_inactive';
            this.workerLabelTarget.textContent = this.translator.trans(key);
        } catch {}
    }

    async restartWorker(event) {
        if (!confirm(this.translator.trans('js.tasks_admin.confirm_restart'))) return;
        event.target.disabled = true;
        try {
            await fetch('/api/worker/restart', { method: 'POST' });
        } finally {
            setTimeout(() => location.reload(), 2000);
        }
    }

    async deleteTask(event) {
        if (!confirm(this.translator.trans('js.tasks_admin.confirm_delete'))) return;
        await fetch(`/api/tasks/${event.currentTarget.dataset.id}/delete`, { method: 'POST' });
        location.reload();
    }

    async retryTask(event) {
        if (!confirm(this.translator.trans('js.tasks_admin.confirm_retry'))) return;
        await fetch(`/api/tasks/${event.currentTarget.dataset.id}/retry`, { method: 'POST' });
        location.reload();
    }

    async purgeTasks() {
        if (!confirm(this.translator.trans('js.tasks_admin.confirm_purge'))) return;
        await fetch('/api/tasks/purge', { method: 'POST' });
        location.reload();
    }
}
