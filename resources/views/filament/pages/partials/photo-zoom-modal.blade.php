@php
// Global photo zoom modal - reusable across the app (AGENTS.md: Filament components, no hardcoded colors)
// Trigger via: window.dispatchEvent(new CustomEvent("open-photo-zoom", { detail: { src, alt } }))
@endphp

<div
    x-data="{ zoomSrc: '', zoomAlt: '' }"
    x-on:open-photo-zoom.window="zoomSrc = $event.detail.src; zoomAlt = $event.detail.alt; window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'photo-zoom' } }))"
>
    <x-filament::modal
        id="photo-zoom"
        width="4xl"
        :close-by-clicking-away="true"
        :close-by-escaping="true"
        :heading="__('app.ui.zoom_photo')"
    >
        <div class="flex justify-center">
            <img
                :src="zoomSrc"
                :alt="zoomAlt"
                class="max-w-full max-h-[75vh] object-contain rounded-xl"
            >
        </div>
    </x-filament::modal>
</div>
