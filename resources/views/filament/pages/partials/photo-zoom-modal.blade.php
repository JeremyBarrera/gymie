@php
// Global photo zoom modal - reusable across the app (AGENTS.md: Filament components, no hardcoded colors)
// Trigger via: window.dispatchEvent(new CustomEvent("open-photo-zoom", { detail: { src, alt } }))
@endphp

<div
    x-data
    x-on:open-photo-zoom.window="
        const img = document.getElementById('photo-zoom-img');
        if (img) { img.src = $event.detail.src; img.alt = $event.detail.alt; }
        window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'photo-zoom' } }))
    "
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
                id="photo-zoom-img"
                src=""
                alt=""
                class="max-w-full max-h-[75vh] object-contain rounded-xl"
            >
        </div>
    </x-filament::modal>
</div>
