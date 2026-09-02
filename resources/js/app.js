import './bootstrap';
import './camera-capture';
import './theme-live';
import './device-locale';
import './locale-live';
import './sound-alerts';
import './active-tab';

document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) {
                if (!document.body.classList.contains('fi-body')) {
                    return;
                }
                if (window.location.pathname === '/login') {
                    return;
                }
                preventDefault();
                window.location.href = '/login';
            }
        });
    });

    Livewire.on('notify', (raw) => {
        if (typeof window.FilamentNotification === 'undefined') {
            return;
        }

        const params = Array.isArray(raw) ? raw[0] : raw;
        const message = params?.message ?? raw?.message;

        if (typeof message !== 'string' || message.trim() === '') {
            return;
        }

        const type = params?.type ?? raw?.type ?? 'info';

        new window.FilamentNotification()
            .title(message)
            .status(type)
            .send();
    });
});
