@php $cameraFieldId = str($getStatePath())->replace('.', '_')->toString(); @endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="cameraUpload({
            state: $wire.$entangle('{{ $getStatePath() }}'),
            currentUrl: @js($getState() ? \App\Helpers\Helpers::photoUrl($getState()) : null),
            modalId: 'member-photo-{{ $getStatePath() }}',
            fieldId: @js($cameraFieldId),
        })"
        wire:ignore
        class="grid gap-3"
    >
        <div>
            <div
                x-show="photoUrl"
                class="group relative h-48 w-40 overflow-hidden rounded-xl"
            >
                <img
                    :src="photoUrl"
                    class="h-full w-full object-cover cursor-pointer"
                    alt=""
                    x-on:click="window.dispatchEvent(new CustomEvent('open-photo-zoom', { detail: { src: photoUrl, alt: '' } }))"
                >
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-xl bg-black/40 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                    <x-filament::icon icon="heroicon-o-magnifying-glass-plus" class="h-8 w-8 text-white" />
                </div>
            </div>
            <div
                x-show="! photoUrl"
                class="flex h-48 w-40 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-(--gray-300)"
            >
                <x-filament::icon icon="heroicon-o-camera" class="h-8 w-8" />
                <span class="fi-text-muted px-4 text-center text-sm">
                    {{ __('app.placeholders.upload_photo') }}
                </span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button
                type="button"
                x-on:click="openModal()"
                x-show="! photoUrl"
            >
                {{ __('app.actions.take_photo') }}
            </x-filament::button>

            <x-filament::button
                type="button"
                color="primary"
                x-on:click="openModal()"
                x-show="photoUrl"
            >
                {{ __('app.actions.change_photo') }}
            </x-filament::button>

            <x-filament::button
                type="button"
                color="danger"
                x-on:click="removePhoto()"
                x-show="photoUrl"
            >
                {{ __('app.actions.remove_photo') }}
            </x-filament::button>
        </div>

        <x-filament::modal
            :id="'member-photo-' . $getStatePath()"
            width="2xl"
            :close-by-clicking-away="false"
            :close-by-escaping="false"
            :heading="__('app.fields.photo')"
        >
            <div class="space-y-4">
                <p class="fi-text text-sm">{{ __('app.reception.verify_photo_hint') }}</p>

                <div class="relative mx-auto max-w-md overflow-hidden rounded-xl">
                    <video id="camera-video-{{ $cameraFieldId }}" class="w-full" playsinline muted></video>
                    <img
                        id="camera-preview-{{ $cameraFieldId }}"
                        x-show="draftUrl"
                        :src="draftUrl"
                        class="w-full"
                        alt=""
                    >
                    <div
                        id="camera-fallback-{{ $cameraFieldId }}"
                        x-show="cameraFallback"
                        class="fi-text-muted absolute inset-0 flex items-center justify-center p-4 text-center text-sm"
                    >
                        {{ __('app.reception.verify_no_camera') }}
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <x-filament::button
                        type="button"
                        color="success"
                        x-on:click="capturePhoto()"
                        x-show="! draftUrl"
                        x-bind:disabled="cameraReady === false"
                    >
                        {{ __('app.reception.verify_photo_capture') }}
                    </x-filament::button>

                    <x-filament::button
                        type="button"
                        x-on:click="retake()"
                        x-show="draftUrl && draftSource === 'camera'"
                    >
                        {{ __('app.reception.verify_photo_retake') }}
                    </x-filament::button>

                    <x-filament::button
                        tag="label"
                        color="danger"
                        class="cursor-pointer"
                        x-show="! draftUrl || draftSource === 'camera'"
                    >
                        {{ __('app.reception.verify_photo_upload') }}
                        <input type="file" accept="image/jpeg,image/png,image/webp" hidden x-on:change="handleUpload($event)">
                    </x-filament::button>
                </div>
            </div>

            <x-slot name="footer">
                <div class="flex w-full gap-3">
                    <x-filament::button
                        type="button"
                        color="gray"
                        x-on:click="closeModal()"
                        class="flex-1"
                    >
                        {{ __('app.reception.cancel') }}
                    </x-filament::button>

                    <x-filament::button
                        type="button"
                        x-on:click="usePhoto()"
                        class="flex-1"
                        x-show="draftUrl"
                    >
                        {{ __('app.actions.use_photo') }}
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::modal>
    </div>
</x-dynamic-component>

<script>
    (function () {
        if (window.__gymieCameraUploadRegistered) {
            return;
        }
        window.__gymieCameraUploadRegistered = true;

        document.addEventListener('alpine:init', () => {
            Alpine.data('cameraUpload', (options) => ({
                photo: options.state,
                photoUrl: options.currentUrl,
                draftUrl: null,
                draftSource: null,
                cameraReady: false,
                cameraFallback: false,
                stream: null,

                init() {
                    this.$watch('draftUrl', (draftUrl) => {
                        if (draftUrl) {
                            this.stopStream();
                        }
                    });

                    this.$nextTick(() => {
                        window.addEventListener('open-modal', (event) => {
                            if (event.detail.id !== options.modalId) {
                                return;
                            }
                            this.$nextTick(() => this.startCamera());
                        });

                        window.addEventListener('close-modal', (event) => {
                            if (event.detail.id !== options.modalId) {
                                return;
                            }
                            this.stopStream();
                        });
                    });
                },

                openModal() {
                    this.draftUrl = null;
                    this.draftSource = null;
                    this.$dispatch('open-modal', { id: options.modalId });
                },

                closeModal() {
                    this.$dispatch('close-modal', { id: options.modalId });
                },

                usePhoto() {
                    if (!this.draftUrl) {
                        return;
                    }
                    this.photo = this.draftUrl;
                    this.photoUrl = this.draftUrl;
                    this.closeModal();
                },

                removePhoto() {
                    this.photo = null;
                    this.photoUrl = null;
                },

                videoEl() {
                    return this.$root.querySelector('#camera-video-' + options.fieldId);
                },

                async startCamera() {
                    const video = this.videoEl();
                    if (!video) {
                        this.cameraFallback = true;
                        return;
                    }

                    this.cameraFallback = false;
                    this.cameraReady = false;
                    this.stopStream();

                    await window.GymieCameraCapture.start(video, {
                        onReady: (stream) => {
                            this.stream = stream;
                            this.cameraReady = true;
                        },
                        onNoCamera: () => {
                            this.cameraFallback = true;
                        },
                    });
                },

                stopStream() {
                    if (this.stream) {
                        window.GymieCameraCapture.stop(this.videoEl());
                        this.stream = null;
                    }
                    this.cameraReady = false;
                },

                setDraft(dataUrl, source) {
                    this.draftUrl = dataUrl;
                    this.draftSource = source;
                    this.cameraReady = false;
                    this.cameraFallback = false;
                },

                capturePhoto() {
                    const video = this.videoEl();
                    if (!this.cameraReady) {
                        return;
                    }

                    const dataUrl = window.GymieCameraCapture.capture(video);
                    if (dataUrl) {
                        this.setDraft(dataUrl, 'camera');
                    }
                },

                retake() {
                    this.draftUrl = null;
                    this.draftSource = null;
                    this.startCamera();
                },

                handleUpload(event) {
                    const file = event.target.files && event.target.files[0];
                    if (!file) {
                        return;
                    }

                    window.GymieCameraCapture.fileToDataUrl(file, (dataUrl) => this.setDraft(dataUrl, 'upload'));
                    event.target.value = '';
                },
            }));
        });
    })();
</script>
