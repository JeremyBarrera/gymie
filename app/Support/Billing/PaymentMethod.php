<?php

namespace App\Support\Billing;

final class PaymentMethod
{
    

    public static function normalize(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return $value;
    }

    

    public static function isOnline(?string $value): bool
    {
        $value = self::normalize($value);

        return in_array($value, ['online', 'stripe'], true);
    }

    

    public static function channelLabel(?string $value): string
    {
        $method = self::normalize($value);

        if ($method === '') {
            return __('app.placeholders.dash');
        }

        return self::options()[$method] ?? ucfirst($method);
    }

    

    public static function options(): array
    {
        return [
            'cash' => __('app.payment_methods.cash'),
            'card' => __('app.payment_methods.card'),
            'online' => __('app.payment_methods.online'),
        ];
    }
}
