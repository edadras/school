import { test, expect } from '@playwright/test';
import { createHmac } from 'crypto';
import { Api, ROOT_PW, openApp, see, tap, typeInto, uiLogin } from './helpers';
import { PASSWORD, load } from './state';

/** RFC 6238 TOTP in the test, so the browser flow is verified against an independent implementation. */
function totp(secretB32: string, at = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secretB32.toUpperCase()) { const i = alphabet.indexOf(c); if (i >= 0) bits += i.toString(2).padStart(5, '0'); }
  const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
  const step = Math.floor(at / 1000 / 30);
  const msg = Buffer.alloc(8); msg.writeBigUInt64BE(BigInt(step));
  const h = createHmac('sha1', key).update(msg).digest();
  const o = h[19] & 0xf;
  return String(((h[o] & 0x7f) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1_000_000).padStart(6, '0');
}

test.describe.serial('malware scan on upload (real clamd) and two-factor sign-in (real browser)', () => {
  const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

  test('an infected upload is refused and audited; a clean one is accepted and marked clean', async () => {
    const st = load();
    const t = await Api.create(); await t.login(st.teacher1, PASSWORD);
    const ctx = (t as any).ctx; const h = { Authorization: `Bearer ${t.token}`, 'X-School-Id': String(st.schoolId) };
    const bad = await ctx.post('files', { headers: h, multipart: { file: { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from(EICAR) } } });
    expect(bad.status()).toBe(422);
    expect((await bad.json()).message).toContain('آلوده');
    const ok = await ctx.post('files', { headers: h, multipart: { file: { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello class') } } });
    expect(ok.status()).toBe(201);
    const admin = await Api.create(); await admin.login(st.ownerEmail, PASSWORD);
    const audit = (await admin.get('/school/audit?action=file.blocked_malware')).data;
    expect(audit.length).toBeGreaterThan(0);
    const root = await Api.create(); await root.login('root@e2e.test', ROOT_PW());
    const health = (await root.get('/platform/health')).malware_scan;
    expect(health.ok).toBe(true);
  });

  test('an operator enables 2FA in the UI, then signing in needs the code; a recovery code works once', async ({ browser }) => {
    const st = load();
    const email = `ops-${Date.now()}@e2e.test`;
    const root = await Api.create(); await root.login('root@e2e.test', ROOT_PW());
    await root.post('/platform/operators', { name: 'پشتیبان دومرحله‌ای', email, role: 'support', password: PASSWORD });
    save({ opsEmail: email });
    const ctx = await browser.newContext({ locale: 'fa-IR', viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await uiLogin(page, email, PASSWORD);
    await openApp(page, '/profile');
    await tap(page.getByRole('button', { name: 'فعال‌سازی' }));
    // Flutter merges the card into one semantics group whose label carries the secret (SelectableText under the QR code)
    const group = page.locator('[aria-label*="QR"]').last();
    await expect(group).toBeVisible({ timeout: 15_000 });
    const label = async () => ((await group.getAttribute('aria-label')) ?? (await group.textContent()) ?? '');
    const secret = (await label()).match(/\b[A-Z2-7]{32}\b/)?.[0] ?? '';
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);
    await typeInto(page, page.getByRole('textbox', { name: /کد تأیید/ }), totp(secret));
    await tap(page.getByRole('button', { name: 'تأیید و فعال‌سازی' }));
    const done = page.locator('[aria-label*="کدهای بازیابی را همین حالا"]').last();
    await expect(done).toBeVisible({ timeout: 15_000 });
    const codes = ((((await done.getAttribute('aria-label')) ?? (await done.textContent()) ?? '')).match(/\b[a-z0-9]{5}-[a-z0-9]{5}\b/g)) ?? [];
    expect(codes.length).toBe(10);

    // password alone no longer gives a session
    const api = await Api.create();
    const r = await (api as any).ctx.post('auth/login', { data: { login: email, password: PASSWORD } });
    const j = await r.json();
    expect(j.two_factor_required).toBe(true);
    expect(j.token).toBeUndefined();

    // fresh browser: login screen asks for the code and signs in
    const page2 = await (await browser.newContext({ locale: 'fa-IR', viewport: { width: 1280, height: 900 } })).newPage();
    await openApp(page2, '/login');
    await typeInto(page2, page2.getByRole('textbox', { name: /ایمیل/ }), email);
    await typeInto(page2, page2.getByRole('textbox', { name: /رمز عبور/ }), PASSWORD);
    await tap(page2.getByRole('button', { name: 'ورود' }).first());
    await expect(see(page2, 'تأیید دومرحله‌ای')).toBeVisible({ timeout: 20_000 });
    await typeInto(page2, page2.getByRole('textbox', { name: /کد تأیید/ }), '000000');
    await tap(page2.getByRole('button', { name: 'تأیید و ورود' }));
    await expect(page2.locator('[aria-label*="نادرست"], flt-semantics:has-text("نادرست")').first()).toBeVisible({ timeout: 15_000 });
    await typeInto(page2, page2.getByRole('textbox', { name: /کد تأیید/ }), codes[0].trim());
    await tap(page2.getByRole('button', { name: 'تأیید و ورود' }));
    await expect.poll(() => new URL(page2.url()).pathname, { timeout: 20_000 }).not.toBe('/login');
    save2(codes);
  });

  test('recording on a real LiveKit without Egress says "unconfigured" instead of pretending; end-of-class works', async () => {
    const st = load();
    const root = await Api.create(); await root.login('root@e2e.test', ROOT_PW());
    await root.patch(`/platform/schools/${st.schoolId}/subscription`, { max_live_sessions: 20 });
    const admin = await Api.create(); await admin.login(st.ownerEmail, PASSWORD);
    await admin.patch('/school/settings', { settings: { 'recording.allowed': true } });
    const t = await Api.create(); await t.login(st.teacher1, PASSWORD);
    const start = new Date(Date.now() + 60_000);
    const s = (await t.post('/sessions', { section_id: st.secA.id, subject_id: st.math.id, kind: 'makeup', title: 'ضبط آزمایشی', scheduled_start: start.toISOString(), scheduled_end: new Date(start.getTime() + 30 * 60_000).toISOString() })).data;
    await t.post(`/sessions/${s.id}/start`);
    const r = await (t as any).ctx.put(`sessions/${s.id}/recording`, { data: { enabled: true }, headers: { Authorization: `Bearer ${t.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(r.status()).toBe(503);
    expect((await r.json()).message).toContain('پیکربندی نشده');
    expect(((await t.get(`/sessions/${s.id}`)).data ?? (await t.get(`/sessions/${s.id}`))).recording_enabled).toBeFalsy();
    await t.post(`/sessions/${s.id}/end`);
  });

  test('a used recovery code cannot be replayed', async () => {
    const email = load().opsEmail as string;
    const used = load().recoveryUsed as string;
    const api = await Api.create();
    const ch = (await (await (api as any).ctx.post('auth/login', { data: { login: email, password: PASSWORD } })).json()).challenge;
    const r = await (api as any).ctx.post('auth/2fa/verify', { data: { challenge: ch, code: used } });
    expect(r.status()).toBe(422);
  });
});

import { save } from './state';
function save2(codes: string[]) { save({ recoveryUsed: codes[0].trim() }); }
