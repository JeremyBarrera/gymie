import './bootstrap';
import './echo';
import './camera-capture';
import './theme-live';
import './device-locale';
import './locale-live';
import './sound-alerts';

document.addEventListener('livewire:init', () => {
    Livewire.on('notify', (params) => {
        if (typeof window.FilamentNotification === 'undefined') {
            return;
        }

        const message = params?.message;

        if (typeof message !== 'string' || message.trim() === '') {
            return;
        }

        new window.FilamentNotification()
            .title(message)
            .status(params?.type ?? 'info')
            .send();
    });
});
