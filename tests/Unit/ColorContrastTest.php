<?php

use App\Support\ColorContrast;

it('normalizes hex shorthand and uppercase', function (): void {
    expect(ColorContrast::normalize('FFF'))->toBe('#ffffff')
        ->and(ColorContrast::normalize('#ABC'))->toBe('#aabbcc')
        ->and(ColorContrast::normalize('112233'))->toBe('#112233')
        ->and(ColorContrast::normalize('#aaBBcc'))->toBe('#aabbcc');
});

it('mixes two colours at a given ratio', function (): void {
    expect(ColorContrast::mix('#000000', '#ffffff', 0.5))->toBe('#808080')
        ->and(ColorContrast::mix('#ff0000', '#0000ff', 0.0))->toBe('#ff0000')
        ->and(ColorContrast::mix('#ff0000', '#0000ff', 1.0))->toBe('#0000ff');
});

it('computes WCAG relative luminance within expected bounds', function (): void {
    expect(ColorContrast::relativeLuminance('#000000'))->toBeGreaterThanOrEqual(0)
        ->and(ColorContrast::relativeLuminance('#000000'))->toBeLessThanOrEqual(0.001)
        ->and(ColorContrast::relativeLuminance('#ffffff'))->toBeGreaterThanOrEqual(0.999)
        ->and(ColorContrast::relativeLuminance('#ffffff'))->toBeLessThanOrEqual(1.001)
        ->and(ColorContrast::relativeLuminance('#808080'))->toBeGreaterThan(0.1)
        ->and(ColorContrast::relativeLuminance('#808080'))->toBeLessThan(0.3);
});

it('returns contrast ratio between 1.0 and 21.0', function (): void {
    expect(ColorContrast::ratio('#000000', '#000000'))->toBeGreaterThanOrEqual(0.99)
        ->and(ColorContrast::ratio('#000000', '#000000'))->toBeLessThanOrEqual(1.01)
        ->and(ColorContrast::ratio('#ffffff', '#000000'))->toBeGreaterThanOrEqual(20.99)
        ->and(ColorContrast::ratio('#ffffff', '#000000'))->toBeLessThanOrEqual(21.01)
        ->and(ColorContrast::ratio('#ffffff', '#ffffff'))->toBeGreaterThanOrEqual(0.99)
        ->and(ColorContrast::ratio('#ffffff', '#ffffff'))->toBeLessThanOrEqual(1.01);
});

it('picks white on dark backgrounds via readableOn', function (): void {
    $fg = ColorContrast::readableOn('#1e293b');

    expect($fg)->toBe('#ffffff')
        ->and(ColorContrast::ratio($fg, '#1e293b'))->toBeGreaterThanOrEqual(4.5);
});

it('picks dark on light backgrounds via readableOn', function (): void {
    $fg = ColorContrast::readableOn('#f8fafc');

    expect($fg)->toBe('#0f172a')
        ->and(ColorContrast::ratio($fg, '#f8fafc'))->toBeGreaterThanOrEqual(4.5);
});

it('returns a readable foreground for common brand colours via readableOn', function (): void {
    $surfaces = ['#2563eb', '#dc2626', '#16a34a', '#f59e0b', '#000000', '#ffffff'];

    foreach ($surfaces as $bg) {
        $fg = ColorContrast::readableOn($bg);
        expect(ColorContrast::ratio($fg, $bg))->toBeGreaterThanOrEqual(4.5);
    }
});

it('returns muted foreground above AA threshold via mutedOn', function (): void {
    $surfaces = ['#2563eb', '#dc2626', '#16a34a', '#f59e0b', '#000000', '#ffffff'];

    foreach ($surfaces as $bg) {
        $muted = ColorContrast::mutedOn($bg);
        expect(ColorContrast::ratio($muted, $bg))->toBeGreaterThanOrEqual(4.5);
    }
});

it('adjusts a low-contrast foreground via ensureContrast', function (): void {
    $bg = '#ffffff';
    $fg = '#dddddd';

    expect(ColorContrast::ratio($fg, $bg))->toBeLessThan(4.5);

    $safe = ColorContrast::ensureContrast($fg, $bg);
    expect(ColorContrast::ratio($safe, $bg))->toBeGreaterThanOrEqual(4.5);
});

it('returns foreground unchanged when it already meets contrast', function (): void {
    expect(ColorContrast::ensureContrast('#0f172a', '#ffffff'))->toBe('#0f172a');
});

it('resolves mid-luminance accent via ensureContrast', function (): void {
    $safe = ColorContrast::ensureContrast('#ffffff', '#8b5cf6');

    expect(ColorContrast::ratio($safe, '#8b5cf6'))->toBeGreaterThanOrEqual(4.5);
});

it('derives a palette where every text-surface pairing clears AA', function (): void {
    $backgrounds = ['#2563eb', '#dc2626', '#16a34a', '#f59e0b', '#000000', '#ffffff', '#8b5cf6'];
    $accents = ['#3b82f6', '#ef4444', '#22c55e', '#eab308', '#ffffff', '#000000'];

    foreach ($backgrounds as $bg) {
        foreach ($accents as $accent) {
            $palette = ColorContrast::derivePalette($bg, $accent);

            expect(ColorContrast::ratio($palette['text'], $palette['surface']))->toBeGreaterThanOrEqual(4.5);
            expect(ColorContrast::ratio($palette['textMuted'], $palette['surface']))->toBeGreaterThanOrEqual(4.5);
            expect(ColorContrast::ratio($palette['onBase'], $palette['base']))->toBeGreaterThanOrEqual(4.5);
        }
    }
});

it('returns all expected keys in derivePalette', function (): void {
    $palette = ColorContrast::derivePalette('#2563eb', '#3b82f6');

    expect($palette)->toHaveKeys([
        'base', 'hover', 'active', 'onBase', 'bgA', 'bgB',
        'surface', 'border', 'ring', 'text', 'textMuted',
        'success', 'successBg', 'successBorder', 'successStrong', 'successIconBg',
        'danger', 'dangerBg', 'dangerBorder', 'dangerStrong', 'dangerIconBg',
    ]);
});

it('produces distinct dark-mode vs light-mode palettes', function (): void {
    $light = ColorContrast::derivePalette('#f0f0f0', '#3b82f6');
    $dark = ColorContrast::derivePalette('#0f172a', '#3b82f6');

    expect($light['surface'])->not->toBe($dark['surface']);
    expect($light['danger'])->not->toBe($dark['danger']);
});
