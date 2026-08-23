{{-- Subscribe to Echo events for every location channel (checkin + signup).
     Host component must expose getLocationTokens() and the onQueueEntry* handlers. --}}
<script>
    document.addEventListener('livewire:init', () => {
        // Track staff presence so a tab left unattended never wins the
        // popup race on behalf of an absent colleague: AFK tabs park new
        // arrivals in the badge instead of claiming them.
        let lastActivity = Date.now();
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach((type) => {
            document.addEventListener(type, () => { lastActivity = Date.now(); }, { passive: true, capture: true });
        });
        const staffIsActive = () => Date.now() - lastActivity < 60000;

        const tokens = @js($this->getLocationTokens());
        if (tokens.length && window.Echo) {
            tokens.forEach((token) => {
                window.Echo.private(`location.${token}`)
                    .listen('QueueEntryCreated', (e) => {
                        // Attention cue for NEW arrivals only — claims,
                        // resolutions and expiries stay silent.
                        window.SoundAlerts && window.SoundAlerts.beep();

                        @this.call('onQueueEntryCreated', e, { active: staffIsActive() });
                    })
                    .listen('QueueEntryClaimed', (e) => {
                        @this.call('onQueueEntryClaimed', e);
                    })
                    .listen('QueueEntryReleased', (e) => {
                        @this.call('onQueueEntryReleased', e);
                    })
                    .listen('QueueEntryResolved', (e) => {
                        @this.call('onQueueEntryResolved', e);
                    })
                    .listen('QueueEntryExpired', (e) => {
                        @this.call('onQueueEntryExpired', e);
                    });
            });
        }

        // Events missed during a disconnect are never replayed — re-sync
        // from the database whenever the socket (re)connects.
        if (window.Echo) {
            window.Echo.connector.pusher.connection.bind('connected', () => {
                @this.call('loadQueueEntries');
            });
        }
    });
</script>
