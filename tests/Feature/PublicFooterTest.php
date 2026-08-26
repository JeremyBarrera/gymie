<?php

use App\Models\Location;
use App\Support\ThemeColor;

it('all 5 locale files contain app.legal keys with placeholders', function (): void {
    $locales = ['en', 'ar', 'es', 'fa', 'fr'];
    $requiredKeys = [
        'rights_reserved',
        'copyright',
        'agree_prefix',
        'terms_link',
        'modal_title',
        'modal_intro',
        'item_data',
        'item_purpose',
        'item_retention',
        'item_security',
        'item_consent',
        'close',
    ];

    foreach ($locales as $locale) {
        $path = base_path("resources/lang/{$locale}/app.php");
        expect(file_exists($path))->toBeTrue("Missing locale file: {$locale}/app.php");

        $translations = require $path;

        expect($translations)->toHaveKey('legal');

        foreach ($requiredKeys as $key) {
            expect($translations['legal'])->toHaveKey($key);
            expect($translations['legal'][$key])->not->toBeEmpty();
        }

        expect($translations['legal']['copyright'])->toContain(':year');
        expect($translations['legal']['copyright'])->toContain(':name');
        expect($translations['legal']['modal_intro'])->toContain(':name');
    }
});

it('public-footer partial is location-aware and uses i18n with dynamic name not hardcoded', function (): void {
    $path = resource_path('views/checkin/partials/public-footer.blade.php');
    $content = file_get_contents($path);

    expect($content)->toContain('$footerName = $footerName ?? ($location->name ?? config(');
    expect($content)->toContain("__('app.legal.copyright'");
    expect($content)->toContain("'name' => \$footerName");
    expect($content)->toContain("__('app.legal.modal_intro'");
    expect($content)->toContain("__('app.legal.terms_link')");
    expect($content)->toContain("__('app.legal.rights_reserved')");
    expect($content)->toContain("__('app.legal.agree_prefix')");
    expect($content)->toContain('data-terms-open');
    expect($content)->toContain('public-terms-modal');
    expect($content)->toContain('public-footer__link');
    expect($content)->not->toContain('Toro GYM');
    expect($content)->not->toContain('Toro');

    // Styling compliance: uses var(--c-*) and not raw Tailwind color utilities
    expect($content)->toContain('var(--c-text-muted)');
    expect($content)->toContain('var(--c-base)');
    expect($content)->toContain('var(--c-surface)');
    expect($content)->not->toContain('bg-gray-');
    expect($content)->not->toContain('text-gray-');
    expect($content)->not->toContain('#fff');
    expect($content)->not->toContain('#f9fafb');

    // RTL aware: flex-wrap handles it, html dir already set on pages
    expect($content)->toContain('flex-wrap: wrap');
});

it('scan blade has public-main wrapper and footer include with correct body layout', function (): void {
    $content = file_get_contents(resource_path('views/checkin/scan.blade.php'));

    expect($content)->toContain('class="public-main"');
    expect($content)->toContain("@include('checkin.partials.public-footer')");
    expect($content)->toContain('html {');
    expect($content)->toContain('background-color: var(--c-bg-b);');
    expect($content)->toContain('flex-direction: column');
    expect($content)->toContain('align-items: center');

    $mainPos = strpos($content, 'class="public-main"');
    $footerPos = strpos($content, "@include('checkin.partials.public-footer')");
    expect($footerPos)->toBeGreaterThan($mainPos);
});

it('waiting blade has public-main wrapper, footer before result-overlay outside', function (): void {
    $content = file_get_contents(resource_path('views/checkin/waiting.blade.php'));

    expect($content)->toContain('class="public-main"');
    expect($content)->toContain("@include('checkin.partials.public-footer')");
    expect($content)->toContain('id="result-overlay"');
    expect($content)->toContain('html {');
    expect($content)->toContain('background-color: var(--c-bg-b);');
    expect($content)->toContain('flex-direction: column');

    $mainPos = strpos($content, 'class="public-main"');
    $footerPos = strpos($content, "@include('checkin.partials.public-footer')");
    $overlayPos = strpos($content, 'id="result-overlay"');

    expect($footerPos)->toBeGreaterThan($mainPos);
    expect($overlayPos)->toBeGreaterThan($footerPos, 'result-overlay should be outside public-main after footer');
});

it('invalid-token blade wraps card in public-main and includes footer with column layout', function (): void {
    $content = file_get_contents(resource_path('views/checkin/invalid-token.blade.php'));

    expect($content)->toContain('class="public-main"');
    expect($content)->toContain("@include('checkin.partials.public-footer')");
    expect($content)->toContain('flex-direction: column');
    expect($content)->toContain('align-items: center');
    expect($content)->toContain('html {');
    expect($content)->toContain('background-color: var(--c-bg-b);');

    $mainPos = strpos($content, 'class="public-main"');
    $contactPos = strpos($content, 'checkin.partials.contact-front-desk');
    $footerPos = strpos($content, 'checkin.partials.public-footer');

    expect($contactPos)->toBeGreaterThan($mainPos);
    expect($footerPos)->toBeGreaterThan($contactPos);

    // body should use column layout, not centered justify (footer styles may still contain justify)
    expect($content)->toContain('display: flex');
    expect(substr_count($content, 'flex-direction: column'))->toBeGreaterThan(0);
});

it('contact-front-desk blade wraps card in public-main and includes footer with column layout', function (): void {
    $content = file_get_contents(resource_path('views/checkin/contact-front-desk.blade.php'));

    expect($content)->toContain('class="public-main"');
    expect($content)->toContain("@include('checkin.partials.public-footer')");
    expect($content)->toContain('flex-direction: column');
    expect($content)->toContain('align-items: center');
    expect($content)->toContain('html {');
    expect($content)->toContain('background-color: var(--c-bg-b);');

    $mainPos = strpos($content, 'class="public-main"');
    $contactPos = strpos($content, 'checkin.partials.contact-front-desk');
    $footerPos = strpos($content, 'checkin.partials.public-footer');

    expect($contactPos)->toBeGreaterThan($mainPos);
    expect($footerPos)->toBeGreaterThan($contactPos);
});

it('invalid-token page renders footer with terms link and dynamic copyright via view', function (): void {
    $html = view('checkin.invalid-token', ['background' => '#2563eb', 'accent' => '#2563eb'])->render();

    expect($html)->toContain(__('app.legal.terms_link'));
    expect($html)->toContain(__('app.legal.rights_reserved'));
    expect($html)->toContain('public-footer');
    expect($html)->toContain('public-terms-modal');
    expect($html)->toContain('data-terms-open');
    expect($html)->toContain(config('app.name'));
});

it('contact-front-desk page renders footer with terms link and dynamic copyright via view', function (): void {
    $html = view('checkin.contact-front-desk', ['background' => '#2563eb', 'accent' => '#2563eb'])->render();

    expect($html)->toContain(__('app.legal.terms_link'));
    expect($html)->toContain(__('app.legal.rights_reserved'));
    expect($html)->toContain('public-footer');
    expect($html)->toContain('public-terms-modal');
    expect($html)->toContain('data-terms-open');
    expect($html)->toContain(config('app.name'));
});

it('scan view renders footer with dynamic location name not hardcoded', function (): void {
    $location = Location::factory()->make(['name' => 'Acme Fitness Hub']);
    $themeColor = '#2563eb';
    $palette = ThemeColor::from($themeColor)->palette();

    $html = view('checkin.scan', [
        'location' => $location,
        'token' => 'test-token',
        'kind' => 'checkin',
        'themeColor' => $themeColor,
        'palette' => $palette,
        'background' => $themeColor,
        'accent' => $themeColor,
    ])->render();

    expect($html)->toContain('Acme Fitness Hub');
    expect($html)->toContain(__('app.legal.terms_link'));
    expect($html)->toContain(__('app.legal.rights_reserved'));
    expect($html)->toContain('public-footer');
    expect($html)->toContain('public-terms-modal');
    expect($html)->not->toContain('Toro GYM');
    expect($html)->toContain(date('Y'));
});

it('waiting view renders footer with dynamic location name not hardcoded', function (): void {
    $location = Location::factory()->make(['name' => 'Sunrise Gym West']);
    $themeColor = '#4338ca';
    $palette = ThemeColor::from($themeColor)->palette();

    $html = view('checkin.waiting', [
        'queueEntry' => (object) ['uuid' => 'test-uuid-waiting'],
        'location' => $location,
        'themeColor' => $themeColor,
        'palette' => $palette,
        'uuid' => 'test-uuid-waiting',
        'kind' => 'checkin',
        'background' => $themeColor,
        'accent' => $themeColor,
        'locationToken' => 'tok123',
        'memberName' => null,
        'signupToken' => null,
    ])->render();

    expect($html)->toContain('Sunrise Gym West');
    expect($html)->toContain(__('app.legal.terms_link'));
    expect($html)->toContain('public-footer');
    expect($html)->toContain('public-terms-modal');
    expect($html)->not->toContain('Toro GYM');
});
