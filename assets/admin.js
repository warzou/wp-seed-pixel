/* global wpSeedPixel */
(() => {
    'use strict';
    const output = document.getElementById('pixel-result');
    if (!output) return;
    let running = false;
    let busy = false;
    const labels = wpSeedPixel.labels;
    const show = (data) => {
        const current = data.unchanged || 0;
        const heading = labels[data.status] || labels.empty;
        output.textContent = data.status ? `${data.status === 'complete' && data.failed ? labels.partial : heading} - ${data.processed}/${data.total}. ${Math.max(0, (data.success || 0) - current)} ${labels.optimized}, ${current} ${labels.current}, ${data.skipped || 0} ${labels.skipped}, ${data.failed || 0} ${labels.failed}.` : labels.empty;
        const progress = document.getElementById('pixel-progress');
        progress.max = Math.max(1, data.total || 1);
        progress.value = data.processed || 0;
        const failureList = document.getElementById('pixel-failure-items');
        failureList.replaceChildren();
        (data.failure_items || []).forEach(item => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.textContent = item.title;
            link.href = item.url;
            li.appendChild(link);
            failureList.appendChild(li);
        });
        document.getElementById('pixel-failures').hidden = !(data.failure_items || []).length;
        document.getElementById('pixel-start').disabled = Boolean(data.status && data.status !== 'complete');
        document.getElementById('pixel-pause').disabled = data.status !== 'running';
        document.getElementById('pixel-resume').disabled = !data.status || data.status === 'complete' || running;
        const retryable = (data.failed_ids || []).some(id => ((data.retry_counts || {})[id] || 0) < 2);
        document.getElementById('pixel-retry').disabled = data.status !== 'complete' || !retryable;
    };
    const request = async (operation, values = {}) => {
        const body = new URLSearchParams({ action: 'wp_seed_pixel', nonce: wpSeedPixel.nonce, operation, ...values });
        const response = await fetch(wpSeedPixel.url, { method: 'POST', credentials: 'same-origin', body });
        const result = await response.json();
        if (!result.success) throw new Error(result.data && result.data.message || wpSeedPixel.error);
        if (operation === 'optimize') output.textContent = result.data.message || result.data.status;
        else show(result.data);
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
            output.textContent = labels.confirm;
            return;
        }
        await request('start', { preset: preset(), confirmed: '1' });
        running = true;
        document.getElementById('pixel-resume').disabled = true;
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
