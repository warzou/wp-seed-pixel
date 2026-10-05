/* global wpSeedPixelBulk, wp */
(() => {
    'use strict';
    const cfg = wpSeedPixelBulk, labels = cfg.labels, $ = id => document.getElementById(`pixel-bulk-${id}`);
    let id = 0, page = 1, pages = 1, epoch = 0, busy = false, running = false;
    let transport = Promise.resolve();
    const label = key => labels[key] || key;
    async function request(operation, extra = {}) {
        const body = new URLSearchParams({action:'wp_seed_pixel_bulk',nonce:cfg.nonce,operation,job_id:String(id),...extra});
        // A pause/cancel waits for the current fenced item, never races its own request.
        const call = transport.then(async () => {
            const response = await fetch(cfg.url, {method:'POST', credentials:'same-origin', body});
            const json = await response.json();
            if (!json.success) throw Error(json.data?.message || label(json.data?.code) || labels.error);
            return json.data;
        });
        transport = call.catch(() => {});
        return call;
    }
    function error(e) { $('error').textContent = e.message; running = false; }
    function render(s) {
        $('status').textContent = s.id ? `${label(s.kind)} #${s.id}: ${label(s.status)} (${s.done}/${s.total})` : labels.empty;
        $('fingerprint').textContent = s.id ? `${s.policy_hash} / ${s.plan_hash}` : '';
        $('progress').max = Math.max(1, Number(s.total || 0)); $('progress').value = Number(s.kind === 'plan' ? s.planned : s.done) || 0;
        document.querySelectorAll('[data-command]').forEach(b => {
            const op = b.dataset.command;
            b.disabled = !s.id || (op === 'start' ? s.kind !== 'plan' || s.status !== 'completed' :
                s.kind === 'plan' ? !(op === 'resume' && s.status === 'queued') : (op === 'pause' ? s.status !== 'running' :
                op === 'resume' ? !['running','paused','failed_systemic'].includes(s.status) :
                op === 'cancel' ? !['running','paused','failed_systemic'].includes(s.status) :
                op === 'retry' ? !['paused','failed_systemic','completed_errors'].includes(s.status) : false));
        });
    }
    async function results() {
        const e = epoch, r = await request('results',{page:String(page)}); if(e !== epoch) return;
        pages = r.pages; $('results').replaceChildren();
        const table=document.createElement('table'), head=table.createTHead().insertRow();
        for(const key of ['attachment','state','reason']) { const th=document.createElement('th'); th.scope='col'; th.textContent=label(key); head.append(th); }
        const body=table.createTBody();
        for(const item of r.items) { const tr=body.insertRow(); for(const value of [item.attachment_id,label(item.stage),label(item.error_code || item.reason || '-')]) tr.insertCell().textContent=value; }
        $('results').append(table); $('page').textContent=`${page} / ${pages}`; $('prev').disabled=page<=1; $('next').disabled=page>=pages;
    }
    function metrics(m) {
        $('metrics').replaceChildren();
        for(const key of ['current_active_bytes','active_saved_bytes','quarantine_bytes','potential_purge_bytes','removed_source_bytes','allocated_reclaimed_bytes','net_reclaimed_bytes','unknown_items']) {
            const dt=document.createElement('dt'), dd=document.createElement('dd'); dt.textContent=label(key);
            dd.textContent=m[key] === null ? labels.unknown : `${Number(m[key]).toLocaleString()}${key==='unknown_items' ? '' : ' B'}`;
            $('metrics').append(dt,dd);
        }
    }
    function select(s) {
        id=Number(s.id); page=1;
        if(!Array.from($('job').options).some(o=>Number(o.value)===id)) $('job').add(new Option(`#${id}`,String(id)));
        $('job').value=String(id); $('metrics').replaceChildren(); render(s);
    }
    async function pump(s, e) {
        running=true;
        while(e===epoch && running && ((s.kind==='plan' && s.status==='queued') || (s.kind!=='plan' && s.status==='running'))) {
            await new Promise(r=>setTimeout(r,500)); if(e!==epoch || !running) break;
            s=await request('step'); if(e!==epoch) break; render(s); await results();
        }
        running=false;
    }
    async function command(op, extra={}) {
        if(busy) return; busy=true; $('error').textContent='';
        try {
            running=false; const e=++epoch;
            if(op==='audit') metrics(await request(op));
            else { const s=await request(op,extra); select(s); await results();
                if(['plan','start','resume'].includes(op)) { busy=false; await pump(s,e); }
            }
        } catch(e) { error(e); } finally { busy=false; }
    }
    $('policy').addEventListener('submit',e=>{e.preventDefault(); if($('policy').reportValidity()) command('plan',Object.fromEntries(new FormData($('policy'))));});
    $('select').addEventListener('click', () => {
        const frame = wp.media({title: $('select').textContent, multiple: true, library: {type: 'image'}});
        frame.on('select', () => {
            const ids = frame.state().get('selection').map(item => item.id);
            $('selection').value = ids.join(',');
            $('selection-status').textContent = ids.length + ' / 500';
        });
        frame.open();
    });
    document.querySelectorAll('[data-command]').forEach(b=>b.addEventListener('click',()=>command(b.dataset.command)));
    $('job').addEventListener('change',async()=>{ if(busy)return; running=false; ++epoch; id=Number($('job').value); page=1; $('metrics').replaceChildren(); if(!id) {render({});return;} try {render(await request('status'));await results();} catch(e){error(e);} });
    $('prev').addEventListener('click',()=>{page=Math.max(1,page-1);results().catch(error);});
    $('next').addEventListener('click',()=>{page=Math.min(pages,page+1);results().catch(error);});
    render({});
})();
