// fetch() that sends the CSRF token of the session with every request.
// The server checks it on every request that changes data (see CsrfProtectionSubscriber).
export function csrfFetch(url, options = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    return fetch(url, {
        ...options,
        headers: { ...(options.headers ?? {}), 'X-CSRF-Token': token },
    });
}
