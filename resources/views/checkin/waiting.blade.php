<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'fa'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ \App\Support\ColorContrast::derivePalette($background, $accent)['bgB'] }}">
    <title>{{ $location->name }} - {{ __('app.scan.waiting_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/echo.js'])
    @include('checkin.partials.theme-colors')
    <style>
        * { box-sizing: border-box; }
        html {
            background-color: var(--c-bg-b);
        }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            min-height: 100dvh;
            background-color: var(--c-bg-b);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: calc(20px + env(safe-area-inset-top, 0px)) 20px calc(20px + env(safe-area-inset-bottom, 0px)) 20px;
        }
        .waiting-container {
            width: 100%;
            max-width: 420px;
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            padding: 40px 32px;
            text-align: center;
        }
        @media (prefers-color-scheme: dark) {
            .waiting-container {
                box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            }
        }
        .location-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--c-text);
            margin: 0 0 24px;
        }
        .spinner-wrapper {
            position: relative;
            width: 80px;
            height: 80px;
            margin: 0 auto 32px;
        }
        .spinner {
            width: 100%;
            height: 100%;
            border: 4px solid var(--c-ring);
            border-top-color: var(--c-base);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .pulse-ring {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            border: 4px solid var(--c-base);
            border-radius: 50%;
            animation: pulse 2s ease-out infinite;
            opacity: 0;
        }
        .pulse-ring:nth-child(2) { animation-delay: 0.5s; }
        .pulse-ring:nth-child(3) { animation-delay: 1s; }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.6; }
            100% { transform: scale(1.5); opacity: 0; }
        }
        .status-text {
            font-size: 1.125rem;
            color: var(--c-text);
            margin: 0 0 8px;
            min-height: 1.5em;
        }
        .waiting-member-name {
            font-size: 1rem;
            color: var(--c-text-muted);
            margin: 0 0 24px;
            font-weight: 500;
        }
        .waiting-btn {
            display: inline-block;
            margin-top: 24px;
            padding: 12px 24px;
            background: var(--c-surface);
            color: var(--c-base);
            border: 1.5px solid var(--c-base);
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.9375rem;
            font-weight: 600;
            transition: background 0.2s, color 0.2s;
        }
        .waiting-btn:hover {
            background: var(--c-base);
            color: var(--c-on-base);
        }

        
        .result-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .result-overlay.visible {
            opacity: 1;
            visibility: visible;
        }
        .result-overlay.success {
            background: var(--c-success-bg);
        }
        .result-overlay.denied {
            background: var(--c-danger-bg);
        }
        .result-card {
            text-align: center;
            max-width: 380px;
            width: 100%;
            animation: resultPop 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }
        @keyframes resultPop {
            from { opacity: 0; transform: scale(0.8) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .result-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .result-icon svg {
            width: 40px;
            height: 40px;
        }
        .result-overlay.success .result-icon {
            background: var(--c-success-icon-bg);
            color: var(--c-success);
        }
        .result-overlay.denied .result-icon {
            background: var(--c-danger-icon-bg);
            color: var(--c-danger);
        }
        .result-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 12px;
        }
        .result-overlay.success .result-title { color: var(--c-success-strong); }
        .result-overlay.denied .result-title { color: var(--c-danger-strong); }
        .result-message {
            font-size: 1rem;
            margin: 0;
            line-height: 1.6;
        }
        .result-overlay.success .result-message { color: var(--c-success-strong); }
        .result-overlay.denied .result-message { color: var(--c-danger-strong); }
    </style>
</head>
<body>
    <div class="public-main">
    <div class="waiting-container">
        <h1 class="location-name">{{ $location->name }}</h1>

        @if ($kind === 'signup' && $memberName)
        <p class="waiting-member-name">{{ $memberName }}</p>
        @endif

        <div class="spinner-wrapper">
            <div class="spinner"></div>
            <div class="pulse-ring"></div>
            <div class="pulse-ring"></div>
            <div class="pulse-ring"></div>
        </div>

        <p id="status-text" class="status-text">{{ __('app.scan.status_waiting') }}</p>

        @if ($kind === 'signup' && $signupToken)
        <a href="/signup/{{ $signupToken }}?locale={{ app()->getLocale() }}" class="waiting-btn" onclick="localStorage.removeItem('pendingSignupUuid')">{{ __('app.scan.sign_up_another') }}</a>
        @endif
    </div>
    </div>

    @include('checkin.partials.public-footer')

    <div id="result-overlay" class="result-overlay">
        <div class="result-card">
            <div class="result-icon" id="result-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h2 id="result-title" class="result-title"></h2>
            <p id="result-message" class="result-message"></p>
        </div>
    </div>

    <script>
        window.addEventListener('pageshow', (e) => {
            if (e.persisted) {
                window.location.reload();
            }
        });

        function whenEchoReady(cb) {
            if (window.Echo) {
                cb(window.Echo);
                return;
            }
            window.addEventListener('EchoLoaded', () => cb(window.Echo), { once: true });
            let tries = 0;
            const iv = setInterval(() => {
                if (window.Echo) {
                    clearInterval(iv);
                    cb(window.Echo);
                } else if (++tries > 100) {
                    clearInterval(iv);
                }
            }, 50);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const uuid = "{{ $uuid }}";
            const kind = "{{ $kind }}";
            @php $scanMessages = [
                    'complete_checkin' => __('app.scan.complete_checkin'),
                    'complete_signup' => __('app.scan.complete_signup'),
                    'complete_signup_checked_in' => __('app.scan.complete_signup_checked_in'),
                    'confirmed_checkin' => __('app.scan.confirmed_checkin'),
                    'confirmed_signup' => __('app.scan.confirmed_signup'),
                    'confirmed_signup_checked_in' => __('app.scan.confirmed_signup_checked_in'),
                    'denied_checkin' => __('app.scan.denied_checkin'),
                    'denied_signup' => __('app.scan.denied_signup'),
                    'denied_generic' => __('app.scan.denied_generic'),
                    'expired' => __('app.scan.expired'),
                    'status_claiming' => __('app.scan.status_claiming'),
                ]; @endphp
            const messages = @json($scanMessages);

            const statusText = document.getElementById('status-text');
            const resultOverlay = document.getElementById('result-overlay');
            const resultIcon = document.getElementById('result-icon');
            const resultTitle = document.getElementById('result-title');
            const resultMessage = document.getElementById('result-message');

            whenEchoReady((echo) => {
                echo.channel(`queue.${uuid}`)
                    .listen('QueueEntryClaimed', () => {
                        if (!resultOverlay.classList.contains('visible')) {
                            statusText.textContent = messages.status_claiming;
                        }
                    })
                    .listen('QueueEntryResolved', (e) => {
                        showResult(e.approved, e.deniedReason, e.checkedIn);
                    })
                    .listen('QueueEntryExpired', () => {
                        showResult(false, messages.expired, false);
                    });

                echo.connector.pusher.connection.bind('connected', () => {
                    syncStatus();
                });
            });

            function syncStatus() {
                if (resultOverlay.classList.contains('visible')) {
                    return;
                }
                fetch(`/waiting/${uuid}/status`)
                    .then((r) => r.json())
                    .then((data) => {
                        if (resultOverlay.classList.contains('visible')) {
                            return;
                        }
                        if (data.state === 'approved') {
                            showResult(true, null, Boolean(data.checkedIn));
                        } else if (data.state === 'denied') {
                            showResult(false, data.deniedReason || messages.denied_generic, false);
                        } else if (data.state === 'expired') {
                            showResult(false, messages.expired, false);
                        } else if (data.reviewing) {
                            statusText.textContent = messages.status_claiming;
                        }
                    })
                    .catch(() => {});
            }

            whenEchoReady(() => {
                window.ThemeLive && window.ThemeLive.start(@js([$locationToken]));
            });

            syncStatus();

            function showResult(approved, reason, checkedIn) {
                const pendingKey = kind === 'signup' ? 'pendingSignupUuid' : 'pendingCheckinUuid';
                localStorage.removeItem(pendingKey);
                resultOverlay.classList.add(approved ? 'success' : 'denied');

                if (approved) {
                    resultIcon.innerHTML = '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';

                    if (kind === 'signup' && checkedIn) {
                        resultTitle.textContent = messages.complete_signup_checked_in;
                        resultMessage.textContent = messages.confirmed_signup_checked_in;
                    } else if (kind === 'checkin') {
                        resultTitle.textContent = messages.complete_checkin;
                        resultMessage.textContent = messages.confirmed_checkin;
                    } else {
                        resultTitle.textContent = messages.complete_signup;
                        resultMessage.textContent = messages.confirmed_signup;
                    }
                } else {
                    resultIcon.innerHTML = '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
                    resultTitle.textContent = kind === 'checkin' ? messages.denied_checkin : messages.denied_signup;
                    resultMessage.textContent = reason || messages.denied_generic;
                }

                resultOverlay.classList.add('visible');
            }
        });
    </script>
</body>
</html>
