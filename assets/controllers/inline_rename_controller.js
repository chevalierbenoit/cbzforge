import { Controller } from '@hotwired/stimulus';
import { Translator } from '../translator.js';
import { csrfFetch } from '../csrf.js';

export default class extends Controller {
    static values = {
        libraryId: Number,
        locale: String,
    };

    async connect() {
        this.translator = await Translator.get(this.localeValue || 'en');
        this._boundDblClick = this._onDblClick.bind(this);
        this.element.addEventListener('dblclick', this._boundDblClick);
    }

    disconnect() {
        this.element.removeEventListener('dblclick', this._boundDblClick);
        this._cleanupEdit();
    }

    _onDblClick(event) {
        const td = event.target.closest('td:first-child');
        if (td && this.element.contains(td)) {
            this._startEditTable(td, event);
            return;
        }

        const card = event.target.closest('.file-card');
        if (card && this.element.contains(card)) {
            this._startEditCard(card, event);
            return;
        }
    }

    _startEditTable(td, event) {
        const tr = td.closest('tr');
        if (!tr) return;

        const fullName = td.textContent.trim();
        const dotIndex = fullName.lastIndexOf('.');
        if (dotIndex === -1) return;

        const name = fullName.substring(0, dotIndex);
        const ext = fullName.substring(dotIndex);

        this._currentTr = tr;
        this._currentNameEl = td;
        this._originalFullName = fullName;
        this._originalPath = tr.dataset.scrapePathParam;
        this._originalName = name;
        this._extension = ext;

        td.innerHTML = `<span class="inline-rename-name" contenteditable="true">${this._escapeHtml(name)}</span><span class="inline-rename-ext">${ext}</span>`;

        const span = td.querySelector('.inline-rename-name');
        this._setupEdit(span);

        event.stopPropagation();
    }

    _startEditCard(card, event) {
        const nameEl = card.querySelector('.file-card-name');
        if (!nameEl) return;

        const fullName = nameEl.textContent.trim();
        const dotIndex = fullName.lastIndexOf('.');
        if (dotIndex === -1) return;

        const name = fullName.substring(0, dotIndex);
        const ext = fullName.substring(dotIndex);

        this._currentTr = null;
        this._currentCard = card;
        this._currentNameEl = nameEl;
        this._originalFullName = fullName;
        this._originalPath = card.dataset.scrapePathParam;
        this._originalName = name;
        this._extension = ext;

        nameEl.innerHTML = `<span class="inline-rename-name" contenteditable="true">${this._escapeHtml(name)}</span><span class="inline-rename-ext">${ext}</span>`;

        const span = nameEl.querySelector('.inline-rename-name');
        this._setupEdit(span);

        event.stopPropagation();
    }

    _setupEdit(span) {
        this._selectText(span);

        this._boundKeyDown = e => this._onKeyDown(e);
        this._boundBlur = e => this._onBlur(e);
        span.addEventListener('keydown', this._boundKeyDown);
        span.addEventListener('blur', this._boundBlur);
    }

    _onKeyDown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.currentTarget.blur();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this._cancelEdit();
        }
    }

    _onBlur(event) {
        const span = event.currentTarget;
        const newName = span.textContent.trim();
        if (!newName || newName === this._originalName) {
            this._cancelEdit();
            return;
        }

        span.contentEditable = 'false';

        csrfFetch('/api/files/rename-file', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                library_id: this.libraryIdValue,
                path: this._originalPath,
                new_name: newName,
            }),
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this._currentNameEl.innerHTML = this._escapeHtml(newName) + this._extension;
                    const target = this._currentTr || this._currentCard;
                    if (target) target.dataset.scrapePathParam = data.path;
                } else {
                    throw new Error(data.error || this.translator.trans('js.inline_rename.error'));
                }
            })
            .catch(error => {
                this._cancelEdit();
                const flash = document.getElementById('flash');
                if (flash) {
                    flash.innerHTML = `<div class="flash flash-error">${this._escapeHtml(error.message)}</div>`;
                }
            });
    }

    _cancelEdit() {
        this._cleanupEdit();
        if (this._currentNameEl) {
            this._currentNameEl.innerHTML = this._escapeHtml(this._originalFullName);
        }
    }

    _cleanupEdit() {
        if (this._boundKeyDown) {
            const span = this._currentNameEl?.querySelector('.inline-rename-name');
            if (span) {
                span.removeEventListener('keydown', this._boundKeyDown);
                span.removeEventListener('blur', this._boundBlur);
            }
        }
    }

    _selectText(element) {
        const range = document.createRange();
        range.selectNodeContents(element);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        element.focus();
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str ?? ''));
        return div.innerHTML;
    }
}
