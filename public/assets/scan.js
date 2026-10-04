// QR scanner: opens the camera and goes to the box page of a scanned Studbook box code.
// Uses the browser's BarcodeDetector where available, otherwise the bundled jsQR.
(() => {
    const root = document.getElementById('scan');
    if (!root) {
        return;
    }
    const video = document.getElementById('scan-video');
    const status = document.getElementById('scan-status');
    const startButton = document.getElementById('scan-start');
    const t = (name) => root.dataset['t' + name] || '';
    const boxPath = root.dataset.boxPath;
    let stream = null;
    let detect = null;
    let busy = false;
    let done = false;

    const say = (text) => { status.textContent = text; };

    const loadJsQr = () => new Promise((resolve, reject) => {
        if (window.jsQR) {
            resolve(window.jsQR);
            return;
        }
        const script = document.createElement('script');
        script.src = root.dataset.jsqrUrl;
        script.onload = () => resolve(window.jsQR);
        script.onerror = reject;
        document.head.append(script);
    });

    const makeDetector = async () => {
        if ('BarcodeDetector' in window) {
            try {
                const formats = await window.BarcodeDetector.getSupportedFormats();
                if (formats.includes('qr_code')) {
                    const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                    return async () => {
                        const codes = await detector.detect(video);
                        return codes.length > 0 ? codes[0].rawValue : null;
                    };
                }
            } catch (e) {
                // fall back to jsQR
            }
        }
        const jsQR = await loadJsQr();
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { willReadFrequently: true });
        return async () => {
            const scale = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            const image = context.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
            return code ? code.data : null;
        };
    };

    // Only the box number is taken from the code; we always navigate within this site.
    const handle = (value) => {
        let path = value;
        try {
            path = new URL(value, window.location.href).pathname;
        } catch (e) {
            // not a URL
        }
        const match = path.match(/\/b\/(\d+)\/?$/);
        if (!match) {
            say(`${t('NotStudbook')} ${value}`);
            return;
        }
        done = true;
        say(t('Opening'));
        stop();
        window.location.href = boxPath + match[1];
    };

    const loop = async () => {
        if (done || !stream) {
            return;
        }
        if (!busy && video.readyState >= 2) {
            busy = true;
            try {
                const value = await detect();
                if (value) {
                    handle(value);
                }
            } catch (e) {
                // try the next frame
            }
            busy = false;
        }
        window.setTimeout(() => window.requestAnimationFrame(loop), 200);
    };

    const stop = () => {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
    };

    const start = async () => {
        startButton.hidden = true;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            say(t('NoCamera'));
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            video.srcObject = stream;
            video.hidden = false;
            await video.play();
            detect = await makeDetector();
            say('');
            loop();
        } catch (e) {
            stop();
            say(t('NoCamera'));
            startButton.hidden = false;
        }
    };

    startButton.addEventListener('click', start);
    window.addEventListener('pagehide', stop);
    start();
})();
