/*
 * shared/church-time.js, run as a browser would run it, from several viewer
 * time zones. Called by tests/Regression/church-time.php with TZ set:
 *
 *   TZ=Asia/Shanghai node tests/Regression/church-time.mjs America/Toronto
 *
 * Prints one JSON object of results; the PHP test judges them.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '../../shared/church-time.js'), 'utf8')
    .replace('__CHURCH_TIME_ZONE__', JSON.stringify(process.argv[2]));
new Function(source)();
const T = globalThis.EkklesiaTime;
const show = (d) => (d ? `${T.key(d)} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : null);

// "Now" at the church, worked out independently with Intl.
let expectedToday = null;
try {
    expectedToday = new Intl.DateTimeFormat('en-CA', { timeZone: process.argv[2], year: 'numeric', month: '2-digit', day: '2-digit' })
        .format(new Date());
} catch { expectedToday = null; }

console.log(JSON.stringify({
    wallClock: show(T.parse('2026-10-11 10:00:00')),
    dateOnly: show(T.parse('2026-10-12')),
    withOffset: show(T.parse('2026-10-11T10:00:00-04:00')),
    utcInstant: show(T.parse('2026-10-11T14:00:00Z')),
    lateEvening: show(T.parse('2026-10-11 23:30:00')),
    invalid: T.parse('not a date'),
    empty: T.parse(''),
    today: T.today(),
    expectedToday,
    startOfToday: show(T.startOfToday()),
}));
