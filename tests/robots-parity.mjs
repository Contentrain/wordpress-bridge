// The robots.txt pairs tests/robots.php served (switch off / on), read by Contentrain Migrate's own robots parser
// (`@migrate/crawler`, RFC 9309 group merging since migrate#679): with the switch on, Migrate's crawler may read the site,
// the REST API and the uploads, never wp-admin; every other agent's verdict is the one it had with the switch off.
// Usage: node tests/robots-parity.mjs <path to migrate's packages/crawler/dist/index.mjs>
import { readdirSync, readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const parser = process.argv[2];
if (!parser) throw new Error('usage: node tests/robots-parity.mjs <migrate packages/crawler/dist/index.mjs>');
const { parseRobots, isAllowed, MIGRATE_UA } = await import(pathToFileURL(parser).href);
const dir = new URL('./.out/robots/', import.meta.url);
const READS = ['/', '/blog/a-post/', '/wp-json/contentrain-bridge/v1/about', '/wp-json/wp/v2/posts', '/wp-content/uploads/2026/10/a.jpg'];
const OTHERS = ['*', 'Googlebot', 'GPTBot', 'ClaudeBot', 'Mozilla/5.0 (compatible; bingbot/2.0)'];
const PATHS = [...READS, '/wp-admin/', '/private/x', '/wp-content/plugins/x.js'];
const names = readdirSync(dir).filter(f => f.endsWith('.on.txt')).map(f => f.slice(0, -'.on.txt'.length));
if (!names.length) throw new Error('no pairs: run tests/robots.sh first');
let failed = 0;
for (const name of names) {
  const on = parseRobots(readFileSync(new URL(`${name}.on.txt`, dir), 'utf8'));
  const off = parseRobots(readFileSync(new URL(`${name}.off.txt`, dir), 'utf8'));
  const closed = READS.filter(path => !isAllowed(on, path, MIGRATE_UA).allowed);
  const admin = isAllowed(on, '/wp-admin/', MIGRATE_UA).allowed;
  const moved = OTHERS.flatMap(ua => PATHS.filter(path => isAllowed(on, path, ua).allowed !== isAllowed(off, path, ua).allowed).map(path => `${ua} ${path}`));
  const ok = !closed.length && !admin && !moved.length;
  if (!ok) failed++;
  console.log(`${ok ? 'PASS' : 'FAIL'}: ${name}${closed.length ? ` closed to Migrate: ${closed.join(', ')}` : ''}${admin ? ' wp-admin open to Migrate' : ''}${moved.length ? ` other agents moved: ${moved.join('; ')}` : ''}`);
}
console.log(`Parity: ${names.length - failed}/${names.length} through @migrate/crawler`);
if (failed) process.exit(1);
