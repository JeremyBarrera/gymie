<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class ModelExists implements ValidationRule
{
    

    public function __construct(private string $modelClass) {}

    

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_scalar($value)) {
            $fail(__('validation.exists', ['attribute' => $attribute]));

            return;
        }

        $modelClass = $this->modelClass;

        $exists = $modelClass::query()
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail(__('validation.exists', ['attribute' => $attribute]));
        }
    }
}
