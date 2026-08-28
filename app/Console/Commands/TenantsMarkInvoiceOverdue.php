<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\LocationTenantContext;
use Illuminate\Console\Command;

class TenantsMarkInvoiceOverdue extends Command
{
    

    protected $signature = 'gymie:tenants:invoices {--mark-overdue : Mark invoices as overdue based on due date}';

    

    protected $description = 'Perform operations on invoices for every location';

    public function handle(): int
    {
        $arguments = [
            '--mark-overdue' => $this->option('mark-overdue'),
        ];

        foreach (Location::query()->orderBy('id')->cursor() as $location) {
            
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
