(function () {
    if (window.GymieActiveTab) return;

    const ACTIVE_MS = 10000;
    const POLL_MS = 2500;
    const HEARTBEAT_MS = 1000;
    const NONCE = Math.random().toString(36).slice(2);

    let userId = '0';
    let leader = false;
    let running = false;
    let lastActivity = Date.now();
    let bornAt = Date.now();
    let pollTimer = null;
    let heartbeatTimer = null;
    let channel = null;
    let listeners = new Set();

    const pref = () => 'gymieActiveTab_' + userId;
    const myKey = () => pref() + '_tab_' + NONCE;
    const holderKey = () => pref() + '_holder';

    function sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function emit(active) {
        listeners.forEach((fn) => fn(active));
    }

    function setLeader(value) {
        if (leader === value) return;
        leader = value;
        emit(leader);
    }

    function onActivity() {
        lastActivity = Date.now();
    }

    function isActive() {
        return Date.now() - lastActivity < ACTIVE_MS;
    }

    async function holdWebLock() {
        if (!navigator.locks || !running) return;
        navigator.locks.request(pref() + '_lock', { ifAvailable: true }, async (lock) => {
            if (!lock) return;
            setLeader(true);
            try {
                while (running && isActive()) {
                    await sleep(1000);
                }
            } finally {
                setLeader(false);
            }
        });
    }

    function writeHeartbeat() {
        if (!running) return;
        try {
            localStorage.setItem(myKey(), JSON.stringify({ nonce: NONCE, updatedAt: Date.now(), bornAt }));
        } catch (e) {}
    }

    function clearHeartbeat() {
        try {
            localStorage.removeItem(myKey());
            const holder = localStorage.getItem(holderKey());
            if (holder && JSON.parse(holder).nonce === NONCE) {
                localStorage.removeItem(holderKey());
            }
        } catch (e) {}
    }

    function reconcile() {
        if (!running) return;
        const now = Date.now();
        let best = null;
        try {
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (!key || !key.startsWith(pref() + '_tab_')) continue;
                let data;
                try {
                    data = JSON.parse(localStorage.getItem(key));
                } catch (e) {
                    continue;
                }
                if (now - data.updatedAt > ACTIVE_MS) continue;
                if (
                    !best ||
                    data.bornAt < best.bornAt ||
                    (data.bornAt === best.bornAt && data.nonce < best.nonce)
                ) {
                    best = data;
                }
            }
        } catch (e) {}

        const mine = best && best.nonce === NONCE;
        if (mine) {
            if (!leader) {
                setLeader(true);
                try {
                    localStorage.setItem(holderKey(), JSON.stringify({ nonce: NONCE }));
                } catch (e) {}
            }
        } else if (leader) {
            setLeader(false);
        }
    }

    async function fallbackStart() {
        try {
            channel = new BroadcastChannel(pref() + '_broadcast');
            channel.addEventListener('message', (ev) => {
                if (ev.data && (ev.data.type === 'reconcile' || ev.data.type === 'departed')) {
                    reconcile();
                }
            });
        } catch (e) {
            channel = null;
        }
        writeHeartbeat();
        heartbeatTimer = setInterval(() => {
            writeHeartbeat();
            reconcile();
        }, HEARTBEAT_MS);
    }

    function fallbackStop() {
        if (channel) {
            try {
                channel.postMessage({ type: 'departed' });
            } catch (e) {}
            channel.close();
            channel = null;
        }
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
        clearHeartbeat();
        setLeader(false);
    }

    window.GymieActiveTab = {
        get isActive() {
            return running && leader;
        },
        onChange(fn) {
            listeners.add(fn);
            return () => {
                listeners.delete(fn);
            };
        },
        async start(id) {
            if (running) return;
            running = true;
            userId = String(id ?? 0);
            listeners = new Set();
            leader = false;
            lastActivity = Date.now();
            bornAt = Date.now();
            ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach((type) => {
                document.addEventListener(type, onActivity, { passive: true, capture: true });
            });
            window.addEventListener('beforeunload', () => {
                if (!navigator.locks) fallbackStop();
            });

            if (navigator.locks) {
                pollTimer = setInterval(() => {
                    if (isActive()) holdWebLock();
                }, POLL_MS);
                holdWebLock();
            } else {
                fallbackStart();
            }
        },
        async stop() {
            if (!running) return;
            running = false;
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (!navigator.locks) {
                fallbackStop();
            } else {
                setLeader(false);
            }
        },
    };
})();
