<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if ((bool) config('gymie-tenancy.enabled', false)) {
    
    Schedule::command('gymie:tenants:subscriptions')
        ->name('gymie-tenants-subscriptions')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->dailyAt('00:00');

    
    Schedule::command('gymie:tenants:invoices --mark-overdue')
        ->name('gymie-tenants-invoices-overdue')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->dailyAt('00:00');
} else {
    
    Schedule::command('gymie:subscriptions')
        ->name('gymie-subscriptions')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->dailyAt('00:00');

    
    Schedule::command('gymie:invoices --mark-overdue')
        ->name('gymie-invoices-overdue')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->dailyAt('00:00');
}
