<?php

namespace App\Filament\Livewire;

use App\Events\SoundAlertsToggled;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class SoundAlertToggle extends Component
{
    public bool $soundAlerts = false;

    public function mount(): void
    {
        $this->soundAlerts = (bool) Auth::user()?->sound_alerts;
    }

    

    protected function getListeners(): array
    {
        $userId = Auth::id();

        return ["echo:user.{$userId},SoundAlertsToggled" => 'syncSoundAlertsFromBroadcast'];
    }

    public function syncSoundAlertsFromBroadcast(array $payload): void
    {
        $this->soundAlerts = isset($payload['enabled'])
            ? (bool) $payload['enabled']
            : (bool) Auth::user()?->sound_alerts;
    }

    public function toggleSoundAlerts(): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $this->soundAlerts = ! $this->soundAlerts;
        $user->update(['sound_alerts' => $this->soundAlerts]);

        
        
        $this->dispatch('sound-alerts-updated', enabled: $this->soundAlerts);

        
        
        try {
            broadcast(new SoundAlertsToggled($user->id, $this->soundAlerts));
        } catch (\Throwable $exception) {
            Log::warning('Sound alerts cross-tab broadcast failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->dispatch('notify',
            type: 'success',
            message: $this->soundAlerts
                ? __('app.reception.sound_enabled_toast')
                : __('app.reception.sound_disabled_toast'),
        );
    }

    public function render(): View
    {
        return view('livewire.sound-alert-toggle');
    }
}
