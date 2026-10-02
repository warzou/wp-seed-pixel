/* global wpSeedPixelMedia */
document.addEventListener('click', async (event) => {
    const button = event.target.closest('.pixel-regenerate');
    if (!button || button.dataset.pixelBusy === '1') return;
    const status = button.parentElement.querySelector('.pixel-media-status');
    const wasFocused = document.activeElement === button;
    button.dataset.pixelBusy = '1';
    button.setAttribute('aria-disabled', 'true');
    status.textContent = wpSeedPixelMedia.processing;
    try {
        const body = new URLSearchParams({ action: 'wp_seed_pixel', operation: 'optimize', nonce: wpSeedPixelMedia.nonce, preset: wpSeedPixelMedia.preset, attachment_id: button.dataset.id, force: '1' });
        const response = await fetch(wpSeedPixelMedia.url, { method: 'POST', credentials: 'same-origin', body });
        const result = await response.json();
        status.textContent = result.success ? result.data.status : result.data.message;
    } catch (error) {
        status.textContent = wpSeedPixelMedia.failed;
    } finally {
        delete button.dataset.pixelBusy;
        button.removeAttribute('aria-disabled');
        if (wasFocused && (document.activeElement === document.body || document.activeElement === button)) button.focus();
    }
});
