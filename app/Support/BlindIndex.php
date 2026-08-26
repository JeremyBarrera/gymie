<?php

namespace App\Support;

/**
 * Deterministic keyed hash (blind index) for searching encrypted columns.
 *
 * Laravel's `encrypted` cast uses a random nonce, so ciphertext equality
 * cannot be queried. Columns that need exact-match lookups keep a companion
 * `<column>_hash` holding this HMAC-SHA256 of the plaintext; the raw value
 * never reaches the database unencrypted.
 */
final class BlindIndex
{
    private function __construct() {}

    /**
     * Compute the blind-index hash for a value, or null when blank.
     */
    public static function compute(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
