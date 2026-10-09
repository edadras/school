#!/usr/bin/env node
// Measured load test against a running stack (reads accounts from the E2E state file).
//   node scripts/loadtest.mjs [baseUrl] [secondsPerStage] [concurrency,list]
// Reports ONLY what was measured here; the numbers describe the machine/stack it ran on, not production capacity.
import fs from 'fs';

const BASE = process.argv[2] ?? 'http://localhost:8000';
const SECONDS = Number(process.argv[3] ?? 20);
const STAGES = (process.argv[4] ?? '5,20,50').split(',').map(Number);
const st = JSON.parse(fs.readFileSync(process.env.E2E_STATE ?? '/tmp/e2e/state.json', 'utf8'));
const PASSWORD = 'E2e-Passw0rd-123';

async function login(email) {
  const r = await fetch(`${BASE}/api/v1/auth/login`, { method: 'POST', headers: { 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ login: email, password: PASSWORD }) });
  if (!r.ok) throw new Error(`login ${email}: ${r.status}`);
  const j = await r.json();
  return { token: j.token, school: j.user.memberships?.[0]?.school?.id };
}

const accounts = {
  student: `s1-${st.code}@e2e.test`, teacher: st.teacher1, guardian: st.guardian1, admin: st.ownerEmail,
};
const sessions = {};
for (const [k, e] of Object.entries(accounts)) sessions[k] = await login(e);

const mix = [   // [role, path, weight]
  ['student', '/api/v1/me/notifications', 4], ['student', '/api/v1/me/schedule', 3], ['student', '/api/v1/assignments', 3], ['student', '/api/v1/exams', 2], ['student', '/api/v1/grades', 2],
  ['teacher', '/api/v1/me/teaching', 2], ['teacher', '/api/v1/assignments', 2], ['teacher', '/api/v1/sessions', 2],
  ['guardian', '/api/v1/me/children', 2], ['guardian', '/api/v1/grades', 1],
  ['admin', '/api/v1/academics/students?per_page=25', 2], ['admin', '/api/v1/analytics/overview', 1], ['admin', '/api/v1/report-cards', 1],
];
const bag = mix.flatMap((m) => Array(m[2]).fill(m));

async function stage(concurrency) {
  const lat = []; const codes = {}; let stop = false;
  setTimeout(() => (stop = true), SECONDS * 1000);
  const t0 = performance.now();
  await Promise.all(Array.from({ length: concurrency }, async () => {
    while (!stop) {
      const [role, path] = bag[Math.floor(Math.random() * bag.length)];
      const s = sessions[role];
      const t = performance.now();
      let code = 'ERR';
      try {
        const r = await fetch(BASE + path, { headers: { authorization: `Bearer ${s.token}`, 'x-school-id': String(s.school), accept: 'application/json' } });
        await r.arrayBuffer(); code = r.status;
      } catch { /* counted as ERR */ }
      lat.push(performance.now() - t);
      codes[code] = (codes[code] ?? 0) + 1;
    }
  }));
  const secs = (performance.now() - t0) / 1000;
  lat.sort((a, b) => a - b);
  const q = (p) => lat[Math.min(lat.length - 1, Math.floor(lat.length * p))].toFixed(0);
  const bad = Object.entries(codes).filter(([c]) => !String(c).startsWith('2')).reduce((a, [, n]) => a + n, 0);
  return { concurrency, requests: lat.length, rps: +(lat.length / secs).toFixed(1), p50_ms: +q(0.5), p95_ms: +q(0.95), p99_ms: +q(0.99), non2xx: bad, codes };
}

console.log(`target ${BASE}  stage length ${SECONDS}s  (cpu cores on this host: ${(await import('os')).cpus().length})`);
const out = [];
for (const c of STAGES) { const r = await stage(c); out.push(r); console.log(JSON.stringify(r)); }
fs.writeFileSync(process.env.LOAD_OUT ?? '/tmp/e2e/loadtest.json', JSON.stringify({ at: new Date().toISOString(), base: BASE, stages: out }, null, 2));
