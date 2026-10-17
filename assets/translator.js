const cache = new Map();

export class Translator {
    constructor(locale, messages) {
        this.locale = locale;
        this.messages = messages;
    }

    static async get(locale) {
        if (!cache.has(locale)) {
            const response = await fetch(`/translations/${locale}.json`);
            if (!response.ok) {
                throw new Error(`Failed to load translations for locale "${locale}"`);
            }
            cache.set(locale, new Translator(locale, await response.json()));
        }
        return cache.get(locale);
    }

    trans(key, params = {}) {
        let message = this.messages[key] ?? key;
        for (const [name, value] of Object.entries(params)) {
            message = message.replaceAll(`%${name}%`, value);
        }
        return message;
    }
}
