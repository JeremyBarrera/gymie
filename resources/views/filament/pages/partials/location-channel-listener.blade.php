{{-- Subscribe to Echo events for every location channel (checkin + signup).
     Host component must expose getLocationTokens() and the four onQueueEntry* handlers. --}}
<script>
    document.addEventListener('livewire:init', () => {
        const tokens = @js($this->getLocationTokens());
        if (tokens.length && window.Echo) {
            tokens.forEach((token) => {
                window.Echo.private(`location.${token}`)
                    .listen('QueueEntryCreated', (e) => {
                        @this.call('onQueueEntryCreated', e);
                    })
                    .listen('QueueEntryClaimed', (e) => {
                        @this.call('onQueueEntryClaimed', e);
                    })
                    .listen('QueueEntryResolved', (e) => {
                        @this.call('onQueueEntryResolved', e);
                    })
                    .listen('QueueEntryExpired', (e) => {
                        @this.call('onQueueEntryExpired', e);
                    });
            });
        }
    });
</script>