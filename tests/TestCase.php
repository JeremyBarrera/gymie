<?php

namespace Tests;

use App\Services\LocationTenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reset the pinned tenant location so the static context never leaks
     * from one test into the next.
     */
    protected function tearDown(): void
    {
        LocationTenantContext::setLocationId(null);

        parent::tearDown();
    }
}
