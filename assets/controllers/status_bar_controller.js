import { Controller } from '@hotwired/stimulus';
import { Translator } from '../translator.js';
import { csrfFetch } from '../csrf.js';

export default class extends Controller {
    static targets = ['panel', 'body', 'badge'];
    static values = { locale: { type: String, default: 'en' } };

    async connect() {
        this.translator = await Translator.get(this.localeValue);
        this._pollInterval = setInterval(() => this._poll(), 3000);
        this._poll();
    }

    disconnect() {
        if (this._pollInterval) {
            clearInterval(this._pollInterval);
        }
        clearTimeout(this._hideTimer);
    }

    showPanel() {
        clearTimeout(this._hideTimer);
        this._loadContent();
        this.panelTarget.classList.add('open');
    }

    hidePanelWithDelay() {
        this._hideTimer = setTimeout(() => {
            if (!this.element.matches(':hover') && !this.panelTarget.matches(':hover')) {
                this.panelTarget.classList.remove('open');
            }
        }, 400);
    }

    keepPanel() {
        clearTimeout(this._hideTimer);
    }

    togglePanel(event) {
        if (this.panelTarget.classList.contains('open')) {
            this.panelTarget.classList.remove('open');
        } else {
            this.showPanel();
        }
    }

    async _poll() {
        try {
            const response = await csrfFetch('/api/tasks/status');
            const data = await response.json();
            const pending = (data.pending || 0) + (data.processing || 0);

            if (this.hasBadgeTarget) {
                if (pending > 0) {
                    this.badgeTarget.textContent = pending;
                    this.badgeTarget.hidden = false;
                } else {
                    this.badgeTarget.hidden = true;
                }
            }
        } catch {}
    }

    async _loadContent() {
        if (this._loaded && this.panelTarget.classList.contains('open')) return;

        try {
            const response = await csrfFetch('/api/tasks/list');
            const tasks = await response.json();
            this._render(tasks);
            this._loaded = true;
        } catch {}
    }

    _render(tasks) {
        if (tasks.length === 0) {
            this.bodyTarget.innerHTML = `<div class="status-bar-panel-empty">${this.translator.trans('js.status_bar.no_tasks')}</div>`;
            return;
        }

        this.bodyTarget.innerHTML = tasks.map(t => {
            const knownStatus = ['pending', 'processing', 'completed', 'failed'].includes(t.status);
            const statusLabel = knownStatus ? this.translator.trans(`js.tasks.status.${t.status}`) : t.status;
            const statusClass = { pending: 'badge-neutral', processing: 'badge-warning', completed: 'badge-success', failed: 'badge-danger' }[t.status] || 'badge-neutral';
            const errorHtml = t.error ? `<code style="color: var(--danger); font-size: 0.65rem;">${this._escape(t.error)}</code>` : '';
            return `<div class="status-bar-panel-row">
                <span class="badge ${statusClass}">${statusLabel}</span>
                <code class="status-bar-panel-path">${this._escape(t.path)}</code>
                ${errorHtml}
                <span class="status-bar-panel-time">${this._escape(t.createdAt)}</span>
            </div>`;
        }).join('');
    }

    _escape(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
}
