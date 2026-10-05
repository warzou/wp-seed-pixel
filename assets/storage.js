/* global wpSeedPixelScan */
(() => {
    'use strict';
    const config = wpSeedPixelScan;
    const labels = config.labels;
    const get = id => document.getElementById('pixel-scan-' + id);
    const number = new Intl.NumberFormat(config.locale);
    const bytes = value => number.format(Number(value || 0)) + ' B';
    let state = null, page = 1, pages = 1, busy = false, activeRun = null, revision = 0, resultRequest = 0, lastError = '';
    const text = (tag, value) => { const element = document.createElement(tag); element.textContent = value; return element; };

    async function request(operation, values = {}) {
        const response = await fetch(config.url, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({action: 'wp_seed_pixel_scan', nonce: config.nonce, operation, job_id: state ? state.id : 0, ...values})});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.data && result.data.message || config.error);
        return result.data;
    }
    function render() {
        const active = state && ['running', 'paused'].includes(state.status);
        get('start').disabled = busy || active;
        get('pause').disabled = busy || !state || state.status !== 'running';
        get('resume').disabled = busy || !state || !(state.status === 'paused' || state.status === 'running' && activeRun === null);
        get('cancel').disabled = busy || !active;
        if (!state) { if (lastError) get('status').textContent = lastError; return; }
        get('status').textContent = lastError || (labels[state.status] || labels.unknown) + ' · ' + state.done + '/' + state.total + ' ' + labels.progress;
        get('progress').max = Math.max(1, state.total);
        get('progress').value = state.done;
        get('bytes').textContent = bytes(state.storage.unique_logical_bytes);
        get('potential').textContent = bytes(state.storage.potential_original_bytes);
        get('uncertain').textContent = bytes(state.storage.uncertain_bytes);
        get('reclaimed').textContent = bytes(state.storage.reclaimed_bytes);
        get('roles').replaceChildren(...Object.entries(state.storage.by_role).map(([role, amount]) => text('li', labels[role] + ': ' + bytes(amount))));
    }
    async function results() {
        if (!state) return;
        const sequence = ++resultRequest;
        const data = await request('results', {page, filter: get('filter').value});
        if (sequence !== resultRequest) return;
        pages = data.pages;
        const cards = data.items.map(item => {
            const article = document.createElement('article'); article.className = 'pixel-scan-item';
            const heading = text('h3', item.title || '#' + item.attachment_id);
            if (item.preview_url) { const image = document.createElement('img'); image.src = item.preview_url; image.alt = ''; image.width = 64; image.height = 64; image.loading = 'lazy'; article.append(image); }
            article.append(heading, text('p', labels[item.health] + (item.stale ? ' · ' + labels.stale : '')));
            if (item.issues && item.issues.length) article.append(text('p', [...new Set(item.issues)].map(issue => labels[issue] || labels.unknown).join(' · ')));
            if (item.image) article.append(text('p', item.image.format.toUpperCase() + ' (' + item.image.reported_mime + ') · ' + (item.image.width || '?') + ' × ' + (item.image.height || '?') + ' · ' + bytes(item.storage.unique_logical_bytes) + ' — ' + labels.related));
            const opportunities = document.createElement('ul');
            (item.opportunities || []).forEach(opportunity => opportunities.append(text('li', labels[opportunity.category] || labels.unknown)));
            article.append(opportunities);
            const details = document.createElement('details'); details.append(text('summary', labels.files));
            const files = document.createElement('ul');
            (item.files || []).forEach(file => files.append(text('li', file.relative_path + ' · ' + bytes(file.logical_bytes) + ' · ' + file.roles.map(role => labels[role]).join(', ') + (!file.exists ? ' · ' + labels.missing : ''))));
            details.append(files); article.append(details); return article;
        });
        get('results').replaceChildren(...(cards.length ? cards : [text('p', labels.nothing)]));
        get('page').textContent = page + ' / ' + pages;
        get('previous').disabled = page <= 1;
        get('next').disabled = page >= pages;
    }
    async function run() {
        const token = revision;
        if (activeRun === token) return;
        activeRun = token;
        try {
            while (state && state.status === 'running' && revision === token) {
                const next = await request('step');
                if (revision !== token) return;
                state = next; render();
                if (state.done % 10 === 0 || state.status !== 'running') await results();
                await new Promise(resolve => setTimeout(resolve, 50));
            }
        } catch (error) { if (revision === token) lastError = error.message; }
        finally { if (activeRun === token) activeRun = null; render(); }
    }
    async function command(operation) {
        if (busy) return; busy = true; revision++; activeRun = null; resultRequest++; lastError = ''; render();
        try { state = await request(operation); page = 1; render(); await results(); if (operation === 'start' || operation === 'resume') run(); }
        catch (error) { lastError = error.message; }
        finally { busy = false; render(); }
    }
    ['start', 'pause', 'resume', 'cancel'].forEach(operation => get(operation).addEventListener('click', () => command(operation)));
    get('filter').addEventListener('change', () => { page = 1; results().catch(error => { get('status').textContent = error.message; }); });
    get('previous').addEventListener('click', () => { page = Math.max(1, page - 1); results().catch(error => { get('status').textContent = error.message; }); });
    get('next').addEventListener('click', () => { page = Math.min(pages, page + 1); results().catch(error => { get('status').textContent = error.message; }); });
    request('status').then(data => { state = data; render(); if (state) return results(); }).catch(error => { get('status').textContent = error.message; });
    // Opening/reloading this page never automatically starts or resumes analysis.
})();
