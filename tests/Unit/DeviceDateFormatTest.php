<?php

use App\Support\Dates\DeviceDateFormat;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    DeviceDateFormat::setTestConsoleOverride(null);
});

function bindRequest(array $cookies = [], array $headers = []): void
{
    app()->instance('request', Request::create('/', 'GET', [], $cookies, [], $headers));
}

test('date order comes from the device cookie', function (string $order, string $format): void {
    DeviceDateFormat::setTestConsoleOverride(false);
    bindRequest(['gymie_date_order' => $order]);

    expect(DeviceDateFormat::date())->toBe($format);
})->with([
    ['mdy', 'm/d/Y'],
    ['dmy', 'd/m/Y'],
    ['ymd', 'Y/m/d'],
]);

test('clock convention comes from the device cookie', function (string $hour12, string $format): void {
    DeviceDateFormat::setTestConsoleOverride(false);
    bindRequest(['gymie_hour12' => $hour12]);

    expect(DeviceDateFormat::time())->toBe($format);
})->with([
    ['1', 'h:i A'],
    ['0', 'H:i'],
]);

test('date order falls back to the Accept-Language header when no cookie exists', function (string $header, string $expectedOrder): void {
    DeviceDateFormat::setTestConsoleOverride(false);
    bindRequest([], ['HTTP_ACCEPT_LANGUAGE' => $header]);

    expect(DeviceDateFormat::order())->toBe($expectedOrder);
})->with([
    ['en-US,en;q=0.9', 'mdy'],
    ['en-GB,en;q=0.9', 'dmy'],
    ['fr-FR,fr;q=0.9', 'dmy'],
    ['fa-IR,fa;q=0.9', 'ymd'],
]);

test('12h clock fallback respects the region', function (string $header, bool $expected): void {
    DeviceDateFormat::setTestConsoleOverride(false);
    bindRequest([], ['HTTP_ACCEPT_LANGUAGE' => $header]);

    expect(DeviceDateFormat::hour12())->toBe($expected);
})->with([
    ['en-US,en;q=0.9', true],
    ['es-ES,es;q=0.9', false],
    ['es-MX,es;q=0.9', true],
    ['fr-FR,fr;q=0.9', false],
    ['ar-SA,ar;q=0.9', false],
]);

test('date order falls back to the app locale when no request context exists', function (): void {
    app()->setLocale('en');

    expect(DeviceDateFormat::date())->toBe('d/m/Y');
});

test('invalid cookie values are ignored', function (): void {
    DeviceDateFormat::setTestConsoleOverride(false);
    bindRequest(
        ['gymie_date_order' => 'junk', 'gymie_hour12' => 'junk'],
        ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9'],
    );

    expect(DeviceDateFormat::order())->toBe('dmy')
        ->and(DeviceDateFormat::hour12())->toBeFalse();
});

test('format renders dates and times in the device convention', function (): void {
    DeviceDateFormat::setTestConsoleOverride(false);
    bindRequest(['gymie_date_order' => 'mdy', 'gymie_hour12' => '0']);

    $date = Carbon::parse('2026-05-03 14:05:00');

    expect(DeviceDateFormat::format($date))->toBe('05/03/2026')
        ->and(DeviceDateFormat::formatTime($date))->toBe('14:05')
        ->and(DeviceDateFormat::formatDateTime($date))->toBe('05/03/2026 14:05');
});

test('format falls back to a dash for null dates', function (): void {
    DeviceDateFormat::setTestConsoleOverride(false);
    bindRequest(['gymie_date_order' => 'dmy']);

    expect(DeviceDateFormat::format(null))->toBe('—')
        ->and(DeviceDateFormat::formatTime(null))->toBe('—')
        ->and(DeviceDateFormat::formatDateTime(null))->toBe('—');
});
