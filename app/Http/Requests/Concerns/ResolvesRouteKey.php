<?php

namespace App\Http\Requests\Concerns;

use App\Support\Data;
use Illuminate\Database\Eloquent\Model;

trait ResolvesRouteKey
{
    

    protected function routeKey(string $parameter): int|string
    {
        $value = $this->route($parameter);

        if ($value instanceof Model) {
            return Data::string($value->getKey());
        }

        if (is_scalar($value)) {
            return Data::string($value);
        }

        throw new \LogicException("Missing or invalid route parameter [{$parameter}].");
    }
}
