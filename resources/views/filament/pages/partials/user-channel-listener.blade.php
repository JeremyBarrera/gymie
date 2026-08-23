{{-- Subscribe to the private user channel so follow-up alerts surface live.
     Host component must expose onFollowUpEscalated() and loadNotifications(). --}}
<script>
    document.addEventListener('livewire:init', () => {
        const userId = @js($this->getUserChannelId());

        if (! userId || ! window.Echo) {
            return;
        }

        window.Echo.private(`user.${userId}`)
            .listen('FollowUpEscalated', (e) => {
                // Refresh signal only — the DB row stays the source of
                // truth, so the handler re-fetches instead of applying
                // the payload as a client-side delta.
                @this.call('onFollowUpEscalated', e);
            });

        // Events missed during a disconnect are never replayed — re-sync
        // from the database whenever the socket (re)connects.
        window.Echo.connector.pusher.connection.bind('connected', () => {
            @this.call('loadNotifications');
        });
    });
</script>
