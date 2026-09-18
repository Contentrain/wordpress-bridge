// Replay each state of the emulated repository's default branch
// (tests/delivery-git.php) into a real Git repository, and let Git say what
// each delivery changed: every deletion is one the delta proved, nothing else
// disappeared, and a person's edit survived the delivery that kept it.
//
//   node tests/delivery-git.mjs [statesDir]
import { execFileSync } from 'node:child_process'
import { cpSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const dir = process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '.out', 'git')
const receipts = JSON.parse(readFileSync(join(dir, 'receipts.json'), 'utf8'))
let checks = 0
const check = (ok, message) => {
  if (!ok) throw new Error('FAIL: ' + message)
  checks++
  console.log('PASS: ' + message)
}
const repo = mkdtempSync(join(tmpdir(), 'bridge-git-'))
const env = { ...process.env, GIT_AUTHOR_NAME: 'replay', GIT_AUTHOR_EMAIL: 'replay@example.test', GIT_COMMITTER_NAME: 'replay', GIT_COMMITTER_EMAIL: 'replay@example.test' }
const git = (...a) => execFileSync('git', a, { cwd: repo, encoding: 'utf8', env })
const walk = (d) => readdirSync(d).flatMap((n) => (n === '.git' ? [] : statSync(join(d, n)).isDirectory() ? walk(join(d, n)) : [join(d, n)]))
try {
  git('init', '-q')
  const states = Object.keys(receipts).sort()
  for (const [i, state] of states.entries()) {
    for (const f of walk(repo)) rmSync(f)
    cpSync(join(dir, state), repo, { recursive: true })
    git('add', '-A')
    git('commit', '-qm', state, '--allow-empty')
    if (i === 0) continue
    const changes = git('diff', '--name-status', 'HEAD~1', 'HEAD').trim().split('\n').filter(Boolean).map((l) => l.split('\t'))
    const deleted = changes.filter(([s]) => s === 'D').map(([, p]) => p).sort()
    const expected = [...(receipts[state].removed ?? [])].sort()
    check(JSON.stringify(deleted) === JSON.stringify(expected), `${state}: Git deletes exactly the ${expected.length} file(s) the delta proved (${changes.length} paths changed)`)
    if (receipts[state].kept) check(readFileSync(join(repo, receipts[state].kept), 'utf8').includes("A person's words."), `${state}: the person's edit is still in the file Git holds`)
  }
  console.log(`\n${checks} Git replay checks passed over ${states.length} states.`)
} finally {
  rmSync(repo, { recursive: true, force: true })
}
