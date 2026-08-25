<?php

namespace App\Jobs;

use App\Events\QueueEntryExpired;
use App\Models\QueueEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpireQueueEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $queueEntryId,
        public string $queueEntryUuid,
        public ?string $locationToken = null,
    ) {}

    public function handle(): void
    {
        $entry = QueueEntry::find($this->queueEntryId);
        if (! $entry || ! in_array($entry->status, ['waiting', 'attending'], true)) {
            return;
        }

        QueueEntryExpired::dispatch($entry->id, $entry->uuid, $this->locationToken ?? '');

        $entry->delete();
    }
}
