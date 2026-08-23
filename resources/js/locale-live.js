/**
 * Live locale sync:
 *  - Admin panel (auto-detected via the `fi-body` class): subscribes to the
 *    public `admin.locale` channel and keeps open admin tabs in the same
 *    language. When an admin picks a locale the LocaleSwitcher persists it
 *    server-side and broadcasts `LocaleChanged`; every other tab reloads so
 *    translations and RTL direction re-render consistently.
 */

const normalize = (value) => String(value ?? '').trim().toLowerCase().split(/[-_]/)[0];

const applyLocale = (locale) => {
    if (typeof locale !== 'string' || locale.trim() === '') {
        return;
    }

    if (normalize(locale) === normalize(document.documentElement.lang)) {
        return;
    }

    window.location.reload();
};

document.addEventListener('DOMContentLoaded', () => {
    if (!window.Echo || !document.body.classList.contains('fi-body')) {
        return;
    }

    window.Echo.channel('admin.locale')
        .listen('LocaleChanged', (e) => applyLocale(e?.locale));
});
