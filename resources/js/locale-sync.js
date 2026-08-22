const CHANNEL_NAME = 'gymie-locale';

function applyLocale(locale) {
    if (!locale || document.documentElement.lang === locale) {
        return;
    }

    // SetAppLocale honours the ?locale= query on every route, so a single
    // reload mechanism serves both the admin panel and the public pages.
    const url = new URL(window.location.href);
    url.searchParams.set('locale', locale);
    window.location.replace(url.toString());
}

const channel = new BroadcastChannel(CHANNEL_NAME);
channel.onmessage = (event) => applyLocale(event.data);

window.gymieLocaleSync = {
    broadcast(locale) {
        if (!locale) {
            return;
        }

        channel.postMessage({ locale });
    },
};
