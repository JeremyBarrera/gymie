

const kebab = (key) => key.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();

const applyColors = (colors) => {
    const style = document.documentElement.style;

    Object.entries(colors).forEach(([key, value]) => {
        style.setProperty(`--c-${kebab(key)}`, value);
    });

    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta && colors.bgB) {
        meta.setAttribute('content', colors.bgB);
    }
};

const startAdminThemeSync = () => {
    if (!window.Echo) {
        return;
    }

    let applyingRemote = false;
    let lastSent = localStorage.getItem('theme') ?? 'light';

    window.Echo.channel('admin.theme')
        .listen('AdminThemeChanged', (e) => {
            applyingRemote = true;
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: e.preset }));

            const switcher = document.querySelector('.fi-theme-switcher');
            if (switcher && window.Alpine && typeof window.Alpine.$data === 'function') {
                try {
                    window.Alpine.$data(switcher).theme = e.preset;
                } catch (err) {
                    
                }
            }

            applyingRemote = false;
        });

    window.addEventListener('theme-changed', (event) => {
        if (applyingRemote || !window.axios || typeof event.detail !== 'string') {
            return;
        }
        if (event.detail === lastSent) {
            return;
        }

        lastSent = event.detail;
        window.axios.post('/admin/theme', { preset: event.detail });
    });
};

window.ThemeLive = {
    start(tokens) {
        if (!tokens || !tokens.length || !window.Echo) {
            return;
        }

        tokens.forEach((token) => {
            window.Echo.channel(`location.theme.${token}`)
                .listen('LocationThemeChanged', (e) => applyColors(e.colors));
        });
    },
};

document.addEventListener('DOMContentLoaded', () => {
    if (document.body.classList.contains('fi-body')) {
        startAdminThemeSync();
    }
});