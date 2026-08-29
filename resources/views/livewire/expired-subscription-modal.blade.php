<div>
    <x-filament::modal
        id="expired-subscription-modal"
        width="lg"
        :close-by-clicking-away="false"
        :heading="__('app.check_in.add_subscription')"
        :description="__('app.check_in.add_subscription_hint')"
    >
        <div class="space-y-5">
            {{ $this->form }}
        </div>

        <x-slot name="footer">
            <x-filament::button
                wire:key="expired-submit"
                color="success"
                size="md"
                class="w-full"
                wire:click="submit"
                wire:loading.attr="disabled"
                wire:target="submit"
            >
                {{ __('app.check_in.add_subscription') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
