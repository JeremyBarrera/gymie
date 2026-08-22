<x-filament-panels::page>
    @if ($locations->isEmpty())
        <x-filament::section>
            <div class="flex flex-col items-center py-12 text-center">
                <x-filament::icon
                    icon="heroicon-o-qr-code"
                    class="h-12 w-12 fi-text-muted"
                />
                <h3 class="mt-4 text-base font-semibold fi-text">
                    {{ __('app.reception.qr_empty_title') }}
                </h3>
                <p class="mt-2 max-w-md text-sm fi-text-muted">
                    {{ __('app.reception.qr_empty_body') }}
                </p>
                @if (\App\Filament\Resources\Locations\LocationResource::canCreate())
                    <x-filament::button
                        :href="\App\Filament\Resources\Locations\LocationResource::getUrl('create')"
                        tag="a"
                        class="mt-6"
                        icon="heroicon-m-plus"
                    >
                        {{ __('app.reception.qr_empty_create') }}
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @else
        @foreach ($locations as $location)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $location->name }}
                </x-slot>

                <div class="space-y-6">
                    @foreach (['checkin', 'signup'] as $kind)
                        @php
                            $token = $location->tokens->firstWhere('kind', $kind);
                            $isCheckin = $kind === 'checkin';
                            $typeLabel = $isCheckin
                                ? __('app.reception.qr_type_checkin')
                                : __('app.reception.qr_type_signup');
                            $typeIcon = $isCheckin
                                ? 'heroicon-m-clipboard-document-check'
                                : 'heroicon-m-user-plus';
                        @endphp

                        <div class="flex items-center justify-between gap-4 py-3">
                            <div class="flex items-center gap-3">
                                <x-filament::icon
                                    :icon="$typeIcon"
                                    class="h-5 w-5 fi-text-muted"
                                />
                                <span class="font-medium fi-text">
                                    {{ $typeLabel }}
                                </span>
                                @if ($token)
                                    <x-filament::badge color="success">
                                        {{ __('app.reception.qr_status_ready') }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">
                                        {{ __('app.reception.qr_status_missing') }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($token)
                                    <x-filament::button
                                        size="sm"
                                        tag="a"
                                        :href="$this->downloadUrl($location->id, $kind)"
                                        icon="heroicon-m-arrow-down-tray"
                                        color="success"
                                    >
                                        {{ __('app.actions.download') }}
                                    </x-filament::button>
                                    <x-filament::button
                                        size="sm"
                                        tag="a"
                                        :href="$this->previewUrl($location->id, $kind)"
                                        icon="heroicon-m-eye"
                                    >
                                        {{ __('app.actions.preview') }}
                                    </x-filament::button>
                                    <x-filament::button
                                        size="sm"
                                        color="danger"
                                        icon="heroicon-m-trash"
                                        wire:click="deleteToken({{ $token->id }})"
                                        wire:confirm="{{ __('app.reception.qr_delete_confirm') }}"
                                    >
                                        {{ __('app.reception.qr_delete') }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button
                                        size="sm"
                                        color="primary"
                                        icon="heroicon-m-plus"
                                        wire:click="createToken({{ $location->id }}, '{{ $kind }}')"
                                    >
                                        {{ __('app.reception.qr_generate_new') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>