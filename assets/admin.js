/* global wpSeedPixel */
(() => {
    'use strict';
    const output = document.getElementById('pixel-result');
    if (!output) return;
    let running = false;
    let busy = false;
    const show = (data) => {
        output.textContent = JSON.stringify(data, null, 2);
        const progress = document.getElementById('pixel-progress');
        progress.max = Math.max(1, data.total || 1);
        progress.value = data.processed || 0;
    };
    const request = async (operation, values = {}) => {
        const body = new URLSearchParams({ action: 'wp_seed_pixel', nonce: wpSeedPixel.nonce, operation, ...values });
        const response = await fetch(wpSeedPixel.url, { method: 'POST', credentials: 'same-origin', body });
        const result = await response.json();
        if (!result.success) throw new Error(result.data && result.data.message || wpSeedPixel.error);
        show(result.data);
        return result.data;
    };
    const perform = async (fn) => {
        if (busy) return;
        busy = true;
        try { await fn(); } catch (error) { running = false; output.textContent = error.message; }
        finally { busy = false; }
    };
    const run = async () => {
        while (running) {
            const state = await request('step');
            if (state.status !== 'running') running = false;
        }
    };
    const preset = () => document.getElementById('pixel-preset').value;
    document.getElementById('pixel-start').addEventListener('click', () => perform(async () => {
        if (!document.getElementById('pixel-confirm').checked) {
            document.getElementById('pixel-confirm').focus();
            return;
        }
        await request('start', { preset: preset(), confirmed: '1' });
        running = true;
        await run();
    }));
    document.getElementById('pixel-pause').addEventListener('click', () => {
        running = false;
        const wait = () => busy ? setTimeout(wait, 100) : perform(() => request('pause'));
        wait();
    });
    document.getElementById('pixel-resume').addEventListener('click', () => perform(async () => {
        await request('resume');
        running = true;
        await run();
    }));
    document.getElementById('pixel-retry').addEventListener('click', () => perform(() => request('retry')));
    document.getElementById('pixel-single').addEventListener('submit', (event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        perform(() => request('optimize', { preset: preset(), attachment_id: data.get('attachment_id'), force: data.get('force') || '0' }));
    });
    perform(() => request('status'));
})();
