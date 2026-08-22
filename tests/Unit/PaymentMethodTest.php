<?php

use App\Support\Billing\PaymentMethod;

it('normalizes and classifies payment methods', function (): void {
    expect(PaymentMethod::normalize(' Stripe '))->toBe('stripe');
    expect(PaymentMethod::isOnline('stripe'))->toBeTrue();
    expect(PaymentMethod::isOnline('online'))->toBeTrue();
    expect(PaymentMethod::isOnline('cash'))->toBeFalse();
    expect(PaymentMethod::channelLabel('online'))->toBe(__('app.payment_methods.online'))
        ->and(PaymentMethod::channelLabel('cash'))->toBe(__('app.payment_methods.cash'))
        ->and(PaymentMethod::channelLabel('card'))->toBe(__('app.payment_methods.card'))
        ->and(PaymentMethod::channelLabel(null))->toBe(__('app.placeholders.dash'))
        ->and(PaymentMethod::channelLabel('upi'))->toBe('Upi');
});

it('exposes stable payment method options for forms', function (): void {
    expect(PaymentMethod::options())->toMatchArray([
        'cash' => __('app.payment_methods.cash'),
        'card' => __('app.payment_methods.card'),
        'online' => __('app.payment_methods.online'),
    ]);
});
