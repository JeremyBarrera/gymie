<div class="fi-inline-flex relative items-center ms-1">
    <x-filament::icon-button
        :icon="$soundAlerts ? 'heroicon-m-speaker-wave' : 'heroicon-m-speaker-x-mark'"
        :color="$soundAlerts ? 'success' : 'gray'"
        :label="__('app.reception.sound_alerts_label')"
        wire:key="sound-toggle-topbar"
        wire:click="toggleSoundAlerts"
        onclick="window.SoundAlerts && window.SoundAlerts.ensureUnlocked()"
    />

    <div
        x-data="{ open: false }"
        x-init="open = {{ Js::from(! $soundAlerts) }} && ! sessionStorage.getItem('gymie-sound-prompt-dismissed')"
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
                x-on:click="open = false; sessionStorage.setItem('gymie-sound-prompt-dismissed', '1')"
            >
                {{ __('app.reception.sound_not_now') }}
            </x-filament::button>

            <x-filament::button
                size="xs"
                color="success"
                wire:click="toggleSoundAlerts"
                onclick="window.SoundAlerts && window.SoundAlerts.enableFromGesture()"
            >
                {{ __('app.reception.sound_enable') }}
            </x-filament::button>
        </div>
    </div>
</div>
