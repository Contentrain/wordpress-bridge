/* global ContentrainBridge, wp */
(() => {
  'use strict';
  const { __, sprintf } = wp.i18n;
  const $ = (id) => document.getElementById(`cr-${id}`);
  let job = null;
  let running = false;
  let busy = false;
  let offset = 0;
  let candidates = [];
  async function api(payload) {
    const body = new URLSearchParams({ action: 'contentrain_bridge', nonce: ContentrainBridge.nonce, payload: JSON.stringify(payload) });
    const response = await fetch(ContentrainBridge.ajax, { method: 'POST', credentials: 'same-origin', body });
    let result;
    try { result = await response.json(); } catch { throw new Error(__('WordPress returned an invalid response. Retry to resume your export.', 'contentrain-bridge')); }
    if (!response.ok || !result.success) throw new Error(result.data?.message || __('Request failed. Please retry.', 'contentrain-bridge'));
    return result.data;
  }
  function error(err) { $('error').textContent = err.message; $('error').hidden = false; }
  function render() {
    $('scope').hidden = Boolean(job);
    $('delete').hidden = !job;
    $('pause').hidden = !running;
    $('resume').hidden = !job || running || ['ready', 'review'].includes(job.phase);
    $('review').hidden = !job || job.phase !== 'review';
    $('delivery').hidden = !job || job.phase !== 'ready';
    if (!job) return;
    $('status').textContent = sprintf(__('Stage: %1$s · Content: %2$d · Files: %3$d · Texts to review: %4$d · Coverage notices: %5$d', 'contentrain-bridge'), job.phase, job.counts.posts, job.files, job.unreviewed, job.counts.warnings);
    $('models').replaceChildren();
    for (const model of job.models) {
      const line = document.createElement('p');
      line.textContent = `${model.name} — ${model.kind} (${Object.keys(model.fields || {}).join(', ')})`;
      $('models').append(line);
    }
    if (job.github?.phase === 'done') {
      $('receipt').href = job.github.url;
      $('receipt').hidden = false;
      $('migrate').hidden = false;
      $('repo').value = job.github.repository;
    }
  }
  async function loop() {
    running = true;
    try {
      render();
      while (running && !['review', 'ready'].includes(job.phase)) {
        job = await api({ op: 'step', id: job.id, step: job.step });
        render();
      }
    } finally { running = false; render(); }
    if (job.phase === 'review') await loadCandidates();
  }
  async function loadCandidates() {
    candidates = await api({ op: 'candidates', id: job.id, offset });
    $('candidates').replaceChildren();
    for (const candidate of candidates) {
      const fieldset = document.createElement('fieldset');
      const legend = document.createElement('legend');
      legend.textContent = `${candidate.source}${candidate.line ? ':' + candidate.line : ''} · ${candidate.context}${candidate.occurrences ? ' · ×' + candidate.occurrences.length : ''}${candidate.reason ? ' · ' + candidate.reason : ''}`;
      const text = document.createElement('p');
      text.textContent = candidate.value;
      const keyLabel = document.createElement('label');
      keyLabel.textContent = __('Dictionary key', 'contentrain-bridge');
      const key = document.createElement('input');
      key.type = 'text'; key.value = candidate.key;
      key.addEventListener('input', () => { candidate.key = key.value; });
      keyLabel.append(key);
      const select = document.createElement('select');
      select.setAttribute('aria-label', __('Export decision', 'contentrain-bridge'));
      for (const [value, label] of [['review', __('Choose a decision', 'contentrain-bridge')], ['include', __('Include as editable text', 'contentrain-bridge')], ['exclude', __('Exclude from content', 'contentrain-bridge')]]) {
        const option = document.createElement('option'); option.value = value; option.textContent = label; option.selected = candidate.decision === value; select.append(option);
      }
      select.addEventListener('change', () => { candidate.decision = select.value; });
      fieldset.append(legend, text, keyLabel, select);
      $('candidates').append(fieldset);
    }
    $('prev').disabled = offset === 0;
    $('next').disabled = offset + candidates.length >= job.candidates;
  }
  async function saveReview(finish = false) {
    const decisions = Object.fromEntries(candidates.filter(c => c.decision !== 'review').map(c => [c.id, { key: c.key, decision: c.decision }]));
    job = await api({ op: 'review', id: job.id, decisions, finish });
    render();
  }
  function action(id, fn) {
    $(id).addEventListener('click', async () => {
      if (busy) return;
      busy = true; $(id).disabled = true; $('error').hidden = true;
      try { await fn(); } catch (err) { error(err); } finally { busy = false; $(id).disabled = false; render(); }
    });
  }
  action('create', async () => {
    const types = [...$('types').querySelectorAll('input[type="checkbox"]:checked')].map(input => input.value);
    const labels = Object.fromEntries([...$('types').querySelectorAll('input[type="text"]')].map(input => [input.dataset.type, input.value]));
    job = await api({ op: 'create', types, labels, private: $('private').checked, comments: $('comments').checked, scan_sources: $('scan').checked, scan_plugins: $('plugins').checked, scan_render: $('render').checked, selected_meta: $('meta').value.split(',').map(x => x.trim()).filter(Boolean) });
    await loop();
  });
  action('resume', loop);
  $('pause').addEventListener('click', () => { running = false; });
  action('delete', async () => {
    await api({ op: 'delete', id: job.id }); job = null; offset = 0;
    $('download').hidden = true; $('migrate').hidden = true; $('receipt').hidden = true; $('token').value = ''; $('status').textContent = ''; render();
  });
  action('save-review', () => saveReview());
  action('next', async () => { await saveReview(); offset += 30; await loadCandidates(); });
  action('prev', async () => { await saveReview(); offset = Math.max(0, offset - 30); await loadCandidates(); });
  action('finish', async () => { await saveReview(true); await loop(); });
  action('zip', async () => {
    let result;
    do { result = await api({ op: 'zip', id: job.id }); $('status').textContent = sprintf(__('Preparing archive: %d files', 'contentrain-bridge'), result.cursor); } while (!result.done);
    const url = new URL(ContentrainBridge.download);
    url.search = new URLSearchParams({ action: 'contentrain_bridge_download', id: job.id, _wpnonce: ContentrainBridge.downloadNonce });
    $('download').href = url.href; $('download').hidden = false;
  });
  $('download').addEventListener('click', () => { $('migrate').hidden = false; });
  action('github', async () => {
    if (!ContentrainBridge.secure) throw new Error(__('Open WordPress administration over HTTPS before entering a GitHub token.', 'contentrain-bridge'));
    if (!$('consent').checked) throw new Error(__('Confirm the destination and content before sending.', 'contentrain-bridge'));
    let token = $('token').value;
    $('token').value = '';
    try {
      job = await api({ op: 'github-start', id: job.id, token, repository: $('repo').value.trim(), consent: true, on_conflict: $('conflict').value });
      while (job.github.phase !== 'done') {
        job = await api({ op: 'github-step', id: job.id, token, cursor: job.github.cursor });
        $('status').textContent = sprintf(__('Delivering file step %d', 'contentrain-bridge'), job.github.cursor);
      }
      render();
    } finally { token = ''; }
  });
  (async () => {
    try {
      const inventory = await api({ op: 'inventory' });
      for (const [type, info] of Object.entries(inventory.post_types)) {
        const row = document.createElement('div'); row.className = 'cr-type';
        const label = document.createElement('label');
        const input = document.createElement('input'); input.type = 'checkbox'; input.value = type; input.checked = true;
        label.append(input, document.createTextNode(`${info.label} (${Object.values(info.counts).reduce((a, b) => a + b, 0)})`));
        const name = document.createElement('input'); name.type = 'text'; name.dataset.type = type; name.value = info.label; name.setAttribute('aria-label', sprintf(__('Model name for %s', 'contentrain-bridge'), type));
        row.append(label, name); $('types').append(row);
      }
      if (inventory.active_job) {
        // Keep the id available for deletion even if the job has expired.
        job = { id: inventory.active_job, phase: 'expired', counts: { posts: 0, warnings: 0 }, files: 0, unreviewed: 0, models: [] };
        job = await api({ op: 'status', id: inventory.active_job });
        if (job.phase === 'review') await loadCandidates();
      }
      render();
    } catch (err) { error(err); render(); }
  })();
})();
