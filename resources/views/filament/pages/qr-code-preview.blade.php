<x-filament-panels::page>
    @if ($error)
        <x-filament::section>
            <div class="flex flex-col items-center gap-4 py-8 text-center">
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="h-12 w-12 text-danger-600"
                />
                <p class="fi-text-muted text-sm">
                    {{ $error }}
                </p>
                <x-filament::button
                    :href="\App\Filament\Pages\PrintQrCodes::getUrl()"
                    tag="a"
                    color="gray"
                >
                    {{ __('app.actions.back') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <x-filament::section>
                    <div class="flex flex-col items-center gap-6">
                        <img
                            src="{{ $dataUri }}"
                            alt="{{ __('app.reception.qr_codes') }}"
                            class="h-auto w-full max-w-[200px] rounded-lg"
                        />
                        @if ($downloadUrl)
                            <x-filament::button
                                :href="$downloadUrl"
                                tag="a"
                                size="sm"
                                icon="heroicon-m-arrow-down-tray"
                                color="gray"
                            >
                                {{ __('app.actions.download') }}
                            </x-filament::button>
                        @endif
                    </div>
                </x-filament::section>
            </div>

            <div>
                <x-filament::section>
                    <x-slot name="heading">
                        {{ __('app.reception.qr_preview_details') }}
                    </x-slot>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                        @php $fields = [
                                'location' => $this->getLocation()?->name ?? __('app.placeholders.dash'),
                                'type' => $type === 'checkin' ? __('app.reception.qr_type_checkin') : __('app.reception.qr_type_signup'),
                                'format' => strtoupper($format),
                                'size' => "{$size} × {$size}",
                            ]; @endphp
                        @foreach($fields as $label => $value)
                            <div class="flex flex-col gap-1">
                                <dt class="fi-text-muted text-xs font-medium uppercase tracking-wider">
                                    {{ __('app.reception.qr_' . $label) }}
                                </dt>
                                <dd class="fi-text text-sm font-semibold">
                                    {{ $value }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
