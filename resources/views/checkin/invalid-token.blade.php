<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'fa'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ \App\Support\ColorContrast::derivePalette($background, $accent)['bgB'] }}">
    <title>{{ __('app.scan.invalid_title') }}</title>
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
            padding: calc(20px + env(safe-area-inset-top, 0px)) 20px calc(20px + env(safe-area-inset-bottom, 0px)) 20px;
        }
        .contact-container {
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
            .contact-container {
                box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            }
        }
        .contact-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--c-ring);
        }
        .contact-icon svg {
            width: 32px;
            height: 32px;
            color: var(--c-base);
        }
        .contact-heading {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--c-text);
            margin: 0 0 8px;
        }
        .contact-body {
            color: var(--c-text-muted);
            font-size: 0.95rem;
            line-height: 1.5;
            margin: 0;
        }
        .contact-info {
            margin-top: 24px;
            padding: 12px 16px;
            border-radius: 10px;
            background: var(--c-bg-a);
            border: 1px solid var(--c-border);
            color: var(--c-text);
            font-size: 0.875rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="public-main">
    @include('checkin.partials.contact-front-desk', [
        'heading' => __('app.scan.invalid_heading'),
        'body' => __('app.scan.invalid_body'),
        'contact' => __('app.scan.invalid_contact'),
    ])
    </div>

    @include('checkin.partials.public-footer')

</body>
</html>
