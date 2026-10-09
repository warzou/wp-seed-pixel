/* global wpSeedPixelMetadata */
document.addEventListener('change', event => {
    const section = event.target.closest('.pixel-metadata');
    if (!section) return;
    const start = section.querySelector('[data-operation="start"]');
    if (start) start.disabled = !(section.querySelector('[value="anonymize"]').checked && section.querySelector('.pixel-metadata-approval').checked);
});
document.addEventListener('click', async event => {
    const button = event.target.closest('.pixel-metadata-action');
    if (!button || button.disabled || button.dataset.busy) return;
    const section = button.closest('.pixel-metadata');
    const status = section.querySelector('.pixel-metadata-status');
    const request = async (operation, values = {}) => {
        const response = await fetch(wpSeedPixelMetadata.url, {method: 'POST', credentials: 'same-origin',
            body: new URLSearchParams({action: 'wp_seed_pixel_metadata', nonce: wpSeedPixelMetadata.nonce, attachment_id: section.dataset.id, operation, ...values})});
        const result = await response.json();
        if (!result.success) throw Error(result.data?.message || wpSeedPixelMetadata.failed);
        return result.data;
    };
    button.dataset.busy = '1'; button.disabled = true; status.textContent = wpSeedPixelMetadata.working;
    try {
        let result = await request(button.dataset.operation, {signature: button.dataset.signature || '', metadata: 'anonymize', confirmed: section.querySelector('.pixel-metadata-approval')?.checked ? '1' : '0'});
        for (let step = 0; result.state && result.state.status === 'running' && step < 8; step++) result = await request('step');
        if (result.state && result.state.status !== 'completed') throw Error(wpSeedPixelMetadata.failed);
        const whole = result.media_panel && section.closest('.pixel-media-panel');
        const holder = document.createElement('div'); holder.innerHTML = whole ? result.media_panel : result.panel;
        const next = holder.firstElementChild; if (!next) throw Error(wpSeedPixelMetadata.failed);
        (whole || section).replaceWith(next);
        const selected = next.querySelector(`[value="${button.dataset.operation === 'analyze' ? 'anonymize' : 'keep'}"]`);
        if (selected) selected.checked = true;
        next.querySelector('button:not(:disabled)')?.focus();
    } catch (error) { status.textContent = error.message; }
    finally {
        delete button.dataset.busy;
        button.disabled = button.dataset.operation === 'start' && !(section.querySelector('[value="anonymize"]')?.checked && section.querySelector('.pixel-metadata-approval')?.checked);
    }
});
