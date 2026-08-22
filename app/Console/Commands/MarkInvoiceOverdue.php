<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkInvoiceOverdue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gymie:invoices {--mark-overdue : Mark invoices as overdue based on due date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform operations on invoices (e.g., mark as overdue)';

    /**
     * Execute the console command.
     */
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
