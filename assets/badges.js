// Metadata status labels for file cards and table cells.
// Text labels replace the former ✓ / ✗ glyphs, which did not say what they meant.
const STATES = {
    ok: { className: 'badge badge-success', key: 'js.explore.has_metadata' },
    missing: { className: 'badge badge-neutral', key: 'js.explore.no_metadata' },
    failed: { className: 'badge badge-danger', key: 'js.explore.scrape_failed' },
};

export function setMetadataBadge(badge, state, translator) {
    const { className, key } = STATES[state];
    badge.className = className;
    badge.textContent = translator.trans(key);
}

export function metadataBadge(state, translator) {
    const { className, key } = STATES[state];
    return `<span class="${className}">${translator.trans(key)}</span>`;
}
