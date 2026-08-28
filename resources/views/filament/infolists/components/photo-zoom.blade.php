<div>
    @php
        $photo = $getState();
        $name = $record->name ?? '';
        $src = $photo ? asset('storage/'.$photo) : null;
        $defaultUrl = 'https://ui-avatars.com/api/?background=000&color=fff&name='.urlencode($name);
        $displaySrc = $src ?? $defaultUrl;
        $isZoomable = filled($photo);
    @endphp
    @if($isZoomable)
        <div class="group relative inline-flex h-[180px] w-[180px] shrink-0 overflow-hidden rounded-full">
            <img
                src="{{ $displaySrc }}"
                data-zoom-src="{{ $src }}"
                data-zoom-alt="{{ $name }}"
                alt="{{ $name }}"
                class="h-full w-full object-cover cursor-pointer"
                x-on:click.prevent.stop="window.dispatchEvent(new CustomEvent('open-photo-zoom', { detail: { src: $el.dataset.zoomSrc, alt: $el.dataset.zoomAlt } }))"
            >
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-full bg-black/40 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                <x-filament::icon icon="heroicon-o-magnifying-glass-plus" class="h-8 w-8 text-white" />
            </div>
        </div>
    @else
        <div class="inline-flex h-[180px] w-[180px] shrink-0 overflow-hidden rounded-full">
            <img src="{{ $defaultUrl }}" alt="{{ $name }}" class="h-full w-full object-cover">
        </div>
    @endif
</div>
