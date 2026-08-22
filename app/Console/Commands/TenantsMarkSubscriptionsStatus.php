<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\LocationTenantContext;
use Illuminate\Console\Command;

/**
 * Runs `gymie:subscriptions` once per location so every tenant's
 * subscriptions are marked expiring/expired within its own scope.
 */
class TenantsMarkSubscriptionsStatus extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gymie:tenants:subscriptions
                            {--mark-expired : Mark expired subscriptions}
                            {--mark-expiring : Mark subscriptions expiring within the configured window}';

    /**
     * @var string
     */
    protected $description = 'Mark subscriptions as expiring or expired for every location';

    public function handle(): int
    {
        $arguments = array_filter([
            '--mark-expired' => $this->option('mark-expired'),
            '--mark-expiring' => $this->option('mark-expiring'),
        ]);

        foreach (Location::query()->orderBy('id')->cursor() as $location) {
            /** @var Location $location */
            LocationTenantContext::setLocationId((int) $location->id);

            $this->info("Location: {$location->name}");

            $exitCode = $this->callSilently('gymie:subscriptions', $arguments);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        return self::SUCCESS;
    }
}
