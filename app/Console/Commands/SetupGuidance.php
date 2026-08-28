<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupGuidance extends Command
{
    

    protected $signature = 'gymie:setup-guidance';

    

    protected $description = 'Print the first-run setup next steps';

    public function handle(): int
    {
        $this->newLine();
        $this->info('First-run setup is still pending.');

        $this->warn('No owner account exists yet.');
        $this->warn('Create one, then complete setup, or the app will have no one to administer it.');

        $this->newLine();
        $this->info('Next steps:');

        $this->line('1. php artisan gymie:create-owner you@example.com --name "Your Name" --password secret');
        $this->line('2. php artisan gymie:complete-setup');

        $this->newLine();

        return self::SUCCESS;
    }
}
