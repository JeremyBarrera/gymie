<x-filament-panels::page>
    <form wire:submit.prevent="save" class="space-y-6">
        {{ $this->form }}
        <div class="flex justify-end items-center space-x-4">
            <x-filament::button type="submit" wire:loading.class="opacity-50">
                {{ __('app.settings.actions.save_settings') }}
            </x-filament::button>
        </div>
    </form>

    <div>
        <x-filament::modal
            id="currency-change-modal"
            width="md"
            :close-by-clicking-away="false"
            :heading="__('app.currency.change_heading')"
            :description="__('app.currency.change_description')"
        >
            <x-slot name="footer">
                <x-filament::button
                    color="warning"
                    size="md"
                    class="w-full"
                    wire:click="confirmCurrencySave"
                    wire:loading.attr="disabled"
                    wire:target="confirmCurrencySave"
                >
                    {{ __('app.currency.confirm_change') }}
                </x-filament::button>
                <x-filament::button
                    color="gray"
                    size="md"
                    class="w-full"
                    wire:click="cancelCurrencySave"
                    wire:loading.attr="disabled"
                    wire:target="cancelCurrencySave"
                >
                    {{ __('app.currency.keep_current') }}
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
