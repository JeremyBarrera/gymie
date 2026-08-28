@php
// Global photo zoom modal - reusable across the app (AGENTS.md: Filament components, no hardcoded colors)
// Direct trigger: set #photo-zoom-img src/alt then window.dispatchEvent(new CustomEvent('open-modal', {detail:{id:'photo-zoom'}}))
@endphp

<x-filament::modal
    id="photo-zoom"
    width="4xl"
    :close-by-clicking-away="true"
    :close-by-escaping="true"
    :heading="__('app.ui.zoom_photo')"
>
    <div class="flex justify-center">
        <img
            id="photo-zoom-img"
            src=""
            alt=""
            class="max-w-full max-h-[75vh] object-contain rounded-xl"
        >
    </div>
</x-filament::modal>
