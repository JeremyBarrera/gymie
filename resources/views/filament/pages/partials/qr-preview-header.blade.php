<div class="flex flex-col gap-2 items-start">
    <x-filament::button
        :href="\App\Filament\Pages\PrintQrCodes::getUrl()"
        tag="a"
        size="sm"
        color="gray"
        icon="heroicon-m-arrow-left"
        class="fi-btn-size-sm"
    >
        {{ __('app.actions.back') }}
    </x-filament::button>

    <x-filament-panels::header :heading="$heading" />
</div>
