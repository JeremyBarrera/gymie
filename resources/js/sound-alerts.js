/*
 * Sound alerts for live queue arrivals.
 *
 * Robustness contract:
 *  - The server preference is mirrored per page load via
 *    window.GYMIE_SOUND_ALERTS. Other tabs of the same user follow live
 *    through the private "user.{id}" websocket channel
 *    (SoundAlertsToggled), so a toggle in one tab applies everywhere.
 *  - Browser autoplay policy forbids audio before a user gesture: while
 *    the preference is on but this page has not been unlocked yet, a
 *    capture-phase pointerdown listener silently creates/resumes the
 *    AudioContext on the next interaction anywhere.
 *  - Every entry point is guarded; any failure degrades to silence and
 *    never propagates into page code.
 *  - Beeps within 1.5 s of each other are collapsed (the Reception page
 *    and the global popup both subscribe to QueueEntryCreated).
 */

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
        [880.0, 0.0, 0.14],
        [1318.5, 0.13, 0.30],
    ].forEach(([frequency, start, end]) => {
        const osc = context.createOscillator();
        const gain = context.createGain();

        osc.type = 'sine';
        osc.frequency.value = frequency;

        gain.gain.setValueAtTime(0.0001, t + start);
        gain.gain.exponentialRampToValueAtTime(0.44, t + start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + end);

        osc.connect(gain);
        gain.connect(context.destination);

        osc.start(t + start);
        osc.stop(t + end + 0.02);
    });
}

function dingWhenReady(context) {
    if (context.state === 'running') {
        try {
            ding(context);
        } catch {}

        return;
    }

    Promise.resolve(context.resume())
        .then(() => {
            try {
                ding(context);
            } catch {}
        })
        .catch(() => {});
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

/**
 * Called synchronously inside the "Enable" click: unlocks audio within the
 * user gesture, flips the client-side flag immediately (no round-trip race),
 * and plays the confirmation chime as soon as the context is running.
 */
export function enableFromGesture() {
    try {
        const context = ensureContext();

        if (!context) {
            return;
        }

        enabled = true;
        markUnlocked();
        lastBeepAt = Date.now();
        dingWhenReady(context);
    } catch {}
}

/**
 * Called synchronously inside the click that toggles the preference so
 * the AudioContext is created/resumed while the user gesture is still
 * valid (browsers reject resumes outside gestures).
 */
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

    // The preference is a server-side setting: when this user toggles it in
    // any tab (or device), the private user channel tells every other open
    // panel tab live. Receiving side only syncs state — it never dings.
    if (window.Echo && window.GYMIE_USER_ID) {
        window.Echo.private(`user.${window.GYMIE_USER_ID}`).listen('SoundAlertsToggled', (e) => {
            const on = Boolean(e?.enabled);

            setEnabled(on);

            try {
                window.dispatchEvent(new CustomEvent('gymie-sound-synced', { detail: { enabled: on } }));
            } catch {}
        });
    }

    document.addEventListener('livewire:init', () => {
        window.Livewire.on('sound-alerts-updated', (payload) => {
            const on = Boolean(payload?.[0]?.enabled);

            setEnabled(on);

            if (on) {
                setTimeout(beep, 60);
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

window.SoundAlerts = { beep, setEnabled, ensureUnlocked, enableFromGesture };
