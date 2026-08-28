<?php

namespace App\Services;

use App\Contracts\TenantContext;

class NullTenantContext implements TenantContext
{
    public function locationId(): ?int
    {
        return null;
    }
}
