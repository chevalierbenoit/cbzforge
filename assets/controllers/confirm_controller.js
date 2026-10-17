import { Controller } from '@hotwired/stimulus';
import { Translator } from '../translator.js';

export default class extends Controller {
    static values = {
        message: { type: String, default: 'Confirmer ?' },
        confirmText: { type: String, default: 'Confirmer' },
        cancelText: { type: String, default: 'Annuler' },
        locale: String,
    };

    async connect() {
        this.translator = await Translator.get(this.localeValue || 'en');
    }

    confirm(event) {
        event.preventDefault();

        if (confirm(this.translator.trans('js.confirm.title'))) {
            this._proceed(event);
        }
    }

    _proceed(event) {
        const target = this.element.tagName === 'FORM'
            ? this.element
            : this.element.querySelector('form');

        if (target) {
            target.requestSubmit();
        } else if (event.target.tagName === 'A') {
            window.location.href = event.target.href;
        } else if (event.target.tagName === 'BUTTON' && event.target.form) {
            event.target.form.requestSubmit();
        }
    }
}
