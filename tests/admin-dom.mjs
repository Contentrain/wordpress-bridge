// A stub DOM and a scripted server for assets/admin.js: enough of the platform for the script to render its
// states (elements by id, the stage list, focus, data attributes), with no browser and no dependency.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/admin.js', import.meta.url), 'utf8');

/** The stages as the PHP side passes them (Admin::stages()). */
export const STAGES = [
  ['media', 'Media'], ['posts', 'Content'], ['terms', 'Categories and tags'], ['inventory', 'Inventory'], ['comments', 'Comments'],
  ['sources', 'Interface text'], ['tables', 'Files'], ['review', 'Your review'], ['ready', 'Ready'],
].map(([id, label]) => ({ id, label }));

/** What the PHP page renders hidden until the script shows it. */
const HIDDEN = new Set(['error', 'error-hint', 'retry', 'restart', 'progress', 'resume', 'pause', 'delete', 'review', 'delivery', 'migrate', 'download', 'receipt', 'key-new', 'key-revoke']);

function makeElement(id, elements) {
  const listeners = {};
  const attributes = {};
  const children = [];
  const el = {
    id, hidden: HIDDEN.has(id), disabled: false, textContent: '', value: '', checked: true, className: '', dataset: {}, href: '',
    listeners, attributes, children, focused: 0,
    addEventListener(type, fn) { listeners[type] = fn; },
    click() { return listeners.click?.(); },
    focus() { el.focused++; elements.focused = el; },
    append(...nodes) { children.push(...nodes); },
    replaceChildren(...nodes) { children.length = 0; children.push(...nodes); },
    setAttribute(name, value) { attributes[name] = String(value); },
    removeAttribute(name) { delete attributes[name]; },
    getAttribute(name) { return attributes[name] ?? null; },
    querySelector(selector) { return el.querySelectorAll(selector)[0] ?? null; },
    querySelectorAll(selector) {
      if (selector === '[data-stage]') return children.filter(c => c.dataset.stage);
      if (selector.startsWith('.')) return children.filter(c => c.className.split(' ').includes(selector.slice(1)));
      return [];
    },
    select() {},
    createTHead: () => ({ insertRow: () => ({ append() {} }) }), createTBody: () => ({ insertRow: () => ({ insertCell: () => ({}) }) }),
  };
  return el;
}

/** Load admin.js against the stub DOM; `script(payload, calls)` answers each ajax payload. */
export function boot(script, { stages = STAGES } = {}) {
  const elements = new Map();
  const element = (id) => {
    if (!elements.has(id)) elements.set(id, makeElement(id, elements));
    return elements.get(id);
  };
  // The stage list the PHP page renders: one item per stage with its mark, label and spoken state.
  const steps = element('steps');
  for (const stage of stages) {
    const item = element(`step-${stage.id}`);
    item.dataset.stage = stage.id;
    const state = element(`step-${stage.id}-state`);
    state.className = 'cr-step-state screen-reader-text';
    item.children.push(state);
    steps.children.push(item);
  }
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
    wp: { i18n: { __: (s) => s, sprintf: (s, ...a) => { let i = 0; return s.replace(/%(\d\$)?[sd]/g, () => String(a[i++])).replace(/%%/g, '%'); } } },
    ContentrainBridge: { ajax: '/ajax', nonce: 'n', download: 'http://x/d', secure: true, stages },
    document: { getElementById: (id) => element(id.replace(/^cr-/, '')), createElement: () => element(`c${Math.random()}`), createTextNode: () => ({}) },
    fetch, URLSearchParams, URL, AbortController, JSON, Object, Error, Math, Promise, String, Array, Number,
    setTimeout: (fn) => { queueMicrotask(fn); return 0; }, clearTimeout() {}, console,
    navigator: {},
  };
  vm.runInNewContext(source, sandbox);
  return { element, calls, elements, step: (id) => element(`step-${id}`), focused: () => elements.focused };
}

export const ok = (data) => ({ body: { success: true, data } });
export const job = (over) => ({ id: 'j', phase: 'media', cursor: 0, step: 0, counts: { posts: 0, media: 0, warnings: 0 }, files: 0, unreviewed: 0, models: [], candidates: 0, scope: { types: ['post', 'page', 'attachment'], comments: true, private: false, media_files: true }, ...over });
export const settle = () => new Promise((resolve) => setTimeout(resolve, 20));
export const start = async (h) => { await h.element('create').listeners.click(); await settle(); };

/** A server that answers `step` from the list, then parks the export in review. */
export function server(stepAnswers, over = {}) {
  const steps = [...stepAnswers];
  return (payload) => {
    if (payload.op === 'inventory') return ok({ post_types: {}, active_job: '', ...over.inventory });
    if (payload.op === 'key-status') return ok({ active: false });
    if (payload.op === 'create') return ok(job({}));
    if (payload.op === 'status') return ok(over.status ?? job({}));
    if (payload.op === 'candidates') return ok([]);
    if (payload.op === 'coverage') return ok({ sources: [], totals: { sources: 0, unbalanced: 0, unsupported: 0 } });
    if (payload.op === 'step') return steps.shift() ?? ok(job({ phase: 'review', step: 99 }));
    if (payload.op === 'delete') return ok({});
    throw new Error(`unscripted ${payload.op}`);
  };
}
