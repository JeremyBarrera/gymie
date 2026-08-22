<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\LocationTenantContext;
use Illuminate\Console\Command;

/**
 * Runs `gymie:invoices` once per location so every tenant's invoices are
 * updated within its own scope.
 */
class TenantsMarkInvoiceOverdue extends Command
{
    /**
     * @var string
     */
    protected $signature = 'gymie:tenants:invoices {--mark-overdue : Mark invoices as overdue based on due date}';

    /**
     * @var string
     */
    protected $description = 'Perform operations on invoices for every location';

    public function handle(): int
    {
        $arguments = [
            '--mark-overdue' => $this->option('mark-overdue'),
        ];

        foreach (Location::query()->orderBy('id')->cursor() as $location) {
            /** @var Location $location */
            LocationTenantContext::setLocationId((int) $location->id);

            $this->info("Location: {$location->name}");

            $exitCode = $this->callSilently('gymie:invoices', $arguments);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        return self::SUCCESS;
    }
}
