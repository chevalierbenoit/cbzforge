import { Controller } from '@hotwired/stimulus';
import { Translator } from '../translator.js';

export default class extends Controller {
    static targets = ['input', 'output'];
    static values = {
        locale: String,
    };

    async connect() {
        this.translator = await Translator.get(this.localeValue || 'en');
        this._timer = null;
        this.preview();
    }

    disconnect() {
        clearTimeout(this._timer);
    }

    preview() {
        clearTimeout(this._timer);
        this._timer = setTimeout(async () => {
            const expression = this.inputTarget.value;
            if (!expression.trim()) {
                this.outputTarget.textContent = '';
                return;
            }

            try {
                const response = await fetch('/admin/rename-template/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ expression }),
                });

                const data = await response.json();
                if (data.rendered !== undefined) {
                    this.outputTarget.textContent = data.rendered;
                } else {
                    this.outputTarget.textContent = '⚠ ' + (data.error || this.translator.trans('js.rename.error'));
                }
            } catch (e) {
                this.outputTarget.textContent = '⚠ ' + this.translator.trans('js.rename.connection_error');
            }
        }, 300);
    }
}
