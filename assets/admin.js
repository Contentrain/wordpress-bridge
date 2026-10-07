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
  // A request the host cut short, an HTML error page, a busy export: the export is saved, so these are retried.
  const HOST_STATUS = [500, 502, 503, 504, 520, 521, 522, 524];
  const RETRY_AFTER = [2000, 5000, 10000];
  const STEP_TIMEOUT = 120000;
  const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  function retryable(message) { return Object.assign(new Error(message), { retryable: true }); }
  async function api(payload, timeout = 0) {
    const body = new URLSearchParams({ action: 'contentrain_bridge', nonce: ContentrainBridge.nonce, payload: JSON.stringify(payload) });
    const controller = new AbortController();
    const timer = timeout ? setTimeout(() => controller.abort(), timeout) : 0;
    let response;
    let text;
    try {
      response = await fetch(ContentrainBridge.ajax, { method: 'POST', credentials: 'same-origin', body, signal: controller.signal });
      text = await response.text();
    } catch (err) {
      throw retryable(controller.signal.aborted ? __('the server did not answer in time', 'contentrain-bridge') : __('the connection to the server was lost', 'contentrain-bridge'));
    } finally { clearTimeout(timer); }
    let result;
    try { result = JSON.parse(text); } catch {
      if (HOST_STATUS.includes(response.status) || response.ok) throw retryable(sprintf(__('the host answered with an error page (HTTP %d)', 'contentrain-bridge'), response.status));
      throw new Error(sprintf(__('WordPress returned an invalid response (HTTP %d). Retry to resume your export.', 'contentrain-bridge'), response.status));
    }
    if (!response.ok || !result.success) {
      if (result.data?.busy) throw retryable(result.data.message || __('the export is busy', 'contentrain-bridge'));
      if (HOST_STATUS.includes(response.status)) throw retryable(sprintf(__('the server answered with HTTP %d', 'contentrain-bridge'), response.status));
      throw new Error(result.data?.message || __('Request failed. Please retry.', 'contentrain-bridge'));
    }
    return result.data;
  }
  // The same position three times running means the server is not advancing: say so instead of looping.
  function stall(what, retry) {
    let last; let same = 0;
    return (position) => {
      same = position === last ? same + 1 : 0;
      last = position;
      if (same >= 3) throw stopped(sprintf(__('The %s did not advance.', 'contentrain-bridge'), what), __('Retry; if it repeats, delete the export and start again.', 'contentrain-bridge'), retry);
    };
  }
  // Stages as the screen names them (ContentrainBridge.stages, in order); the first seven do the work, review waits for the person, ready ends.
  const STAGES = ContentrainBridge.stages || [];
  const WORK = STAGES.map(s => s.id).filter(id => !['review', 'ready'].includes(id));
  const label = (phase) => (STAGES.find(s => s.id === phase) || { label: phase }).label;
  // Keyboard and screen-reader users land on what just happened: the error, the review, the result. Never while they type.
  function focus(id) { const el = $(id); if (el && typeof el.focus === 'function') el.focus(); }
  /**
   * An error as a card: what happened, what to do, and a way out — `err.hint` says what to do, `err.retry` names
   * the button Retry presses again, `err.restart` offers deleting the export. Plain errors show the message alone.
   */
  function error(err) {
    $('error-heading').textContent = err.heading || __('Something went wrong', 'contentrain-bridge');
    $('error-message').textContent = err.message;
    $('error-hint').textContent = err.hint || '';
    $('error-hint').hidden = !err.hint;
    $('retry').hidden = !err.retry;
    $('retry').dataset.target = err.retry || '';
    $('restart').hidden = !err.restart;
    $('error').hidden = false;
    focus('error-title');
  }
  const stopped = (message, hint, retry) => Object.assign(new Error(message), { heading: __('The export stopped', 'contentrain-bridge'), hint, retry });
  // What is happening right now, for the eye (the progress line) and for the screen reader (the live region).
  function say(text) { $('status').textContent = text; $('current').textContent = text; }
  // The working stage last seen: an export that fails loses its phase, and the list still shows where it stopped.
  let lastPhase = null;
  // A stage is skipped when the chosen scope leaves it nothing to do; the server records that as a coverage notice.
  function skipped(stage) {
    const scope = job.scope || {};
    if (stage === 'media') return Array.isArray(scope.types) && !scope.types.includes('attachment');
    if (stage === 'comments') return scope.comments === false;
    return false;
  }
  /** Done stages plus the current stage's own share, over the working stages; null when the export is not in one. */
  function percent() {
    if (['review', 'ready'].includes(job.phase)) return 100;
    const at = WORK.indexOf(job.phase);
    if (at < 0) return null;
    const p = job.progress;
    const share = p && p.phase === job.phase && p.total > 0 ? Math.min(1, p.done / p.total) : 0;
    return Math.min(99, Math.round(((at + share) / WORK.length) * 100));
  }
  function renderSteps() {
    if (WORK.includes(job.phase)) lastPhase = job.phase;
    const failedAt = job.phase === 'failed' ? (job.error?.stage || job.progress?.phase || lastPhase) : null;
    const at = STAGES.findIndex(s => s.id === (failedAt || job.phase));
    for (const item of $('steps').querySelectorAll('[data-stage]')) {
      const stage = item.dataset.stage;
      const index = STAGES.findIndex(s => s.id === stage);
      let state = 'pending';
      if (index < at || job.phase === 'ready') state = skipped(stage) ? 'skipped' : 'done';
      else if (index === at) state = failedAt ? 'failed' : stage === 'review' ? 'waiting' : 'current';
      item.className = `cr-step is-${state}`;
      if (state === 'current' || state === 'waiting') item.setAttribute('aria-current', 'step'); else item.removeAttribute('aria-current');
      item.querySelector('.cr-step-state').textContent = { done: __('done', 'contentrain-bridge'), skipped: __('skipped', 'contentrain-bridge'), current: __('in progress', 'contentrain-bridge'), waiting: __('waiting for you', 'contentrain-bridge'), failed: __('stopped', 'contentrain-bridge'), pending: '' }[state];
    }
  }
  // The live region gets a stage change and every quarter of the way, not every batch: a screen reader would read nothing else.
  let announced = { phase: null, quarter: -1 };
  function renderProgress() {
    const pct = percent();
    if (pct !== null) {
      $('bar').value = pct;
      $('bar').className = pct === 100 ? 'is-done' : '';
      $('percent').textContent = `${pct}%`;
    }
    const at = WORK.indexOf(job.phase);
    const p = job.progress && job.progress.phase === job.phase ? job.progress : null;
    let current = '';
    if (at >= 0) {
      current = sprintf(__('Stage %1$d of %2$d: %3$s', 'contentrain-bridge'), at + 1, WORK.length, label(job.phase));
      if (p && p.total > 0) current += ' · ' + sprintf(__('%1$d of %2$d', 'contentrain-bridge'), Math.min(p.done, p.total), p.total);
      if (p && p.current) current += ' · ' + sprintf(__('Latest: %s', 'contentrain-bridge'), p.current);
    } else if (job.phase === 'review') current = __('Every stage is done. Review the models and interface text below, then validate and finalize.', 'contentrain-bridge');
    else if (job.phase === 'ready') current = __('Done. Your content is ready below.', 'contentrain-bridge');
    $('current').textContent = current;
    const quarter = pct === null ? -1 : Math.floor(pct / 25);
    if (job.phase !== announced.phase) {
      announced = { phase: job.phase, quarter };
      if (at >= 0) $('status').textContent = sprintf(__('Stage %1$d of %2$d: %3$s', 'contentrain-bridge'), at + 1, WORK.length, label(job.phase));
      else if (job.phase === 'review') $('status').textContent = __('Export paused for your review.', 'contentrain-bridge');
      else if (job.phase === 'ready') $('status').textContent = __('Export finished.', 'contentrain-bridge');
      else if (job.phase === 'failed') $('status').textContent = __('Export stopped.', 'contentrain-bridge');
    } else if (quarter > announced.quarter && at >= 0) {
      announced.quarter = quarter;
      $('status').textContent = sprintf(__('%d%% done', 'contentrain-bridge'), pct);
    }
  }
  function renderCounts() {
    $('count-posts').textContent = String(job.counts.posts || 0);
    $('count-media').textContent = String(job.counts.media || 0);
    $('count-files').textContent = String(job.files || 0);
    $('count-texts').textContent = String(job.unreviewed || 0);
    $('count-warnings').textContent = String(job.counts.warnings || 0);
    if (job.phase === 'ready') $('done-summary').textContent = sprintf(__('%1$d content entries, %2$d media records and %3$d files were exported; %4$d coverage notices to read.', 'contentrain-bridge'), job.counts.posts || 0, job.counts.media || 0, job.files || 0, job.counts.warnings || 0);
  }
  function render() {
    $('scope').hidden = Boolean(job);
    $('progress').hidden = !job;
    $('delete').hidden = !job;
    $('pause').hidden = !running;
    $('resume').hidden = !job || running || ['ready', 'review', 'failed'].includes(job.phase);
    $('review').hidden = !job || job.phase !== 'review';
    $('delivery').hidden = !job || job.phase !== 'ready';
    if (!job) return;
    renderSteps();
    renderProgress();
    renderCounts();
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
      $('base').value = job.github.base_branch || '';
    }
  }
  async function loop() {
    running = true;
    try {
      render();
      let failures = 0;
      while (running && !['review', 'ready', 'failed'].includes(job.phase)) {
        try {
          job = await api({ op: 'step', id: job.id, step: job.step }, STEP_TIMEOUT);
          failures = 0;
          $('error').hidden = true;
          render();
        } catch (err) {
          if (!err.retryable) throw err;
          if (failures >= RETRY_AFTER.length) {
            $('status').textContent = '';
            throw stopped(
              sprintf(__('The export stopped at stage “%1$s” because %2$s.', 'contentrain-bridge'), label(job.phase), err.message),
              __('Your progress is saved: Retry continues where it stopped. If this repeats, the host’s time or memory limit is probably too low for this site — delete the export and start again with a smaller scope (fewer content types, or without media files).', 'contentrain-bridge'),
              'resume',
            );
          }
          const delay = RETRY_AFTER[failures++];
          say(sprintf(__('Stage %1$s: %2$s. Retrying in %3$d s…', 'contentrain-bridge'), label(job.phase), err.message, delay / 1000));
          await wait(delay);
        }
      }
    } finally { running = false; render(); }
    // The server gave the export up (a step that ended the request again and again): its reason says what to do, and the only way on is a smaller export.
    if (job.phase === 'failed') {
      error(Object.assign(new Error(job.error?.message || __('The export failed.', 'contentrain-bridge')), {
        heading: __('The export cannot continue', 'contentrain-bridge'),
        // The server's own reason for a step that kept killing the request already says what to do.
        hint: job.error?.code === 'step_repeatedly_killed' ? '' : __('Delete this export and start again with a smaller scope — fewer content types, or without media files — or raise the host’s time and memory limits first.', 'contentrain-bridge'),
        restart: true,
      }));
    }
    if (job.phase === 'review') { await loadCandidates(); focus('review-title'); }
    if (job.phase === 'ready') { renderIntegrations(); await loadCoverage(); focus('done-title'); }
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
  function renderIntegrations() {
    const list = $('integrations');
    if (!list) return;
    const services = (job.integrations || []).filter(s => s.reconnect_required);
    list.replaceChildren(...(services.length ? services.map(s => {
      const item = document.createElement('li');
      item.textContent = `${s.name} (${s.category})${s.secret_present ? ' — ' + __('a credential is set on WordPress', 'contentrain-bridge') : ''}`;
      return item;
    }) : [Object.assign(document.createElement('li'), { textContent: __('None found.', 'contentrain-bridge') })]));
  }
  async function loadCoverage() {
    const report = await api({ op: 'coverage', id: job.id });
    const table = document.createElement('table');
    table.className = 'widefat striped';
    const head = table.createTHead().insertRow();
    for (const label of [__('Source', 'contentrain-bridge'), __('Count', 'contentrain-bridge'), __('Outcome', 'contentrain-bridge'), __('Adds up', 'contentrain-bridge')]) {
      const th = document.createElement('th'); th.textContent = label; head.append(th);
    }
    const body = table.createTBody();
    for (const source of report.sources) {
      const row = body.insertRow();
      row.insertCell().textContent = source.source;
      row.insertCell().textContent = String(source.count);
      row.insertCell().textContent = Object.entries(source.outcomes).map(([outcome, n]) => `${outcome} ${n}`).join(', ') || '—';
      row.insertCell().textContent = source.balanced ? '✓' : '✗';
    }
    const summary = document.createElement('p');
    summary.textContent = sprintf(__('%1$d sources; %2$d do not add up; %3$d records cannot be read by this export.', 'contentrain-bridge'), report.totals.sources, report.totals.unbalanced, report.totals.unsupported);
    $('coverage').replaceChildren(summary, table);
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
    job = await api({ op: 'create', types, labels, private: $('private').checked, comments: $('comments').checked, media_files: $('media').checked, scan_sources: $('scan').checked, scan_plugins: $('plugins').checked, scan_render: $('render').checked, selected_meta: $('meta').value.split(',').map(x => x.trim()).filter(Boolean) });
    await loop();
  });
  action('resume', loop);
  $('pause').addEventListener('click', () => { running = false; });
  action('delete', async () => {
    await api({ op: 'delete', id: job.id }); job = null; offset = 0;
    $('download').hidden = true; $('migrate').hidden = true; $('receipt').hidden = true; $('token').value = ''; $('status').textContent = ''; $('current').textContent = '';
    $('bar').value = 0; $('bar').className = ''; $('percent').textContent = '0%'; announced = { phase: null, quarter: -1 }; render();
  });
  // The error card's buttons press the action they stand for, so every path stays the one already tested.
  $('retry').addEventListener('click', () => { const target = $('retry').dataset.target; $('error').hidden = true; if (target && $(target)) $(target).click(); });
  $('restart').addEventListener('click', () => { $('error').hidden = true; $('delete').click(); });
  action('save-review', () => saveReview());
  action('next', async () => { await saveReview(); offset += 30; await loadCandidates(); });
  action('prev', async () => { await saveReview(); offset = Math.max(0, offset - 30); await loadCandidates(); });
  action('finish', async () => { await saveReview(true); await loop(); });
  action('zip', async () => {
    let result;
    const advanced = stall(__('archive', 'contentrain-bridge'), 'zip');
    do { result = await api({ op: 'zip', id: job.id }); advanced(result.cursor); say(sprintf(__('Preparing archive: %d files', 'contentrain-bridge'), result.cursor)); } while (!result.done);
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
      job = await api({ op: 'github-start', id: job.id, token, repository: $('repo').value.trim(), base_branch: $('base').value.trim(), consent: true, on_conflict: $('conflict').value });
      const advanced = stall(__('delivery', 'contentrain-bridge'), 'github');
      while (job.github.phase !== 'done') {
        advanced(`${job.github.phase}:${job.github.cursor}`);
        job = await api({ op: 'github-step', id: job.id, token, cursor: job.github.cursor });
        say(sprintf(__('Delivering file step %d', 'contentrain-bridge'), job.github.cursor));
      }
      render();
    } finally { token = ''; }
  });
  // The Contentrain Migrate connection key (BR-27): its state, never the key itself, except once on creation.
  function when(iso) { return new Date(iso).toLocaleString(); }
  function renderKey(state) {
    const secure = state.secure !== false;
    $('key-create').disabled = !secure;
    $('key-create').textContent = state.active ? __('Replace with a new key', 'contentrain-bridge') : __('Create connection key', 'contentrain-bridge');
    $('key-revoke').hidden = !state.active;
    if (!secure) { $('key-state').textContent = __('Open this site over HTTPS to create a connection key.', 'contentrain-bridge'); return; }
    if (!state.active) { $('key-state').textContent = __('No active connection key.', 'contentrain-bridge'); return; }
    $('key-state').textContent = state.pairing
      ? sprintf(__('Connected to Migrate order %1$s. Last used %2$s from %3$s. Stops working %4$s; revoke it once the move is done.', 'contentrain-bridge'), state.pairing, when(state.last_used_at), state.last_used_ip, when(state.expires_at))
      : sprintf(__('Active key, not used yet: paste it into Migrate before %s.', 'contentrain-bridge'), when(state.expires_at));
  }
  action('key-create', async () => {
    const created = await api({ op: 'key-create' });
    $('key').value = created.key; $('key-new').hidden = false; $('key').select();
    renderKey(created.status);
  });
  action('key-revoke', async () => {
    renderKey(await api({ op: 'key-revoke' }));
    $('key').value = ''; $('key-new').hidden = true;
  });
  $('key-copy').addEventListener('click', async () => {
    $('key').select();
    try { await navigator.clipboard.writeText($('key').value); $('key-copy').textContent = __('Copied', 'contentrain-bridge'); } catch { /* Selected; the person copies it by hand. */ }
  });
  api({ op: 'key-status' }).then(renderKey).catch(error);
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
    if (job.phase === 'ready') { renderIntegrations(); await loadCoverage(); }
      }
      render();
    } catch (err) { error(err); render(); }
  })();
})();
