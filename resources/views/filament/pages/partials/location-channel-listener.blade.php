
<script>
    document.addEventListener('livewire:init', () => {
        const resync = @js($resyncMethod ?? null);

        
        
        
        let lastActivity = Date.now();
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach((type) => {
            document.addEventListener(type, () => { lastActivity = Date.now(); }, { passive: true, capture: true });
        });
        const staffIsActive = () => Date.now() - lastActivity < 60000;

        const pageLoadAt = Date.now();
        const activeTab = window.GymieActiveTab;
        activeTab && activeTab.start(window.GYMIE_USER_ID ?? 0);
        const isNotifyingTab = () => {
            if (!staffIsActive()) return false;
            const g = window.GymieActiveTab;
            if (!g) return true;
            if (g.isActive) return true;
            if (Date.now() - pageLoadAt < 5000) return true;
            if (!navigator.locks) {
                try {
                    const uid = window.GYMIE_USER_ID ?? 0;
                    const prefix = 'gymieActiveTab_' + uid + '_tab_';
                    let live = 0;
                    for (let i = 0; i < localStorage.length; i++) {
                        const k = localStorage.key(i);
                        if (!k || !k.startsWith(prefix)) continue;
                        const d = JSON.parse(localStorage.getItem(k) || 'null');
                        if (d && Date.now() - d.updatedAt < 10000) live++;
                    }
                    if (live <= 1) return true;
                } catch {}
            }
            return false;
        };

        const tokens = @js($this->getLocationTokens());
        if (tokens.length && window.Echo) {
            tokens.forEach((token) => {
                window.Echo.private(`location.${token}`)
                    .listen('QueueEntryCreated', (e) => {
                        const notifying = isNotifyingTab();

                        
                        
                        if (notifying) {
                            window.SoundAlerts && window.SoundAlerts.beep();
                        }

                        @this.call('onQueueEntryCreated', e, notifying);
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
                    })
                    .listen('MemberBanChanged', () => {
                        
                        
                        @this.call(resync);
                    });
            });
        }

        
        
        if (window.Echo && resync) {
            window.Echo.connector.pusher.connection.bind('connected', () => {
                @this.call(resync);
            });
        }
    });
</script>
