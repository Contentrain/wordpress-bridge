// The wp-admin export loop (assets/admin.js) against a host that cuts requests short: it must retry what is
// safe to retry, say what happened when it gives up, and never loop on a server that does not advance.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/admin.js', import.meta.url), 'utf8');

/** Load admin.js against a stub DOM and a scripted server; `script` answers each ajax payload in turn. */
function boot(script) {
  const elements = new Map();
  const element = (id) => {
    if (!elements.has(id)) {
      const listeners = {};
      elements.set(id, {
        id, hidden: false, disabled: false, textContent: '', value: '', checked: true,
        listeners,
        addEventListener(type, fn) { listeners[type] = fn; },
        append() {}, replaceChildren() {}, querySelectorAll: () => [], select() {},
        createTHead: () => ({ insertRow: () => ({ append() {} }) }), createTBody: () => ({ insertRow: () => ({ insertCell: () => ({}) }) }),
      });
    }
    return elements.get(id);
  };
  const calls = [];
  const fetch = async (_url, init) => {
    const payload = JSON.parse(new URLSearchParams(init.body).get('payload'));
    calls.push(payload);
    const answer = script(payload, calls);
    if (answer instanceof Error) throw answer;
    const { status = 200, body } = answer;
    const text = typeof body === 'string' ? body : JSON.stringify(body);
    return { ok: status >= 200 && status < 300, status, text: async () => text };
  };
  const sandbox = {
    wp: { i18n: { __: (s) => s, sprintf: (s, ...a) => { let i = 0; return s.replace(/%(\d\$)?[sd]/g, () => String(a[i++])); } } },
    ContentrainBridge: { ajax: '/ajax', nonce: 'n', download: 'http://x/d', secure: true },
    document: { getElementById: (id) => element(id.replace(/^cr-/, '')) , createElement: () => element(`c${Math.random()}`), createTextNode: () => ({}) },
    fetch, URLSearchParams, URL, AbortController, JSON, Object, Error, Math, Promise, String, Array, Number,
    setTimeout: (fn) => { queueMicrotask(fn); return 0; }, clearTimeout() {}, console,
    navigator: {},
  };
  vm.runInNewContext(source, sandbox);
  return { element, calls };
}
const ok = (data) => ({ body: { success: true, data } });
const job = (over) => ({ id: 'j', phase: 'media', cursor: 0, step: 0, counts: { posts: 0, warnings: 0 }, files: 0, unreviewed: 0, models: [], candidates: 0, ...over });
const settle = () => new Promise((resolve) => setTimeout(resolve, 20));
const start = async (h) => { await h.element('create').listeners.click(); await settle(); };

function server(stepAnswers) {
  const steps = [...stepAnswers];
  return (payload) => {
    if (payload.op === 'inventory') return ok({ post_types: {}, active_job: '' });
    if (payload.op === 'key-status') return ok({ active: false });
    if (payload.op === 'create') return ok(job({}));
    if (payload.op === 'candidates') return ok([]);
    if (payload.op === 'step') return steps.shift() ?? ok(job({ phase: 'review', step: 99 }));
    throw new Error(`unscripted ${payload.op}`);
  };
}

test('a host error page, a dropped connection and a busy export are retried, then the export finishes', async () => {
  const h = boot(server([
    { status: 504, body: '<html>504 Gateway Time-out</html>' },
    new TypeError('network'),
    { status: 409, body: { success: false, data: { message: 'Export is busy. Retry this step.', busy: true } } },
    ok(job({ phase: 'posts', step: 3 })),
  ]));
  await settle();
  await start(h);
  const steps = h.calls.filter((c) => c.op === 'step');
  assert.equal(steps.length, 5, 'three retried failures, one success, then the step that reaches review');
  assert.deepEqual(steps.map((c) => c.step), [0, 0, 0, 0, 3], 'every retry resends the step the browser last saw');
  assert.equal(h.element('error').hidden, true, 'nothing is left on screen once it recovered');
});

test('a host that keeps failing ends with a message that names the stage, saved progress and the limit', async () => {
  const h = boot(server(Array.from({ length: 10 }, () => ({ status: 502, body: '<html>Bad gateway</html>' }))));
  await settle();
  await start(h);
  assert.equal(h.calls.filter((c) => c.op === 'step').length, 4, 'one try and three retries, no more');
  assert.equal(h.element('error').hidden, false);
  assert.match(h.element('error').textContent, /stage “media”.*HTTP 502.*progress is saved.*time or memory limit/s);
});

test('an error the server explains is shown at once, not retried', async () => {
  const h = boot(server([{ status: 400, body: { success: false, data: { message: 'WordPress content changed during export. Restart to obtain a consistent snapshot.' } } }]));
  await settle();
  await start(h);
  assert.equal(h.calls.filter((c) => c.op === 'step').length, 1);
  assert.match(h.element('error').textContent, /content changed during export/);
});
