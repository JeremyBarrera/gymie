@php
    $dialStatePath = $getStatePath() . '_dial_code';
    $currentDial = data_get($getLivewire(), $dialStatePath) ?? \App\Helpers\Helpers::getPhoneCountryCodePlaceholder();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
    :inline-label-vertical-alignment="\Filament\Support\Enums\VerticalAlignment::Center"
>
    <x-filament::input.wrapper :valid="! $errors->has($getStatePath())">
        <div class="flex w-full items-center">
            <select
                class="fi-select-input w-auto shrink-0 cursor-pointer"
                wire:model.live="{{ $dialStatePath }}"
                aria-label="{{ __('app.scan.country_code') }}"
                title="{{ __('app.scan.country_code') }}"
            >
                @foreach (\App\Helpers\Helpers::getCountryDialOptions() as $option)
                    <option
                        value="{{ $option['code'] }}"
                        @selected($currentDial === $option['code'])
                    >{{ $option['code'] }}</option>
                @endforeach
            </select>
            <input
                type="tel"
                class="fi-input min-w-0 flex-1"
                wire:model.live="{{ $getStatePath() }}"
                placeholder="{{ \App\Helpers\Helpers::getPhoneLocalPlaceholder() }}"
                title="{{ __('app.help.phone_format') }}"
                maxlength="20"
                @if ($isRequired()) required @endif
            />
        </div>
    </x-filament::input.wrapper>
</x-dynamic-component>