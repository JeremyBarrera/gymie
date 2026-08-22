{{-- Sign-up photo capture: webcam (getUserMedia) with upload fallback.
     Included by the Reception page and the global live-signup popup — every
     inclusion gets its own copy, scoped to its own Livewire component root.
     Camera state is tracked per overlay element in a shared registry, and
     capture/retake/upload resolve the overlay from the element they were
     clicked in, so two overlays on the same page can never cross-talk or
     bind the photo to the wrong component. The getUserMedia/playing/canvas
     mechanics come from the shared GymieCameraCapture bundle. --}}
<script>
    (function () {
        const script = document.currentScript;
        const componentRoot = script && script.closest('[wire\\:id]');
        if (!componentRoot) return;
        const wireId = componentRoot.getAttribute('wire:id');

        const state = (window.__verifyCameraState = window.__verifyCameraState || {
            streams: new Map(),
            active: new Set(),
            ready: new Set(),
        });

        const overlayEl = () => componentRoot.querySelector('#verify-overlay');

        const wireFor = (root) => {
            const host = root && root.closest('[wire\\:id]');
            return host && window.Livewire ? Livewire.find(host.getAttribute('wire:id')) : null;
        };

        const showCaptureReady = (root) => {
            const captureBtn = root.querySelector('#verify-camera-capture');
            const retakeBtn = root.querySelector('#verify-camera-retake');
            if (!captureBtn || !retakeBtn) return;
            captureBtn.disabled = false;
            captureBtn.classList.remove('fi-disabled');
            captureBtn.style.display = 'inline-flex';
            retakeBtn.style.display = 'none';
        };

        const startCamera = async (root) => {
            root = root || overlayEl();
            if (!root || state.active.has(root)) return;

            const wire = wireFor(root);
            const existingPhoto = wire && wire.get('verifyPhoto');
            if (existingPhoto) {
                setPhoto(root, existingPhoto);
                return;
            }

            const video = root.querySelector('#verify-camera');
            const fallback = root.querySelector('#verify-camera-fallback');
            const captureBtn = root.querySelector('#verify-camera-capture');
            const retakeBtn = root.querySelector('#verify-camera-retake');
            if (!video || !fallback || !captureBtn || !retakeBtn) return;

            const previous = state.streams.get(root);
            if (previous) previous.getTracks().forEach((track) => track.stop());
            state.streams.delete(root);
            state.active.delete(root);
            state.ready.delete(root);
            fallback.style.display = 'none';

            await window.GymieCameraCapture.start(video, {
                onReady: (stream) => {
                    state.streams.set(root, stream);
                    state.active.add(root);
                    state.ready.add(root);
                    showCaptureReady(root);
                },
                onNoCamera: () => {
                    fallback.style.display = 'flex';
                },
            });
        };

        const stopCamera = (root) => {
            const stream = state.streams.get(root);
            if (stream) stream.getTracks().forEach((track) => track.stop());
            state.streams.delete(root);
            state.active.delete(root);
            state.ready.delete(root);
        };

        const setPhoto = (root, dataUrl) => {
            stopCamera(root);

            const preview = root.querySelector('#verify-photo-preview');
            const captureBtn = root.querySelector('#verify-camera-capture');
            const retakeBtn = root.querySelector('#verify-camera-retake');
            const video = root.querySelector('#verify-camera');
            if (preview) {
                preview.src = dataUrl;
                preview.style.display = 'block';
            }
            if (video) video.style.display = 'none';
            if (captureBtn) {
                captureBtn.disabled = true;
                captureBtn.style.display = 'none';
            }
            if (retakeBtn) retakeBtn.style.display = 'inline-flex';

            const wire = wireFor(root);
            if (wire) wire.set('verifyPhoto', dataUrl);

            root.dispatchEvent(new CustomEvent('verify-photo-ready', {
                detail: { ready: true },
                bubbles: true,
            }));
        };

        const overlayFrom = (element) => element && element.closest('#verify-overlay');

        const capturePhoto = (trigger) => {
            const root = overlayFrom(trigger);
            if (!root || !state.ready.has(root)) return;

            const video = root.querySelector('#verify-camera');
            const dataUrl = window.GymieCameraCapture.capture(video);
            if (dataUrl) setPhoto(root, dataUrl);
        };

        const retake = (trigger) => {
            const root = overlayFrom(trigger);
            if (!root) return;

            const preview = root.querySelector('#verify-photo-preview');
            const video = root.querySelector('#verify-camera');
            if (preview) {
                preview.style.display = 'none';
                preview.src = '';
            }
            if (video) video.style.display = 'block';

            const wire = wireFor(root);
            if (wire) wire.set('verifyPhoto', null);

            root.dispatchEvent(new CustomEvent('verify-photo-ready', {
                detail: { ready: false },
                bubbles: true,
            }));

            startCamera(root);
        };

        const handlePhotoFile = (input) => {
            const root = overlayFrom(input);
            if (!root) return;

            const file = input.files && input.files[0];
            if (!file) return;
            window.GymieCameraCapture.fileToDataUrl(file, (dataUrl) => setPhoto(root, dataUrl));
            input.value = '';
        };

        window.verifyCamera = { startCamera, capturePhoto, retake, handlePhotoFile, stopCamera };

        document.addEventListener('livewire:init', () => {
            Livewire.on('verify-photo-step', () => startCamera());
        });

        // Stop any camera whose overlay or video element left the DOM — covers
        // overlay close and going back to step 1, regardless of event ordering.
        const stopOrphaned = () => {
            for (const [root, stream] of state.streams) {
                if (!document.contains(root) || !root.querySelector('#verify-camera')) {
                    stream.getTracks().forEach((track) => track.stop());
                    state.streams.delete(root);
                    state.active.delete(root);
                    state.ready.delete(root);
                }
            }
        };

        new MutationObserver(stopOrphaned).observe(componentRoot, { childList: true, subtree: true });
    })();
</script>
