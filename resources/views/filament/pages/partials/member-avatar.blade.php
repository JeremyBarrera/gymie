@php
    // One avatar presentation for every member mention across reception
    // views: photo when present, themed placeholder otherwise.
    $avatarSizeClass = $sizeClass ?? 'h-16 w-16';
@endphp
@if($member?->photo)
    <img
        src="{{ asset('storage/'.$member->photo) }}"
        data-zoom-src="{{ asset('storage/'.$member->photo) }}"
        data-zoom-alt="{{ $alt ?? $member->name }}"
        class="{{ $avatarSizeClass }} shrink-0 rounded-lg object-cover cursor-pointer"
        alt="{{ $alt ?? $member->name }}"
        x-on:click.prevent.stop="window.dispatchEvent(new CustomEvent('open-photo-zoom', { detail: { src: $el.dataset.zoomSrc, alt: $el.dataset.zoomAlt } }))"
    >
@else
    <span class="fi-color fi-color-primary flex {{ $avatarSizeClass }} shrink-0 items-center justify-center rounded-lg">
        <x-filament::icon icon="heroicon-m-user" :size="\Filament\Support\Enums\IconSize::Large" />
    </span>
@endif
