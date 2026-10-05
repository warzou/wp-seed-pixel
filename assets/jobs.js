(() => {
    'use strict';
    const cfg = window.wpSeedPixelJobs;
    if (!cfg) return;
    const el = id => document.getElementById('pixel-job-' + id);
    const label = value => (job.kind === 'replace' ? cfg.labels['real_' + value] : '') || cfg.labels[value] || value;
    let job = {id: 0}, page = 1, generation = 0, listing = 0, busy = false, activeRunner = false;
    async function request(operation, extra = {}) {
        const response = await fetch(cfg.url, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({action: 'wp_seed_pixel_jobs', nonce: cfg.nonce, operation, job_id: job.id || 0, ...extra})});
        const body = await response.json();
        if (!response.ok || !body.success) throw Error(body.data?.message || label(body.data?.code) || cfg.labels.error);
        return body.data;
    }
    function render() {
        el('status').textContent = job.id ? `${label(job.kind)} #${job.id}: ${label(job.status)} (${job.kind === 'plan' ? job.planned : job.done}/${job.total})` : cfg.labels.empty;
        el('progress').max = Math.max(1, Number(job.total || 1));
        el('progress').value = Number((job.kind === 'plan' ? job.planned : job.done) || 0);
        el('policy').textContent = job.policy_hash || '-'; el('plan').textContent = job.plan_hash || '-';
        document.querySelector('[data-command="plan"]').disabled = busy;
        el('start').disabled = busy || job.kind !== 'plan' || job.status !== 'completed';
        el('pause').disabled = busy || job.kind !== 'simulation' || job.status !== 'running';
        el('resume').disabled = busy || job.kind !== 'simulation' || !['paused', 'running', 'failed_systemic'].includes(job.status) || (job.status === 'running' && activeRunner);
        el('cancel').disabled = busy || job.kind !== 'simulation' || !['running', 'paused', 'failed_systemic'].includes(job.status);
        el('retry').disabled = busy || job.kind !== 'simulation' || !['paused', 'failed_systemic', 'completed_errors'].includes(job.status);
    }
    async function results() {
        const seq = ++listing, id = job.id;
        const data = await request('results', {page});
        if (seq !== listing || id !== job.id) return;
        const fragment = document.createDocumentFragment();
        for (const row of data.items) {
            const section = document.createElement('section'); section.className = 'pixel-job-item';
            const title = document.createElement('h3'); title.textContent = `${cfg.labels.attachment} ${row.attachment_id}`;
            const state = document.createElement('p'); state.textContent = `${cfg.labels.state}: ${label(row.stage)}`;
            const reason = document.createElement('p'); reason.textContent = row.error_code || row.reason ? `${cfg.labels.reason}: ${label(row.error_code || row.reason)}` : '';
            section.append(title, state, reason); fragment.append(section);
        }
        el('results').replaceChildren(fragment); el('page').textContent = `${data.page} / ${data.pages}`;
        el('previous').disabled = page <= 1; el('next').disabled = page >= data.pages;
    }
    async function run(ticket) {
        activeRunner = true; render();
        while (ticket === generation && ((job.kind === 'plan' && job.status === 'queued') || (job.kind === 'simulation' && job.status === 'running'))) {
            const next = await request('step');
            if (ticket !== generation) return;
            job = next; render();
            await new Promise(resolve => setTimeout(resolve, 80));
        }
        if (ticket === generation) await results();
    }
    async function command(operation) {
        const ticket = ++generation; ++listing; activeRunner = false; busy = true; render(); el('error').textContent = '';
        try {
            job = await request(operation); page = 1; busy = false; render(); await results();
            if (['plan', 'start', 'resume'].includes(operation)) await run(ticket);
        } catch (error) { if (ticket === generation) el('error').textContent = error.message || cfg.labels.error; }
        finally { if (ticket === generation) { busy = false; activeRunner = false; render(); } }
    }
    document.querySelector('[data-command="plan"]').addEventListener('click', () => command('plan'));
    for (const operation of ['start', 'pause', 'resume', 'cancel', 'retry']) el(operation).addEventListener('click', () => command(operation));
    for (const [name, delta] of [['previous', -1], ['next', 1]]) el(name).addEventListener('click', async () => { page += delta; try { await results(); } catch (error) { el('error').textContent = error.message; } });
    request('status').then(value => { job = value; render(); return results(); }).catch(error => { el('error').textContent = error.message; });
})();
