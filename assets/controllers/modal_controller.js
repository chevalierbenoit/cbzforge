import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content'];
    static values = { open: { type: Boolean, default: false } };

    connect() {
        document.addEventListener('keydown', this._handleEscape);
    }

    disconnect() {
        document.removeEventListener('keydown', this._handleEscape);
    }

    _handleEscape = (event) => {
        if (event.key === 'Escape' && this.openValue) {
            this.close();
        }
    };

    openValueChanged(open) {
        this.element.classList.toggle('modal-open', open);
        if (open) {
            document.body.style.overflow = 'hidden';
            this.dispatch('open');
        } else {
            document.body.style.overflow = '';
            this.dispatch('close');
        }
    }

    open(event) {
        if (event && event.params && event.params.content) {
            this.contentTarget.innerHTML = event.params.content;
        }
        this.openValue = true;
    }

    close() {
        this.openValue = false;
    }

    closeBackdrop(event) {
        if (event.target === event.currentTarget) {
            this.close();
        }
    }
}
