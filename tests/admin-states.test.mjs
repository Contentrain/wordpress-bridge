// The export page's states (assets/admin.js): empty, running with a stage list and a percentage, a skipped stage,
// an export saved by an older version with no progress record, review, ready — and a live region that speaks
// only on a stage change or a quarter of the way, not on every batch.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, ok, job, settle, start, server } from './admin-dom.mjs';

const states = (h) => Object.fromEntries(['media', 'posts', 'terms', 'inventory', 'comments', 'sources', 'tables', 'review', 'ready'].map((id) => [id, h.step(id).className.replace('cr-step is-', '')]));

test('before an export: the scope card alone, no progress card, nothing in the live region', async () => {
  const h = boot(server([]));
  await settle();
  assert.equal(h.element('scope').hidden, false);
  assert.equal(h.element('progress').hidden, true);
  assert.equal(h.element('delete').hidden, true);
  assert.equal(h.element('status').textContent, '');
  assert.equal(h.element('error').hidden, true);
});

test('while running: the current stage, its share of the bar, the item it is on and the counts', async () => {
  const h = boot(server([
    ok(job({ phase: 'posts', step: 3, progress: { phase: 'posts', done: 120, total: 400, current: 'Hello world' }, counts: { posts: 118, media: 40, warnings: 2 }, files: 160, unreviewed: 0 })),
    { status: 504, body: '<html>504</html>' }, // one retry: the export stays where it is
    ok(job({ phase: 'posts', step: 4, progress: { phase: 'posts', done: 400, total: 400, current: 'Last post' }, counts: { posts: 398, media: 40, warnings: 2 }, files: 440, unreviewed: 0 })),
  ]));
  await settle();
  const seen = [];
  const status = h.element('status');
  Object.defineProperty(status, 'textContent', { set(v) { seen.push(v); }, get() { return seen.at(-1) ?? ''; } });
  await start(h);
  // The stage list after the first answer was: media done, posts current. By the end: review waiting.
  assert.deepEqual(states(h), { media: 'done', posts: 'done', terms: 'done', inventory: 'done', comments: 'done', sources: 'done', tables: 'done', review: 'waiting', ready: 'pending' });
  assert.equal(h.element('bar').value, 100);
  assert.equal(h.element('percent').textContent, '100%');
  assert.ok(seen.includes('Stage 1 of 7: Media'), 'the first stage was announced');
  assert.ok(seen.includes('Stage 2 of 7: Content'), 'the stage change was announced');
  assert.ok(seen.some((s) => /Retrying in \d+ s/.test(s)), 'a retry countdown is spoken');
  assert.ok(seen.includes('Export paused for your review.'));
  const perBatch = seen.filter((s) => /^Stage 2 of 7/.test(s)).length;
  assert.equal(perBatch, 1, 'the live region is not rewritten on every batch of the same stage');
});

test('a stage the scope leaves out is shown as skipped and still counts toward the bar', async () => {
  const noMedia = (over) => job({ scope: { types: ['post', 'page'], comments: false, private: false, media_files: true }, ...over });
  const h = boot(server([
    ok(noMedia({ phase: 'terms', step: 2, progress: { phase: 'terms', done: 5, total: 10, current: 'News' } })),
    ok(noMedia({ phase: 'review', step: 9 })),
  ]));
  await settle();
  await start(h);
  assert.equal(states(h).media, 'skipped');
  assert.equal(states(h).comments, 'skipped');
  assert.equal(h.step('media-state').textContent, 'skipped');
});

test('the percentage is the done stages plus the current stage\'s share; 99 at most while working', async () => {
  const h = boot(server([
    ok(job({ phase: 'terms', step: 2, progress: { phase: 'terms', done: 5, total: 10, current: 'News' } })),
    ok(job({ phase: 'tables', step: 9, progress: { phase: 'tables', done: 11, total: 11, current: 'content/posts/en.json' } })),
    ok(job({ phase: 'tables', step: 10, progress: { phase: 'tables', done: 11, total: 11, current: 'content/posts/en.json' } })),
  ]));
  await settle();
  const values = [];
  const bar = h.element('bar');
  Object.defineProperty(bar, 'value', { set(v) { values.push(v); }, get() { return values.at(-1); } });
  await start(h);
  // terms = stage 3 of 7 half done → (2 + 0.5) / 7 = 35.7 → 36; tables complete but not finished → capped at 99; review → 100.
  assert.deepEqual(values.filter((v, i) => v !== values[i - 1]), [0, 36, 99, 100]);
  assert.match(h.element('current').textContent, /Every stage is done/);
});

test('an export saved by an older version has no progress record: stages are shown, the bar moves per stage', async () => {
  const h = boot(server([
    ok(job({ phase: 'comments', step: 40 })), // no `progress` key at all
  ]));
  await settle();
  await start(h);
  assert.equal(states(h).review, 'waiting');
  const h2 = boot(server([], { inventory: { active_job: 'j' }, status: job({ phase: 'sources', step: 50 }) }));
  await settle();
  assert.equal(h2.element('progress').hidden, false, 'a saved export shows its progress card on load');
  assert.equal(h2.element('bar').value, Math.round((5 / 7) * 100), 'five stages done, the sixth not measurable: its share is zero');
  assert.equal(h2.element('current').textContent, 'Stage 6 of 7: Interface text', 'no item, no count, no guess');
  assert.equal(h2.element('resume').hidden, false, 'Continue is offered');
  assert.equal(h2.focused(), undefined, 'loading a page moves focus nowhere');
});

test('review: focus goes to the review heading once, saving a page of decisions moves it nowhere', async () => {
  const h = boot(server([ok(job({ phase: 'review', step: 9, unreviewed: 12 }))]));
  await settle();
  await start(h);
  assert.equal(h.element('review').hidden, false);
  assert.equal(h.focused(), h.element('review-title'));
  assert.equal(h.element('count-texts').textContent, '12');
  const before = h.element('review-title').focused;
  await h.element('save-review').click();
  await settle();
  assert.equal(h.element('review-title').focused, before, 'a save does not steal focus from the form');
});

test('ready: the result card with its totals, the stage list all done, focus on the heading', async () => {
  const ready = job({ phase: 'ready', step: 20, counts: { posts: 398, media: 40, warnings: 3 }, files: 452, unreviewed: 0, integrations: [] });
  const h = boot(server([ok(ready)]));
  await settle();
  await start(h);
  assert.equal(h.element('delivery').hidden, false);
  assert.equal(h.element('progress').hidden, false, 'the stage list stays as the record of what ran');
  assert.deepEqual(states(h), { media: 'done', posts: 'done', terms: 'done', inventory: 'done', comments: 'done', sources: 'done', tables: 'done', review: 'done', ready: 'done' });
  assert.equal(h.element('bar').value, 100);
  assert.equal(h.element('bar').className, 'is-done');
  assert.equal(h.element('done-summary').textContent, '398 content entries, 40 media records and 452 files were exported; 3 coverage notices to read.');
  assert.equal(h.focused(), h.element('done-title'));
  assert.equal(h.element('status').textContent, 'Export finished.');
});
