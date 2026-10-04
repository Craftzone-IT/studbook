// Small progressive enhancements; every page works without JavaScript.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-print]');
    if (button) {
        window.print();
    }
});

// Part and set pictures that were not downloaded yet come back as a "pending" picture
// (the server limits parallel downloads); ask again a few times, waiting longer each time.
// The pending picture is recognised by its size (41 x 41): after a redirect the image
// still reports its original address.
document.addEventListener('load', (event) => {
    const img = event.target;
    if (!(img instanceof HTMLImageElement) || img.naturalWidth !== 41 || img.naturalHeight !== 41) {
        return;
    }
    const tries = Number(img.dataset.retries || 0);
    if (tries >= 5) {
        return;
    }
    img.dataset.retries = String(tries + 1);
    img.dataset.original = img.dataset.original || img.getAttribute('src');
    const original = img.dataset.original;
    window.setTimeout(() => {
        img.src = `${original}${original.includes('?') ? '&' : '?'}retry=${tries + 1}`;
    }, 1500 * 2 ** tries);
}, true);
