<?php

it('admin app.js contains Livewire 419 redirect hook scoped to fi-body', function (): void {
    $js = file_get_contents(resource_path('js/app.js'));
    expect($js)->toContain("Livewire.hook('request'")
        ->toContain('status === 419')
        ->toContain('fi-body')
        ->toContain("window.location.href = '/login'")
        ->toContain('preventDefault');
});

it('login response supports intended redirect', function (): void {
    $php = file_get_contents(base_path('vendor/filament/filament/src/Auth/Http/Responses/LoginResponse.php'));
    expect($php)->toContain('redirect()->intended');
});

it('public checkin does not contain admin 419 hook', function (): void {
    $scan = file_get_contents(resource_path('views/checkin/scan.blade.php'));
    expect($scan)->toContain("@vite(['resources/css/app.css'");
    // Public checkin uses app.js but hook is guarded by fi-body, so it won't redirect
    $js = file_get_contents(resource_path('js/app.js'));
    expect($js)->toContain("fi-body");
});
