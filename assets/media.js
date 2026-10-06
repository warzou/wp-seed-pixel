/* global wpSeedPixelMedia */
document.addEventListener('change', event => {
    if (!event.target.matches('.pixel-delete-approval')) return;
    event.target.closest('.pixel-delete-confirmation').querySelector('button').disabled = !event.target.checked;
});
document.addEventListener('click', async event => {
    const button = event.target.closest('.pixel-regenerate');
    if (!button || button.disabled || button.dataset.pixelBusy === '1') return;
    const panel = button.closest('.pixel-media-panel');
    const status = button.parentElement.querySelector('.pixel-media-status');
    const wasFocused = document.activeElement === button;
    const request = async (operation, values = {}) => {
        const body = new URLSearchParams({action: 'wp_seed_pixel', nonce: wpSeedPixelMedia.nonce, operation, ...values});
        const response = await fetch(wpSeedPixelMedia.url, {method: 'POST', credentials: 'same-origin', body});
        const result = await response.json();
        if (!result.success) throw new Error(result.data?.message || wpSeedPixelMedia.failed);
        return result.data;
    };
    button.dataset.pixelBusy = '1';
    button.setAttribute('aria-disabled', 'true');
    status.textContent = wpSeedPixelMedia.processing;
    try {
        const operation = button.dataset.operation || 'image_start';
        const approval = operation === 'image_purge' && button.closest('details').querySelector('.pixel-delete-approval').checked;
        let state = await request(operation, {attachment_id: button.dataset.id, generation: button.dataset.generation || '', confirmed: approval ? '1' : '0'});
        if (operation === 'image_start') {
            while (state.status !== 'complete') {
                state = await request(state.phase === 'ready' ? 'native_start' : 'native_step', {job_id: String(state.id)});
                if (state.status === 'paused' && state.phase !== 'ready') throw new Error(wpSeedPixelMedia.failed);
            }
            state = await request('image_panel', {attachment_id: button.dataset.id});
        }
        if (panel && state.panel) {
            const holder = document.createElement('div');
            holder.innerHTML = state.panel;
            const next = holder.firstElementChild;
            panel.replaceWith(next);
            if (wasFocused) (next.querySelector('button:not(:disabled)') || next).focus();
        } else {
            const data = await request('image_panel', {attachment_id: button.dataset.id});
            const holder = document.createElement('div'); holder.innerHTML = data.panel;
            const label = holder.querySelector('.pixel-state') || holder.querySelector('.pixel-media-current');
            status.textContent = label?.textContent || '';
            button.hidden = true;
            const row = button.closest('tr');
            const cell = row?.querySelector('.column-wp_seed_pixel .pixel-state');
            if (cell) cell.textContent = label?.textContent || '';
        }
    } catch (error) { status.textContent = error.message; }
    finally {
        delete button.dataset.pixelBusy;
        button.removeAttribute('aria-disabled');
        if (wasFocused && button.isConnected) button.focus();
    }
});
