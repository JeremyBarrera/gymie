@php
// Global photo zoom modal - reusable across the app (AGENTS.md: Filament components, no hardcoded colors)
// Custom overlay avoids nested Filament modal stacking issues; trigger via open-photo-zoom
@endphp

<div
    x-data="{ show: false, src: '', alt: '' }"
    x-cloak
    x-show="show"
    x-transition.opacity
    x-on:open-photo-zoom.window="if(!$event.detail.src || $event.detail.src === ''){ console.warn('[photo-zoom] empty src', $event.detail); } src = $event.detail.src || ''; alt = $event.detail.alt || ''; show = true; console.log('[photo-zoom] open', $event.detail.src, 'alt', $event.detail.alt)"
    x-on:keydown.escape.window="show = false"
    @click.self="show = false"
    class="fixed inset-0 z-[80] flex items-center justify-center bg-black/80 p-4"
    style="display: none;"
    role="dialog"
    aria-modal="true"
>
    <img
        :src="src"
        :alt="alt"
        class="max-w-full max-h-[75vh] object-contain rounded-xl shadow-xl"
        @click.stop
        x-on:error="console.error('[photo-zoom] img load failed', src)"
    >
    <button @click="show = false" class="absolute top-4 right-4 rounded-full bg-black/60 p-2 text-white hover:bg-black/80">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
</div>
