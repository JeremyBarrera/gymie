<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'fa'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ \App\Support\ColorContrast::derivePalette($background, $accent)['bgB'] }}">
    <title>{{ $location->name }} - {{ $kind === 'checkin' ? __('app.scan.title_checkin') : __('app.scan.title_signup') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
            padding: calc(16px + env(safe-area-inset-top, 0px)) 16px calc(16px + env(safe-area-inset-bottom, 0px)) 16px;
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(16px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .scan-container {
            width: 100%;
            max-width: 420px;
            position: relative;
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.04);
            padding: 32px;
            animation: cardIn 0.4s ease-out;
        }
        @media (prefers-color-scheme: dark) {
            .scan-container {
                box-shadow: 0 20px 40px rgba(0,0,0,0.3), 0 4px 12px rgba(0,0,0,0.15);
            }
        }
        .scan-hero {
            text-align: center;
            margin-bottom: 24px;
        }
        .scan-hero--signup {
            margin-bottom: 24px;
        }
        .kind-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .kind-chip__icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--c-base);
            color: var(--c-on-base);
        }
        .kind-chip__icon svg { width: 24px; height: 24px; }
        .kind-chip__label {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--c-text);
        }
        .location-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--c-text);
            margin: 0 0 4px;
        }
        .location-tagline {
            color: var(--c-text-muted);
            font-size: 0.95rem;
            margin: 0;
        }
        .location-intro {
            color: var(--c-text-muted);
            font-size: 0.9rem;
            margin: 12px auto 0;
            max-width: 320px;
            line-height: 1.5;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .form-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--c-text);
            margin-bottom: 6px;
        }
        .form-label-icon {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            color: var(--c-base);
        }
        .form-label--required::after {
            content: ' *';
            color: var(--c-danger);
        }
        .form-select, .form-input {
            width: 100%;
            max-width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--c-border);
            border-radius: 10px;
            font-size: 0.9375rem;
            line-height: 1.4;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: var(--c-surface);
            color: var(--c-text);
        }
        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: var(--c-base);
            box-shadow: 0 0 0 3px var(--c-ring);
        }
        input[type="date"].form-input {
            -webkit-appearance: none;
            appearance: none;
            min-width: 0;
            max-width: 100%;
        }
        input[type="date"].form-input::-webkit-datetime-edit {
            min-width: 0;
            overflow: hidden;
        }
        input[type="date"].form-input::-webkit-date-and-time-value {
            min-width: 0;
            overflow: hidden;
            text-align: start;
        }
        .form-section {
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--c-text-muted);
            margin: 28px 0 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--c-border);
        }
        .btn {
            width: 100%;
            padding: 15px 24px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s, background-color 0.2s;
        }
        .btn:active { transform: scale(0.98); }
        .btn-primary {
            background: var(--c-base);
            color: var(--c-on-base);
            box-shadow: 0 4px 14px rgba(0,0,0,0.1);
        }
        .btn-primary:hover {
            background: var(--c-hover);
            box-shadow: 0 8px 24px var(--c-ring), 0 4px 14px rgba(0,0,0,0.1);
        }
        .btn-primary:active { background: var(--c-active); }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; }
        .error-message {
            background: var(--c-danger-bg);
            border: 1px solid var(--c-danger-border);
            color: var(--c-danger);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.875rem;
            display: none;
        }
        .loading { display: none; }
        .btn.loading .btn-text { display: none; }
        .btn.loading .loading { display: inline-block; }
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid color-mix(in srgb, var(--c-on-base) 30%, transparent);
            border-top-color: var(--c-on-base);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
            margin-right: 8px;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .locale-switcher {
            position: fixed;
            top: calc(16px + env(safe-area-inset-top, 0px));
            right: calc(16px + env(safe-area-inset-right, 0px));
            z-index: 10;
        }
        .locale-switcher select {
            padding: 4px 8px;
            border: 1px solid var(--c-border);
            border-radius: 6px;
            font-size: 0.75rem;
            background: var(--c-surface);
            color: var(--c-text-muted);
            cursor: pointer;
            opacity: 0.5;
            transition: opacity 0.2s;
        }
        .locale-switcher select:hover { opacity: 1; }
        .phone-field {
            display: flex;
            border: 1.5px solid var(--c-border);
            border-radius: 10px;
            overflow: hidden;
            background: var(--c-surface);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .phone-field:focus-within {
            border-color: var(--c-base);
            box-shadow: 0 0 0 3px var(--c-ring);
        }
        .phone-field .dial-code {
            flex: 0 0 auto;
            max-width: 80px;
            border: none;
            border-inline-end: 1.5px solid var(--c-border);
            background: var(--c-surface);
            color: var(--c-text);
            font-size: 0.9375rem;
            padding: 11px 10px;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .phone-field .form-input {
            flex: 1 1 auto;
            min-width: 0;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }
        .phone-field:focus-within .dial-code {
            border-inline-end-color: var(--c-base);
        }
        .signup-note {
            text-align: center;
            font-size: 0.8rem;
            color: var(--c-text-muted);
            margin: 24px 0 0;
            padding-top: 16px;
            border-top: 1px solid var(--c-border);
        }
        @media (max-width: 480px) {
            .scan-container { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .location-name { font-size: 1.3rem; }
        }
    </style>
</head>
<body>
    <div class="locale-switcher">
        <select id="locale-switcher" aria-label="Language">
            @foreach (\App\Support\AppConfig::supportedLocales() as $locale)
                <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>
                    {{ \App\Support\AppConfig::localeFlags()[$locale] ?? '🏳️' }} {{ __('app.locales.' . $locale) }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="public-main">
    <div class="scan-container scan-container--{{ $kind }}">
        <div class="scan-hero scan-hero--{{ $kind }}">
            <span class="kind-chip">
                <span class="kind-chip__icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" style="width:24px;height:24px">
                        <path fill-rule="evenodd" d="M18 5.25a2.25 2.25 0 0 0-2.012-2.238A2.25 2.25 0 0 0 13.75 1h-1.5a2.25 2.25 0 0 0-2.238 2.012c-.875.092-1.6.686-1.884 1.488H11A2.5 2.5 0 0 1 13.5 7v7h2.25A2.25 2.25 0 0 0 18 11.75v-6.5ZM12.25 2.5a.75.75 0 0 0-.75.75v.25h3v-.25a.75.75 0 0 0-.75-.75h-1.5Z" clip-rule="evenodd"/>
                        <path fill-rule="evenodd" d="M3 6a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1H3Zm6.874 4.166a.75.75 0 1 0-1.248-.832l-2.493 3.739-.853-.853a.75.75 0 0 0-1.06 1.06l1.5 1.5a.75.75 0 0 0 1.154-.114l3-4.5Z" clip-rule="evenodd"/>
                    </svg>
                </span>
                <span class="kind-chip__label">{{ $kind === 'checkin' ? __('app.scan.kind_checkin') : __('app.scan.kind_signup') }}</span>
            </span>
            <h1 class="location-name">{{ $location->name }}</h1>
            <p class="location-tagline">{{ $kind === 'checkin' ? __('app.scan.tagline_checkin') : __('app.scan.tagline_signup') }}</p>
            @if ($kind === 'signup')
            <p class="location-intro">{{ __('app.scan.signup_intro') }}</p>
            @endif
        </div>

        <div id="error-message" class="error-message"></div>

        <form id="scan-form" method="POST" action="{{ route('checkin.submit', ['token' => $token]) }}">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">

            @if ($kind === 'checkin')
            <div class="form-group">
                <label class="form-label" for="identifier_type">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/></svg>
                    {{ __('app.scan.identify_by') }}
                </label>
                <select name="identifier_type" id="identifier_type" class="form-select" required>
                    <option value="contact">{{ __('app.scan.phone_number') }}</option>
                    <option value="government_id">{{ __('app.fields.government_id') }}</option>
                    <option value="code">{{ __('app.fields.member_code') }}</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="value">
                    <svg id="value-icon" class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 3.5A1.5 1.5 0 0 1 3.5 2h1.148a1.5 1.5 0 0 1 1.465 1.175l.716 3.223a1.5 1.5 0 0 1-1.052 1.767l-.933.267c-.41.117-.643.555-.48.95a11.542 11.542 0 0 0 6.254 6.254c.395.163.833-.07.95-.48l.267-.933a1.5 1.5 0 0 1 1.767-1.052l3.223.716A1.5 1.5 0 0 1 18 15.352V16.5a1.5 1.5 0 0 1-1.5 1.5H15c-1.149 0-2.263-.15-3.326-.43A13.022 13.022 0 0 1 2.43 8.326 13.019 13.019 0 0 1 2 5V3.5Z" clip-rule="evenodd"/></svg>
                    {{ __('app.scan.value') }}
                </label>
                <div id="value-phone-field">
                    @include('checkin.partials.phone-field', ['name' => 'value', 'id' => 'value', 'required' => true])
                </div>
                <input type="text" id="value-text" name="value" class="form-input"
                       style="display:none"
                       placeholder="{{ __('app.placeholders.member_code') }}"
                       required autocomplete="off">
            </div>
            @else
            <div class="form-group">
                <label class="form-label form-label--required" for="name">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/></svg>
                    {{ __('app.fields.name') }}
                </label>
                <input type="text" name="name" id="name" class="form-input"
                       placeholder="{{ __('app.placeholders.example_full_name') }}"
                       required autofocus autocomplete="name">
            </div>

            <div class="form-group">
                <label class="form-label form-label--required" for="contact">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 3.5A1.5 1.5 0 0 1 3.5 2h1.148a1.5 1.5 0 0 1 1.465 1.175l.716 3.223a1.5 1.5 0 0 1-1.052 1.767l-.933.267c-.41.117-.643.555-.48.95a11.542 11.542 0 0 0 6.254 6.254c.395.163.833-.07.95-.48l.267-.933a1.5 1.5 0 0 1 1.767-1.052l3.223.716A1.5 1.5 0 0 1 18 15.352V16.5a1.5 1.5 0 0 1-1.5 1.5H15c-1.149 0-2.263-.15-3.326-.43A13.022 13.022 0 0 1 2.43 8.326 13.019 13.019 0 0 1 2 5V3.5Z" clip-rule="evenodd"/></svg>
                    {{ __('app.fields.contact') }}
                </label>
                @include('checkin.partials.phone-field', ['name' => 'contact', 'id' => 'contact', 'required' => true])
            </div>

            <div class="form-group">
                <label class="form-label form-label--required" for="government_id">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M1 6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H4a3 3 0 0 1-3-3V6Zm4 1.5a2 2 0 1 1 4 0 2 2 0 0 1-4 0Zm2 3a4 4 0 0 0-3.665 2.395.75.75 0 0 0 .416 1A8.98 8.98 0 0 0 7 14.5a8.98 8.98 0 0 0 3.249-.604.75.75 0 0 0 .416-1.001A4.001 4.001 0 0 0 7 10.5Zm5-3.75a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Zm0 6.5a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Zm.75-4a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5h-2.5Z" clip-rule="evenodd"/></svg>
                    {{ __('app.fields.government_id') }}
                </label>
                <input type="text" name="government_id" id="government_id" class="form-input"
                       placeholder="{{ __('app.placeholders.government_id') }}"
                       required>
            </div>

            <div class="form-group">
                <label class="form-label" for="email">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a2 2 0 0 0-2 2v1.161l8.441 4.221a1.25 1.25 0 0 0 1.118 0L19 7.162V6a2 2 0 0 0-2-2H3Z"/><path d="m19 8.839-7.77 3.885a2.75 2.75 0 0 1-2.46 0L1 8.839V14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.839Z"/></svg>
                    {{ __('app.fields.email') }}
                </label>
                <input type="email" name="email" id="email" class="form-input"
                       placeholder="{{ __('app.placeholders.example_email') }}"
                       autocomplete="email">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label form-label--required" for="gender">
                        <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M7 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM14.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM1.615 16.428a1.224 1.224 0 0 1-.569-1.175 6.002 6.002 0 0 1 11.908 0c.058.467-.172.92-.57 1.174A9.953 9.953 0 0 1 7 18a9.953 9.953 0 0 1-5.385-1.572ZM14.5 16h-.106c.07-.297.088-.611.048-.933a7.47 7.47 0 0 0-1.588-3.755 4.502 4.502 0 0 1 5.874 2.636.818.818 0 0 1-.36.98A7.465 7.465 0 0 1 14.5 16Z"/></svg>
                        {{ __('app.fields.gender') }}
                    </label>
                    <select name="gender" id="gender" class="form-select" required>
                        <option value="male">{{ __('app.options.gender.male') }}</option>
                        <option value="female">{{ __('app.options.gender.female') }}</option>
                        <option value="other">{{ __('app.options.gender.other') }}</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label form-label--required" for="dob">
                        <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z" clip-rule="evenodd"/></svg>
                        {{ __('app.fields.dob') }}
                    </label>
                    <input type="date" name="dob" id="dob" class="form-input" required min="1900-01-01" max="3000-01-01">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="goal">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" clip-rule="evenodd"/></svg>
                    {{ __('app.fields.goal') }}
                </label>
                <select name="goal" id="goal" class="form-select">
                    <option value="fitness">{{ __('app.options.goal.fitness') }}</option>
                    <option value="body_building">{{ __('app.options.goal.body_building') }}</option>
                    <option value="fatloss">{{ __('app.options.goal.fatloss') }}</option>
                    <option value="weightgain">{{ __('app.options.goal.weightgain') }}</option>
                    <option value="others">{{ __('app.options.goal.others') }}</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="emergency_contact">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 3.5A1.5 1.5 0 0 1 3.5 2h1.148a1.5 1.5 0 0 1 1.465 1.175l.716 3.223a1.5 1.5 0 0 1-1.052 1.767l-.933.267c-.41.117-.643.555-.48.95a11.542 11.542 0 0 0 6.254 6.254c.395.163.833-.07.95-.48l.267-.933a1.5 1.5 0 0 1 1.767-1.052l3.223.716A1.5 1.5 0 0 1 18 15.352V16.5a1.5 1.5 0 0 1-1.5 1.5H15c-1.149 0-2.263-.15-3.326-.43A13.022 13.022 0 0 1 2.43 8.326 13.019 13.019 0 0 1 2 5V3.5Z" clip-rule="evenodd"/></svg>
                    {{ __('app.fields.emergency_contact') }}
                </label>
                @include('checkin.partials.phone-field', ['name' => 'emergency_contact', 'id' => 'emergency_contact'])
            </div>

            <div class="form-group">
                <label class="form-label" for="health_issue">
                    <svg class="form-label-icon" viewBox="0 0 20 20" fill="currentColor"><path d="m9.653 16.915-.005-.003-.019-.01a20.759 20.759 0 0 1-1.162-.682 22.045 22.045 0 0 1-2.582-1.9C4.045 12.733 2 10.352 2 7.5a4.5 4.5 0 0 1 8-2.828A4.5 4.5 0 0 1 18 7.5c0 2.852-2.044 5.233-3.885 6.82a22.049 22.049 0 0 1-3.744 2.582l-.019.01-.005.003h-.002a.739.739 0 0 1-.69.001l-.002-.001Z"/></svg>
                    {{ __('app.fields.health_issues') }}
                </label>
                <input type="text" name="health_issue" id="health_issue" class="form-input"
                       placeholder="{{ __('app.placeholders.health_issues') }}">
            </div>
            @endif

            <button type="submit" class="btn btn-primary">
                <span class="btn-text">{{ $kind === 'checkin' ? __('app.scan.action_checkin') : __('app.scan.action_signup') }}</span>
                <span class="loading"><span class="spinner"></span>{{ __('app.scan.processing') }}</span>
            </button>
        </form>

        @if ($kind === 'signup')
        <p class="signup-note">{{ __('app.scan.signup_member_note') }}</p>
        @endif
    </div>
    </div>

    @include('checkin.partials.public-footer')

    <script>
        const form = document.getElementById('scan-form');
        const errorDiv = document.getElementById('error-message');
        const submitBtn = form.querySelector('button[type="submit"]');
        const kind = @json($kind);
        let checkinFailCount = 0;
        let checkinFirstFailAt = 0;
        const CHECKIN_FAIL_WINDOW_MS = 30000;

        @php
            $scanMessages = [
                'no_member_found' => __('app.scan.no_member_found'),
                'something_went_wrong' => __('app.scan.something_went_wrong'),
            ];
        @endphp
        const messages = @json($scanMessages);

        document.getElementById('locale-switcher').addEventListener('change', (e) => {
            const url = new URL(window.location.href);
            url.searchParams.set('locale', e.target.value);
            window.location.href = url.toString();
        });

        const identifierType = document.getElementById('identifier_type');
        const valueInput = document.getElementById('value');
        const valueTextField = document.getElementById('value-text');
        const valuePhoneField = document.getElementById('value-phone-field');
        if (identifierType && valueInput) {
            @php
                $scanPlaceholders = [
                    'contact' => \App\Helpers\Helpers::getPhoneLocalPlaceholder(),
                    'code' => __('app.placeholders.member_code'),
                    'government_id' => __('app.placeholders.government_id'),
                ];
            @endphp
            const placeholders = @json($scanPlaceholders);

            const icons = {
                contact: '<path fill-rule="evenodd" d="M2 3.5A1.5 1.5 0 0 1 3.5 2h1.148a1.5 1.5 0 0 1 1.465 1.175l.716 3.223a1.5 1.5 0 0 1-1.052 1.767l-.933.267c-.41.117-.643.555-.48.95a11.542 11.542 0 0 0 6.254 6.254c.395.163.833-.07.95-.48l.267-.933a1.5 1.5 0 0 1 1.767-1.052l3.223.716A1.5 1.5 0 0 1 18 15.352V16.5a1.5 1.5 0 0 1-1.5 1.5H15c-1.149 0-2.263-.15-3.326-.43A13.022 13.022 0 0 1 2.43 8.326 13.019 13.019 0 0 1 2 5V3.5Z" clip-rule="evenodd"/>',
                government_id: '<path fill-rule="evenodd" d="M1 6a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H4a3 3 0 0 1-3-3V6Zm4 1.5a2 2 0 1 1 4 0 2 2 0 0 1-4 0Zm2 3a4 4 0 0 0-3.665 2.395.75.75 0 0 0 .416 1A8.98 8.98 0 0 0 7 14.5a8.98 8.98 0 0 0 3.249-.604.75.75 0 0 0 .416-1.001A4.001 4.001 0 0 0 7 10.5Zm5-3.75a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Zm0 6.5a.75.75 0 0 1 .75-.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1-.75-.75Zm.75-4a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5h-2.5Z" clip-rule="evenodd"/>',
                code: '<path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 0 0 2 4.25v2.5A2.25 2.25 0 0 0 4.25 9h2.5A2.25 2.25 0 0 0 9 6.75v-2.5A2.25 2.25 0 0 0 6.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 2 13.25v2.5A2.25 2.25 0 0 0 4.25 18h2.5A2.25 2.25 0 0 0 9 15.75v-2.5A2.25 2.25 0 0 0 6.75 11h-2.5Zm9-9A2.25 2.25 0 0 0 11 4.25v2.5A2.25 2.25 0 0 0 13.25 9h2.5A2.25 2.25 0 0 0 18 6.75v-2.5A2.25 2.25 0 0 0 15.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 11 13.25v2.5A2.25 2.25 0 0 0 13.25 18h2.5A2.25 2.25 0 0 0 18 15.75v-2.5A2.25 2.25 0 0 0 15.75 11h-2.5Z" clip-rule="evenodd"/>',
            };

            const syncInputMode = () => {
                const isContact = identifierType.value === 'contact';
                valuePhoneField.style.display = isContact ? '' : 'none';
                valueTextField.style.display = isContact ? 'none' : '';

                valueInput.name = isContact ? 'value' : '';
                valueInput.required = isContact;
                valueTextField.name = isContact ? '' : 'value';
                valueTextField.required = !isContact;

                valueTextField.placeholder = placeholders[identifierType.value] || '';

                const valueIcon = document.getElementById('value-icon');
                valueIcon.innerHTML = icons[identifierType.value];
            };
            identifierType.addEventListener('change', syncInputMode);
            syncInputMode();

            // Navigating back (e.g. from an expired queue) restores the last
            // selected identifier type without firing `change`, so the input
            // mode must be re-synced every time the page is shown.
            window.addEventListener('pageshow', syncInputMode);
        }

        const prefixPhone = (input, dialCode) => {
            const raw = (input.value || '').trim();
            if (raw === '') {
                return raw;
            }
            return /^\+/.test(raw) ? raw : dialCode + ' ' + raw;
        };

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            errorDiv.style.display = 'none';
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            const formData = new FormData(form);

            const dialValue = document.getElementById('dial-value');
            if (identifierType && identifierType.value === 'contact' && dialValue) {
                formData.set('value', prefixPhone(valueInput, dialValue.value));
            } else if (identifierType && valueTextField) {
                formData.set('value', valueTextField.value.trim());
            }

            const dialContact = document.getElementById('dial-contact');
            if (dialContact) {
                formData.set('contact', prefixPhone(document.getElementById('contact'), dialContact.value));
            }

            const dialEmergency = document.getElementById('dial-emergency_contact');
            if (dialEmergency) {
                const emergencyInput = document.getElementById('emergency_contact');
                formData.set('emergency_contact', prefixPhone(emergencyInput, dialEmergency.value));
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const data = await response.json();

                if (!response.ok) {
                    if (data.errors) {
                        const firstError = Object.values(data.errors)[0];
                        throw new Error(Array.isArray(firstError) ? firstError[0] : messages.something_went_wrong);
                    }
                    throw new Error(data.message || messages.something_went_wrong);
                }

                if (data.match === false) {
                    if (kind === 'checkin') {
                        const now = Date.now();
                        if (checkinFirstFailAt && (now - checkinFirstFailAt > CHECKIN_FAIL_WINDOW_MS)) {
                            checkinFailCount = 0;
                            checkinFirstFailAt = 0;
                        }
                        if (!checkinFirstFailAt) checkinFirstFailAt = now;
                        checkinFailCount++;
                        if (checkinFailCount >= 3) {
                            const locale = new URLSearchParams(window.location.search).get('locale');
                            const params = new URLSearchParams();
                            if (locale) params.set('locale', locale);
                            params.set('theme_color', @json($themeColor));
                            window.location.href = `/contact-front-desk?${params.toString()}`;
                            return;
                        }
                    }
                    errorDiv.textContent = data.message || messages.no_member_found;
                    errorDiv.style.display = 'block';
                    return;
                }

                if (data.queue_entry_uuid) {
                    checkinFailCount = 0;
                    checkinFirstFailAt = 0;
                    const pendingKey = kind === 'signup' ? 'pendingSignupUuid' : 'pendingCheckinUuid';
                    localStorage.setItem(pendingKey, data.queue_entry_uuid);
                    const locale = new URLSearchParams(window.location.search).get('locale');
                    const localeParam = locale ? `&locale=${encodeURIComponent(locale)}` : '';
                    window.location.href = `/waiting/${data.queue_entry_uuid}?token=${encodeURIComponent(formData.get('kind'))}${localeParam}`;
                }
            } catch (err) {
                errorDiv.textContent = err.message;
                errorDiv.style.display = 'block';
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.ThemeLive && window.ThemeLive.start(@js([$token]));
        });
    </script>
    <script>
        window.addEventListener('pageshow', () => {
            const kind = @json($kind);
            const pendingKey = kind === 'signup' ? 'pendingSignupUuid' : 'pendingCheckinUuid';
            const pendingUuid = localStorage.getItem(pendingKey);
            if (pendingUuid) {
                localStorage.removeItem(pendingKey);
                window.location.replace(`/waiting/${pendingUuid}`);
            }
        });
    </script>
</body>
</html>
