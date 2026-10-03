// Small progressive enhancements; every page works without JavaScript.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-print]');
    if (button) {
        window.print();
    }
});
