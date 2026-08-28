<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkInvoiceOverdue extends Command
{
    

    protected $signature = 'gymie:invoices {--mark-overdue : Mark invoices as overdue based on due date}';

    

    protected $description = 'Perform operations on invoices (e.g., mark as overdue)';

    

    public function handle(): int
    {
        if (! $this->option('mark-overdue')) {
            $this->info('No operation selected.');

            return self::SUCCESS;
        }

        $updatedCount = Invoice::markOverdue();

        $this->info("{$updatedCount} invoice(s) marked as overdue.");

        return self::SUCCESS;
    }
}
