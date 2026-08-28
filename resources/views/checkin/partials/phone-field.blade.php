@props([
    'name' => null,
    'wireModel' => null,
    'id' => null,
    'required' => false,
    'placeholder' => null,
    'ariaLabel' => null,
    'filament' => false,
    'dialCode' => null,
])

@php
    $phoneDialOptions = \App\Helpers\Helpers::getCountryDialOptions();
    $phoneSelectedDialCode = $dialCode ?? \App\Helpers\Helpers::getPhoneCountryCodePlaceholder();
@endphp

@if($filament)
    <div class="fi-input-wrp">
        <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content">
            <x-filament::input.select
                id="dial-{{ $id }}"
                inline-prefix
                wire:model.live="{{ $wireModel }}_dial_code"
                aria-label="{{ $ariaLabel ?? __('app.scan.country_code') }}"
            >
                @foreach ($phoneDialOptions as $dialOption)
                    <option value="{{ $dialOption['code'] }}" @selected($dialOption['code'] === $phoneSelectedDialCode)>{{ $dialOption['code'] }}</option>
                @endforeach
            </x-filament::input.select>
        </div>
        <div class="fi-input-wrp-content-ctn">
            @if($wireModel)
                <x-filament::input
                    type="tel"
                    :wire:model="$wireModel"
                    :id="$id"
                    :placeholder="$placeholder ?? \App\Helpers\Helpers::getPhoneLocalPlaceholder()"
                    maxlength="50"
                    :required="$required"
                />
            @else
                <x-filament::input
                    type="tel"
                    :name="$name"
                    :id="$id"
                    :placeholder="$placeholder ?? \App\Helpers\Helpers::getPhoneLocalPlaceholder()"
                    autocomplete="tel"
                    :required="$required"
                />
            @endif
        </div>
    </div>
@else
    <div class="phone-field">
        <select id="dial-{{ $id }}" class="dial-code"
                aria-label="{{ $ariaLabel ?? __('app.scan.country_code') }}">
            @foreach ($phoneDialOptions as $dialOption)
                <option value="{{ $dialOption['code'] }}" @selected($dialOption['code'] === $phoneSelectedDialCode)>{{ $dialOption['code'] }}</option>
            @endforeach
        </select>
        @if($wireModel)
            <input type="tel" wire:model="{{ $wireModel }}" id="{{ $id }}" class="form-input"
                   placeholder="{{ $placeholder ?? \App\Helpers\Helpers::getPhoneLocalPlaceholder() }}"
                   maxlength="50" @if($required) required @endif>
        @else
            <input type="tel" name="{{ $name }}" id="{{ $id }}" class="form-input"
                   placeholder="{{ $placeholder ?? \App\Helpers\Helpers::getPhoneLocalPlaceholder() }}"
                   autocomplete="tel" @if($required) required @endif>
        @endif
    </div>
@endif