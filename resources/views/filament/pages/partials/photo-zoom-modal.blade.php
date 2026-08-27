@php
// Global photo zoom modal - reusable across the app
// Trigger via: $dispatch('open-photo-zoom', { src: '...', alt: '...' })
@endphp

<div
    x-data="{
        isOpen: false,
        currentSrc: '',
        currentAlt: '',
        open(src, alt) {
            this.currentSrc = src
            this.currentAlt = alt
            this.isOpen = true
            document.body.style.overflow = 'hidden'
        },
        close() {
            this.isOpen = false
            document.body.style.overflow = ''
        }
    }"
    x-on:open-photo-zoom.window="open($event.detail.src, $event.detail.alt)"
    x-on:keydown.escape.window="isOpen && close()"
    class="fixed inset-0 z-50"
    x-show="isOpen"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('app.ui.zoom_photo') }}"
>

    {{-- Backdrop --}}
    <div
        class="fixed inset-0 bg-black/80 backdrop-blur-sm"
        x-on:click="close()"
        aria-hidden="true"
    ></div>

    {{-- Modal content --}}
    <div
        class="fixed inset-0 flex items-center justify-center p-4"
        x-on:click.outside="close()"
    >
        <div class="relative max-w-full max-h-full">
            <img
                :src="currentSrc"
                :alt="currentAlt"
                class="max-w-[90vw] max-h-[85vh] object-contain rounded-xl shadow-2xl"
                x-on:click.outside="close()"
            >

            {{-- Close button --}}
            <button
                type="button"
                class="absolute -top-12 right-0 h-10 w-10 rounded-full bg-black/50 hover:bg-black/70 text-white flex items-center justify-center transition-colors focus:outline-none focus:ring-2 focus:ring-white"
                x-on:click="close()"
                aria-label="{{ __('app.actions.close') }}"
            >
                <x-filament::icon icon="heroicon-o-x-mark" class="h-6 w-6" />
            </button>
        </div>
    </div>

    {{-- Keyboard hint --}}
    <div class="fixed bottom-6 left-1/2 -translate-x-1/2 text-white/70 text-sm hidden sm:block"
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
    >
        {{ __('app.ui.press_escape_to_close') }}
    </div>

</div>