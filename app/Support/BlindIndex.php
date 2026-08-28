<?php

namespace App\Support;

final class BlindIndex
{
    private function __construct() {}

    

    public static function compute(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
