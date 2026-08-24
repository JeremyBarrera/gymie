<div
    class="fi-inline-flex relative items-center ms-1"
    x-data="{
        open: false,
        promptSeen: localStorage.getItem('gymie-sound-prompt-seen') === '1',
        iconClick() {
            window.SoundAlerts && window.SoundAlerts.ensureUnlocked();

            if (! this.promptSeen) {
                localStorage.setItem('gymie-sound-prompt-seen', '1');
                this.promptSeen = true;
                this.open = true;

                return;
            }

            const turningOn = ! $wire.soundAlerts;
            if (turningOn) {
                window.SoundAlerts && window.SoundAlerts.enableFromGesture();
            }
            $wire.toggleSoundAlerts();
        },
    }"
>
    <x-filament::icon-button
        :icon="$soundAlerts ? 'heroicon-m-speaker-wave' : 'heroicon-m-speaker-x-mark'"
        :color="$soundAlerts ? 'success' : 'gray'"
        :label="__('app.reception.sound_alerts_label')"
        wire:key="sound-toggle-topbar"
        x-on:click="iconClick"
    />

    <div
        x-on:gymie-sound-synced.window="if ($event.detail.enabled) open = false"
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="fi-dropdown-panel absolute top-full z-10 mt-2 w-64 p-4 end-0"
    >
        <p class="fi-text text-sm font-semibold">{{ __('app.reception.sound_prompt_title') }}</p>
        <p class="fi-text fi-text-muted mt-1 text-xs leading-relaxed">{{ __('app.reception.sound_prompt_body') }}</p>

        <div class="mt-3 flex items-center justify-end gap-2">
            <x-filament::button
                size="xs"
                color="gray"
                x-on:click="open = false"
            >
                {{ __('app.reception.sound_not_now') }}
            </x-filament::button>

            <x-filament::button
                size="xs"
                color="success"
                x-on:click="window.SoundAlerts && window.SoundAlerts.enableFromGesture(); $wire.toggleSoundAlerts(); open = false"
            >
                {{ __('app.reception.sound_enable') }}
            </x-filament::button>
        </div>
    </div>
</div>
