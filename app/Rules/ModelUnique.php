<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;

final class ModelUnique implements ValidationRule
{
    

    public function __construct(
        private string $modelClass,
        private string $column,
        private int|string|null $ignoreId = null,
    ) {}

    

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $modelClass = $this->modelClass;

        
        $model = new $modelClass;

        $query = $modelClass::query()->where($this->column, $value);

        if ($this->ignoreId !== null) {
            $query->where($model->getKeyName(), '!=', $this->ignoreId);
        }

        if ($query->exists()) {
            $fail(__('validation.unique', ['attribute' => $attribute]));
        }
    }
}
