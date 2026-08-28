@php $avatarSizeClass = $sizeClass ?? 'h-16 w-16';
    $iconSize = str_contains($avatarSizeClass, 'h-64') || str_contains($avatarSizeClass, 'h-52') ? 'h-8 w-8' : 'h-5 w-5'; @endphp
@if($member?->photo)
    <div class="group relative {{ $avatarSizeClass }} shrink-0 overflow-hidden rounded-lg">
        <img
            src="{{ asset('storage/'.$member->photo) }}"
            data-zoom-src="{{ asset('storage/'.$member->photo) }}"
            data-zoom-alt="{{ $alt ?? $member->name }}"
            class="h-full w-full object-cover cursor-pointer"
            alt="{{ $alt ?? $member->name }}"
            x-on:click.prevent.stop="window.dispatchEvent(new CustomEvent('open-photo-zoom', { detail: { src: $el.dataset.zoomSrc, alt: $el.dataset.zoomAlt } }))"
        >
        <div class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-lg bg-black/40 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
            <x-filament::icon icon="heroicon-o-magnifying-glass-plus" class="{{ $iconSize }} text-white" />
        </div>
    </div>
@else
    <span class="fi-color fi-color-primary flex {{ $avatarSizeClass }} shrink-0 items-center justify-center rounded-lg">
        <x-filament::icon icon="heroicon-m-user" :size="\Filament\Support\Enums\IconSize::Large" />
    </span>
@endif
