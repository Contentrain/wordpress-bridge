// The wp-admin export loop (assets/admin.js) against a host that cuts requests short: it must retry what is
// safe to retry, say what happened when it gives up, and never loop on a server that does not advance.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boot, ok, job, settle, start, server } from './admin-dom.mjs';

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

test('a host that keeps failing ends with a card that names the stage, what to do, and a Retry that continues', async () => {
  const h = boot(server(Array.from({ length: 4 }, () => ({ status: 502, body: '<html>Bad gateway</html>' }))));
  await settle();
  await start(h);
  assert.equal(h.calls.filter((c) => c.op === 'step').length, 4, 'one try and three retries, no more');
  assert.equal(h.element('error').hidden, false);
  assert.equal(h.element('error-heading').textContent, 'The export stopped');
  assert.match(h.element('error-message').textContent, /stage “Media” because .*HTTP 502/, 'the stage by its screen name, the cause from the server');
  assert.match(h.element('error-hint').textContent, /progress is saved.*Retry continues.*time or memory limit/s);
  assert.equal(h.element('retry').hidden, false, 'a retryable stop offers Retry');
  assert.equal(h.element('restart').hidden, true);
  assert.equal(h.focused(), h.element('error-title'), 'focus lands on the error');
  // Retry is Continue: the loop resumes with the step the browser last saw.
  const before = h.calls.length;
  await h.element('retry').click();
  await settle();
  assert.equal(h.element('error').hidden, true, 'the card closes when Retry is pressed');
  assert.ok(h.calls.slice(before).some((c) => c.op === 'step'), 'Retry sent the next step');
  assert.equal(h.step('review').attributes['aria-current'], 'step', 'and the export went on to review');
});

test('an error the server explains is shown at once, not retried, with no Retry button', async () => {
  const h = boot(server([{ status: 400, body: { success: false, data: { message: 'WordPress content changed during export. Restart to obtain a consistent snapshot.' } } }]));
  await settle();
  await start(h);
  assert.equal(h.calls.filter((c) => c.op === 'step').length, 1);
  assert.match(h.element('error-message').textContent, /content changed during export/);
  assert.equal(h.element('error-heading').textContent, 'Something went wrong');
  assert.equal(h.element('retry').hidden, true);
  assert.equal(h.element('error-hint').hidden, true, 'nothing to add to what the server said');
});

test('an export the server gave up on stops the loop, shows the reason and offers only a fresh start', async () => {
  const failed = job({ phase: 'failed', step: 7, progress: { phase: 'posts', done: 0, total: 9, current: '' }, error: { code: 'step_repeatedly_killed', stage: 'media', message: 'Step 7 of stage "media" stopped the request 6 times without finishing; raise the host\'s limits.' } });
  const h = boot(server([ok(job({ phase: 'media', step: 7 })), ok(failed), ok(failed), ok(failed)]));
  await settle();
  await start(h);
  assert.equal(h.calls.filter((c) => c.op === 'step').length, 2, 'the failed answer ends the loop: no further step is sent');
  assert.equal(h.element('error').hidden, false);
  assert.equal(h.element('error-heading').textContent, 'The export cannot continue');
  assert.match(h.element('error-message').textContent, /Step 7 of stage "media".*6 times/);
  assert.equal(h.element('error-hint').hidden, true, 'the server\'s reason already says what to do; it is not said twice');
  assert.equal(h.element('resume').hidden, true, 'nothing to resume: the server refuses every later step');
  assert.equal(h.element('retry').hidden, true, 'no Retry: it would hit the same limit');
  assert.equal(h.element('restart').hidden, false);
  assert.equal(h.step('media').className, 'cr-step is-failed', 'the stage the server names, even when the progress record is already the next stage\'s');
  assert.equal(h.step('posts').className, 'cr-step is-pending', 'the stages after it never ran');
  // The way out deletes the export and the page returns to its empty state.
  await h.element('restart').click();
  await settle();
  assert.ok(h.calls.some((c) => c.op === 'delete'));
  assert.equal(h.element('progress').hidden, true);
  assert.equal(h.element('scope').hidden, false);
});
