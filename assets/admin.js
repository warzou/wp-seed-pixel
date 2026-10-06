/* global wpSeedPixel, wpSeedPixelSelection, wp */
(() => {
    'use strict';
    const output = document.getElementById('pixel-result');
    if (!output) return;
    let running = false;
    let busy = false;
    let selection = [];
    let activeBatch = false;
    let native = false, jobId = 0, phase = '';
    const labels = wpSeedPixel.labels;
    const show = (data) => {
        native = Boolean(data.native); jobId = data.id || 0; phase = data.phase || '';
        const current = data.unchanged || 0;
        const heading = labels[data.status] || labels.empty;
        const count = (kind, value) => wpSeedPixel.counters[value === 0 ? 0 : value === 1 ? 1 : 2][kind].replace('%d', value);
        output.textContent = data.status ? `${data.status === 'complete' && data.failed ? labels.partial : heading} - ${data.processed}/${data.total}. ${count('optimized', Math.max(0, (data.success || 0) - current))}, ${count('current', current)}, ${count('skipped', data.skipped || 0)}, ${count('failed', data.failed || 0)}.` : labels.empty;
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
        activeBatch = Boolean(data.status && data.status !== 'complete');
        document.getElementById('pixel-select').disabled = activeBatch;
        document.getElementById('pixel-selected-start').disabled = !selection.length || selection.length > 500 || activeBatch;
        document.getElementById('pixel-pause').disabled = data.status !== 'running';
        document.getElementById('pixel-pause').hidden = data.status !== 'running' || phase === 'plan';
        document.getElementById('pixel-cancel').hidden = !native || !activeBatch || phase === 'plan' || phase === 'ready';
        document.getElementById('pixel-resume').disabled = !data.status || data.status === 'complete' || running;
        document.getElementById('pixel-resume').hidden = !activeBatch || running;
        const retryable = (data.failed_ids || []).some(id => ((data.retry_counts || {})[id] || 0) < 2);
        document.getElementById('pixel-retry').disabled = data.status !== 'complete' || !retryable;
        document.getElementById('pixel-retry').hidden = data.status !== 'complete' || !retryable;
    };
    const request = async (operation, values = {}) => {
        const body = new URLSearchParams({ action: 'wp_seed_pixel', nonce: wpSeedPixel.nonce, operation, ...values });
        const response = await fetch(wpSeedPixel.url, { method: 'POST', credentials: 'same-origin', body });
        const result = await response.json();
        if (!result.success) throw new Error(result.data && result.data.message || wpSeedPixel.error);
        if (operation === 'optimize') output.textContent = result.data.message || wpSeedPixel.error;
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
            const state = await request(native ? 'native_step' : 'step', native ? {job_id: String(jobId)} : {});
            if (state.native && state.phase === 'ready') {
                await request('native_start', {job_id: String(jobId)});
                continue;
            }
            if (state.status !== 'running') running = false;
        }
    };
    const preset = () => document.getElementById('pixel-preset').value;
    let frame;
    document.getElementById('pixel-select').addEventListener('click', () => {
        if (!frame) {
            frame = wp.media({title: wpSeedPixelSelection.choose, multiple: true, library: {type: 'image'}, button: {text: wpSeedPixelSelection.choose}});
            frame.on('open', () => frame.content.mode('browse'));
            frame.on('select', () => {
                const items = frame.state().get('selection').toJSON();
                selection = [...new Set(items.map(item => Number(item.id)))];
                const count = document.getElementById('pixel-selection-count');
                count.textContent = wpSeedPixelSelection.count.replace('%d', selection.length);
                const review = document.getElementById('pixel-selection-review');
                review.replaceChildren();
                items.slice(0, 10).forEach(item => { const li = document.createElement('li'); li.textContent = item.title + ' (' + (item.mime || item.type) + ')'; review.append(li); });
                const start = document.getElementById('pixel-selected-start');
                start.textContent = wpSeedPixelSelection.start.replace('%d', selection.length);
                start.disabled = !selection.length || selection.length > 500 || activeBatch;
                start.focus();
            });
        }
        frame.open();
    });
    document.getElementById('pixel-selected-start').addEventListener('click', () => perform(async () => {
        if (!selection.length || selection.length > 500) return;
        await request('selected', {preset: preset(), selection: selection.join(',')});
        running = true;
        await run();
    }));
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
        const wait = () => busy ? setTimeout(wait, 100) : perform(() => request(native ? 'native_pause' : 'pause', native ? {job_id: String(jobId)} : {}));
        wait();
    });
    document.getElementById('pixel-resume').addEventListener('click', () => perform(async () => {
        if (!native || phase !== 'plan') {
            await request(native ? (phase === 'ready' ? 'native_start' : 'native_resume') : 'resume', native ? {job_id: String(jobId)} : {});
        }
        running = true;
        await run();
    }));
    document.getElementById('pixel-retry').addEventListener('click', () => perform(() => request('retry')));
    document.getElementById('pixel-cancel').addEventListener('click', () => {
        running = false;
        const wait = () => busy ? setTimeout(wait, 100) : perform(() => request('native_cancel', {job_id: String(jobId)}));
        wait();
    });
    document.getElementById('pixel-single').addEventListener('submit', (event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        perform(() => request('optimize', { preset: preset(), attachment_id: data.get('attachment_id'), force: data.get('force') || '0' }));
    });
    perform(() => request('status'));
})();
