// Photo upload: shows a preview and shrinks large photos in the browser before upload,
// so phone pictures stay under the server's upload limit. Without JavaScript the photo
// is sent as it is.
(() => {
    const MAX_SIDE = 2400;
    const MAX_BYTES = 1500000;

    document.querySelectorAll('[data-photo-input]').forEach((input) => {
        const preview = input.form ? input.form.querySelector('[data-photo-preview]') : null;
        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            if (!file || !file.type.startsWith('image/')) {
                return;
            }
            if (preview) {
                preview.src = URL.createObjectURL(file);
                preview.hidden = false;
            }
            if (!window.createImageBitmap || typeof DataTransfer === 'undefined') {
                return;
            }
            try {
                const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
                if (scale === 1 && file.size <= MAX_BYTES) {
                    return;
                }
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(bitmap.width * scale);
                canvas.height = Math.round(bitmap.height * scale);
                canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
                if (!blob) {
                    return;
                }
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'photo.jpg', { type: 'image/jpeg' }));
                input.files = transfer.files;
            } catch (e) {
                // keep the original file
            }
        });
    });

    document.querySelectorAll('[data-photo-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('[data-busy]');
            if (button) {
                window.setTimeout(() => {
                    button.disabled = true;
                    button.textContent = button.dataset.busy;
                }, 0);
            }
        });
    });
})();
