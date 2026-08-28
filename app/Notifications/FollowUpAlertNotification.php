<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class FollowUpAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    

    public function __construct(
        public array $payload,
    ) {
        
        
        $this->id ??= Str::uuid()->toString();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    

    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
