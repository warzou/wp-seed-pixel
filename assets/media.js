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
        const body = new URLSearchParams({ action: 'wp_seed_pixel', operation: 'optimize', nonce: wpSeedPixelMedia.nonce, preset: wpSeedPixelMedia.preset, attachment_id: button.dataset.id, force: button.dataset.force || '0' });
        const response = await fetch(wpSeedPixelMedia.url, { method: 'POST', credentials: 'same-origin', body });
        const result = await response.json();
        status.textContent = result.success ? result.data.message : wpSeedPixelMedia.failed;
        if (result.success) {
            const panel = button.closest('.pixel-media-panel');
            if (panel && result.data.panel) {
                const holder = document.createElement('div');
                holder.innerHTML = result.data.panel;
                const next = holder.firstElementChild;
                panel.replaceWith(next);
                const nextButton = next.querySelector('.pixel-regenerate');
                if (wasFocused && nextButton) nextButton.focus();
            } else {
                button.dataset.force = '1';
                button.textContent = wpSeedPixelMedia.regenerate;
                const row = button.closest('tr');
                const state = row && row.querySelector('.column-wp_seed_pixel .pixel-state');
                if (state) state.textContent = result.data.message;
            }
        }
    } catch (error) {
        status.textContent = wpSeedPixelMedia.failed;
    } finally {
        delete button.dataset.pixelBusy;
        button.removeAttribute('aria-disabled');
        if (wasFocused && (document.activeElement === document.body || document.activeElement === button)) button.focus();
    }
});
