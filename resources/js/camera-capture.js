

const waitForMetadata = (video) => {
    if (video.readyState >= 1) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const onMeta = () => { cleanup(); resolve(); };
        const onTimeout = () => { cleanup(); resolve(); };
        const cleanup = () => {
            video.removeEventListener('loadedmetadata', onMeta);
            clearTimeout(timer);
        };
        video.addEventListener('loadedmetadata', onMeta, { once: true });
        var timer = setTimeout(onTimeout, 5000);
    });
};

const waitForPlaying = (video) => new Promise((resolve) => {
    let settled = false;
    const done = (result) => {
        if (settled) {
            return;
        }
        settled = true;
        cleanup();
        resolve(result);
    };
    const onPlaying = () => done(true);
    const onError = () => done(false);
    const cleanup = () => {
        video.removeEventListener('playing', onPlaying);
        video.removeEventListener('error', onError);
        clearTimeout(timer);
    };
    video.addEventListener('playing', onPlaying, { once: true });
    video.addEventListener('error', onError, { once: true });
    var timer = setTimeout(() => done(false), 5000);
    video.play().catch(() => done(false));
});

async function start(video, { onReady = () => {}, onNoCamera = () => {} } = {}) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        onNoCamera();
        return null;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 } },
            audio: false,
        });
        video.srcObject = stream;

        if (!(await waitForPlaying(video))) {
            stop(video);
            onNoCamera();
            return null;
        }

        await waitForMetadata(video);
        onReady(stream);
        return stream;
    } catch (err) {
        onNoCamera();
        return null;
    }
}

function capture(video) {
    if (!video || !video.videoWidth) {
        return null;
    }

    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);

    return canvas.toDataURL('image/jpeg', 0.85);
}

function stop(video) {
    if (!video) {
        return;
    }

    const stream = video.srcObject;
    if (stream && stream.getTracks) {
        stream.getTracks().forEach((track) => track.stop());
    }
    video.srcObject = null;
}

function fileToDataUrl(file, onDataUrl) {
    if (!file) {
        return;
    }

    const reader = new FileReader();
    reader.onload = () => onDataUrl(String(reader.result));
    reader.readAsDataURL(file);
}

window.GymieCameraCapture = { start, capture, stop, fileToDataUrl };
