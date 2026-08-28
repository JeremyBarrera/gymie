

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
