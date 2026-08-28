

const UNLOCKED_KEY = 'gymie-sound-unlocked';
const DEDUPE_MS = 1500;

let ctx = null;
let unsupported = false;
let enabled = false;
let armedForGesture = false;
let lastBeepAt = 0;

function ensureContext() {
    if (unsupported) {
        return null;
    }

    if (ctx) {
        return ctx;
    }

    try {
        const AC = window.AudioContext || window.webkitAudioContext;

        if (!AC) {
            unsupported = true;

            return null;
        }

        ctx = new AC();
    } catch {
        unsupported = true;
        ctx = null;
    }

    return ctx;
}

function markUnlocked() {
    try {
        localStorage.setItem(UNLOCKED_KEY, '1');
    } catch {}
}

function armGestureUnlock() {
    if (armedForGesture) {
        return;
    }

    armedForGesture = true;

    const unlock = () => {
        document.removeEventListener('pointerdown', unlock, true);
        armedForGesture = false;

        const context = ensureContext();

        if (!context || context.state !== 'suspended') {
            return;
        }

        try {
            context.resume().then(markUnlocked).catch(() => {});
        } catch {}
    };

    document.addEventListener('pointerdown', unlock, true);
}

function ding(context) {
    const t = context.currentTime;
    [
        [659.3, 0.0, 0.12],
        [880.0, 0.02, 0.22],
        [1318.5, 0.10, 0.40],
    ].forEach(([frequency, start, end]) => {
        const osc = context.createOscillator();
        const gain = context.createGain();
        osc.type = 'sine';
        osc.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, t + start);
        gain.gain.exponentialRampToValueAtTime(1.45, t + start + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + end);
        osc.connect(gain);
        gain.connect(context.destination);
        osc.start(t + start);
        osc.stop(t + end + 0.06);
    });
}

function dingWhenReady(context) {
    if (context.state === 'running') {
        try {
            ding(context);
            return true;
        } catch {
            return false;
        }
    }

    try {
        const p = context.resume();
        if (p && typeof p.then === 'function') {
            p.then(() => {
                try {
                    ding(context);
                } catch {}
            }).catch(() => {
                tryFallbackBeep();
            });
        } else if (context.state === 'running') {
            try {
                ding(context);
            } catch {}
        } else {
            tryFallbackBeep();
        }
        return true;
    } catch {
        tryFallbackBeep();
        return false;
    }
}

function tryFallbackBeep() {
    try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        const tmp = new AC();
        const doDing = () => {
            try {
                ding(tmp);
                setTimeout(() => {
                    try {
                        tmp.close();
                    } catch {}
                }, 600);
            } catch {}
        };
        if (tmp.state === 'suspended') {
            try {
                const p = tmp.resume();
                if (p && typeof p.then === 'function') {
                    p.then(doDing).catch(() => {
                        try {
                            doDing();
                        } catch {}
                    });
                } else {
                    doDing();
                }
            } catch {
                doDing();
            }
        } else {
            doDing();
        }
    } catch {}
}

function playConfirmationChime() {
    try {
        const context = ensureContext();
        if (!context) {
            tryFallbackBeep();
            return;
        }
        if (context.state === 'running') {
            try {
                ding(context);
                return;
            } catch {
                tryFallbackBeep();
                return;
            }
        }
        const resumed = dingWhenReady(context);
        if (!resumed) {
            tryFallbackBeep();
        }
    } catch {
        tryFallbackBeep();
    }
}

export function setEnabled(on) {
    enabled = Boolean(on);

    if (enabled) {
        armGestureUnlock();
    }
}

export function beep() {
    if (!enabled) {
        return;
    }

    const now = Date.now();

    if (now - lastBeepAt < DEDUPE_MS) {
        return;
    }

    lastBeepAt = now;

    try {
        const context = ensureContext();

        if (!context) {
            return;
        }

        if (context.state === 'suspended') {
            armGestureUnlock();

            dingWhenReady(context);

            return;
        }

        ding(context);
    } catch {}
}

export function enableFromGesture() {
    try {
        const context = ensureContext();

        if (!context) {
            enabled = true;
            markUnlocked();
            tryFallbackBeep();
            return;
        }

        enabled = true;
        markUnlocked();
        playConfirmationChime();
    } catch {
        tryFallbackBeep();
    }
}

export function ensureUnlocked() {
    try {
        const context = ensureContext();

        if (!context) {
            return;
        }

        if (context.state === 'suspended') {
            context.resume().then(markUnlocked).catch(() => {});
        } else {
            markUnlocked();
        }
    } catch {}
}

function boot() {
    setEnabled(window.GYMIE_SOUND_ALERTS === true || window.GYMIE_SOUND_ALERTS === '1');

    
    
    
    
    
    if (window.Echo && window.GYMIE_USER_ID) {
        window.Echo.channel(`user.${window.GYMIE_USER_ID}`).listen('SoundAlertsToggled', (e) => {
            const on = Boolean(e?.enabled);

            setEnabled(on);

            try {
                window.dispatchEvent(new CustomEvent('gymie-sound-synced', { detail: { enabled: on } }));
            } catch {}
        });
    }

    document.addEventListener('livewire:init', () => {
        window.Livewire.on('sound-alerts-updated', (raw) => {
            const payload = Array.isArray(raw) ? raw[0] : raw;
            const enabled = payload?.enabled ?? raw?.enabled ?? raw?.[0]?.enabled;
            setEnabled(Boolean(enabled));
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

window.SoundAlerts = { beep, setEnabled, ensureUnlocked, enableFromGesture };
