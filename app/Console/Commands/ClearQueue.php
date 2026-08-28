<?php

namespace App\Console\Commands;

use App\Events\QueueEntryExpired;
use App\Models\QueueEntry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ClearQueue extends Command
{
    

    protected $signature = 'gymie:clear-queue
                            {--location= : Only clear the queue for this location id}
                            {--force : Skip the confirmation prompt}';

    

    protected $description = 'Remove all active queue entries (waiting/attending) and notify waiting screens';

    

    public function handle(): int
    {
        $query = QueueEntry::query()->whereIn('status', ['waiting', 'attending']);

        if ($locationId = $this->option('location')) {
            $query->where('location_id', $locationId);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No active queue entries.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Clear {$count} active queue entries?", false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $model = $query->getModel();

        $query->chunkById(
            200,
            function (Collection $entries): void {
                foreach ($entries as $entry) {
                    QueueEntryExpired::dispatch($entry->id, $entry->uuid, $entry->location->tokens()->value('token') ?? '');
                }

                QueueEntry::whereKey($entries->modelKeys())->delete();
            },
            column: $model->getQualifiedKeyName(),
            alias: $model->getKeyName(),
        );

        $this->info("Cleared {$count} active queue entries.");

        return self::SUCCESS;
    }
}
