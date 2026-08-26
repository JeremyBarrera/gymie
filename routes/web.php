<?php

use App\Http\Controllers\AdminLocaleController;
use App\Http\Controllers\AdminThemeController;
use App\Http\Controllers\CheckInScanController;
use App\Http\Controllers\InvoiceDocumentController;
use App\Http\Controllers\QrCodeDownloadController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:scan-page')->group(function (): void {
    Route::get('/checkin/{token}', [CheckInScanController::class, 'showCheckIn'])
        ->name('checkin.scan');

    Route::get('/signup/{token}', [CheckInScanController::class, 'showSignUp'])
        ->name('signup.scan')
        ->middleware('feature:api.signup.apply');

    Route::get('/waiting/{uuid}', [CheckInScanController::class, 'waiting'])
        ->name('checkin.waiting');

    Route::get('/contact-front-desk', [CheckInScanController::class, 'contactFrontDesk'])
        ->name('checkin.contact-front-desk');
});

Route::post('/checkin/submit', [CheckInScanController::class, 'submit'])
    ->name('checkin.submit')
    ->middleware('throttle:scan-submit');

Route::middleware([Authenticate::class])
    ->group(function (): void {
        Route::get('/invoices/{invoice}/preview', [InvoiceDocumentController::class, 'preview'])
            ->name('invoices.preview');

        Route::get('/invoices/{invoice}/download', [InvoiceDocumentController::class, 'download'])
            ->name('invoices.download');

        Route::get('/qr-codes/download', QrCodeDownloadController::class)
            ->name('qr-codes.download');

        Route::post('/admin/theme', AdminThemeController::class)
            ->name('admin.theme.update');

        Route::post('/admin/locale', AdminLocaleController::class)
            ->name('admin.locale.update');
    });
