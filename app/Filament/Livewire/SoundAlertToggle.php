<?php

namespace App\Filament\Livewire;

use App\Events\SoundAlertsToggled;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Per-user sound-alerts toggle for the panel topbar.
 *
 * The preference lives on the user record. The acting tab flips instantly
 * via the "sound-alerts-updated" Livewire event; every other open tab of
 * the same user follows over the private "user.{id}" websocket channel.
 */
class SoundAlertToggle extends Component
{
    public bool $soundAlerts = false;

    public function mount(): void
    {
        $this->soundAlerts = (bool) Auth::user()?->sound_alerts;
    }

    /**
     * Listen for the same user's toggle broadcast on the private
     * `user.{id}` channel so every other open tab re-renders its icon to
     * match, exactly like the theme switcher live-syncs across tabs.
     * Permission is guaranteed: the broadcast channel only authorizes the
     * owning user, and each tab's component is mounted for the same user.
     */
    protected function getListeners(): array
    {
        $userId = Auth::id();

        return ["echo-private:user.{$userId},SoundAlertsToggled" => 'syncSoundAlertsFromBroadcast'];
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

        // Fast path for the acting tab; the websocket copy (below) keeps
        // every other open tab of this user in sync live.
        $this->dispatch('sound-alerts-updated', enabled: $this->soundAlerts);

        // Cross-tab sync is best-effort: a down websocket server must never
        // break the toggle itself (the acting tab already flipped above).
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
