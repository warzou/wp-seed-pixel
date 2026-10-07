/* global wpSeedPixelFormat */
document.addEventListener('change', event => {
    if (event.target.matches('[data-format-profile]')) {
        runFormatAction(event.target, 'select');
        return;
    }
    if (!event.target.matches('[data-format-approval]')) return;
    const group = event.target.closest('.pixel-format-confirmation');
    group.querySelector('button').disabled = [...group.querySelectorAll('input')].some(input => !input.checked);
});
document.addEventListener('click', event => {
    const button = event.target.closest('.pixel-format-action');
    if (!button || button.disabled) return;
    runFormatAction(button, button.dataset.operation);
});

async function runFormatAction(button, operation) {
    const panel = button.closest('.pixel-media-panel');
    if (!panel || panel.getAttribute('aria-busy') === 'true') return;
    const status = panel.querySelector('.pixel-format-status');
    const group = button.closest('.pixel-format-confirmation');
    const body = new URLSearchParams({action: 'wp_seed_pixel_format', nonce: wpSeedPixelFormat.nonce,
        operation, attachment_id: button.dataset.id, generation: button.dataset.generation,
        profile: operation === 'select' ? button.dataset.formatProfile : (panel.querySelector('[data-format-profile]:checked')?.dataset.formatProfile || ''),
        confirmed: group ? (group.querySelector('[data-format-approval="confirmed"]').checked ? '1' : '0') : '1',
        provenance: group?.querySelector('[data-format-approval="provenance"]')?.checked ? '1' : '0',
        quality_override: group?.querySelector('[data-format-approval="quality_override"]')?.checked ? '1' : '0',
        urls: group?.querySelector('[data-format-approval="urls"]')?.checked ? '1' : '0'});
    panel.setAttribute('aria-busy', 'true');
    button.disabled = true;
    status.textContent = wpSeedPixelFormat.working;
    try {
        const response = await fetch(wpSeedPixelFormat.url, {method: 'POST', credentials: 'same-origin', body});
        const result = await response.json();
        if (!result.success) throw new Error(result.data?.message || wpSeedPixelFormat.failed);
        const holder = document.createElement('div'); holder.innerHTML = result.data.panel;
        const next = holder.firstElementChild;
        panel.replaceWith(next);
        if (operation === 'select') {
            next.querySelector(`[data-format-profile="${button.dataset.formatProfile}"]`)?.focus();
        } else { next.focus(); }
    } catch (error) { status.textContent = error.message; }
    finally { panel.removeAttribute('aria-busy'); button.disabled = false; }
}
