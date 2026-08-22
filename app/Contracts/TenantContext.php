<?php

namespace App\Contracts;

/**
 * Resolves the current tenant (Location) context.
 *
 * OSS default returns null (single tenant). The platform/tenancy implementation
 * binds this to a tenant-aware resolver.
 */
interface TenantContext
{
    /**
     * @return int|null The current Location id, or null when running single-tenant.
     */
    public function locationId(): ?int;
}
