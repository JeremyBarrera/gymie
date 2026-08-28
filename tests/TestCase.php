<?php

namespace Tests;

use App\Services\LocationTenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    

    protected function tearDown(): void
    {
        LocationTenantContext::setLocationId(null);

        parent::tearDown();
    }
}
