import { Controller } from '@hotwired/stimulus';

// Switches between light and dark. The choice is a per-browser preference, kept in
// localStorage; without a choice the system setting applies (see base.html.twig).
const STORAGE_KEY = 'cbzforge-theme';

export default class extends Controller {
    toggle() {
        const root = document.documentElement;
        const isDark = root.dataset.theme
            ? root.dataset.theme === 'dark'
            : window.matchMedia('(prefers-color-scheme: dark)').matches;
        const next = isDark ? 'light' : 'dark';

        root.dataset.theme = next;
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {}
    }
}
