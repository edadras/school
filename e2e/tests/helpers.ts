import { Browser, BrowserContext, Locator, Page, expect, request } from '@playwright/test';
import * as fs from 'fs';

export const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
export const ROOT_PW = () => fs.readFileSync(process.env.ROOT_PW_FILE ?? '/tmp/e2e/root.pw', 'utf8').trim();

/** Flutter web draws to a canvas; turning on its semantics tree exposes real ARIA roles/labels to the test. */
export async function openApp(page: Page, path = '/') {
  await page.goto(BASE + path);
  await page.waitForSelector('flt-glass-pane', { state: 'attached', timeout: 60_000 });
  await enableSemantics(page);
}

export async function enableSemantics(page: Page) {
  const btn = page.locator('flt-semantics-placeholder');
  if (await btn.count()) {
    await btn.first().evaluate((el: HTMLElement) => el.click()).catch(() => {});
  }
  await page.waitForSelector('flt-semantics', { state: 'attached', timeout: 30_000 });
}

const TOKENS = new Map<string, { token: string; schoolId?: number; user: any }>();

/** Typed API helper for fast, deterministic setup (the UI tests then exercise the behaviour under test). */
export class Api {
  token?: string;
  schoolId?: number;
  constructor(private ctx: Awaited<ReturnType<typeof request.newContext>>) {}
  static async create() {
    return new Api(await request.newContext({ baseURL: BASE + '/api/v1/', extraHTTPHeaders: { Accept: 'application/json' } }));
  }
  /** Playwright resolves a leading '/' against the origin; keep paths relative to /api/v1/. */
  private p(path: string) { return path.replace(/^\//, ''); }
  private h() {
    return { ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}), ...(this.schoolId ? { 'X-School-Id': String(this.schoolId) } : {}) };
  }
  async login(login: string, password: string, schoolId?: number) {
    // Login is rate-limited (5/min per account, by design): reuse the session of an already signed-in account.
    const cached = TOKENS.get(login);
    if (cached) { this.token = cached.token; this.schoolId = schoolId ?? cached.schoolId; return cached.user; }
    let r = await this.ctx.post('auth/login', { data: { login, password } });
    for (let i = 0; r.status() === 429 && i < 6; i++) { await new Promise((x) => setTimeout(x, 15_000)); r = await this.ctx.post('auth/login', { data: { login, password } }); }
    expect(r.status(), await r.text()).toBe(200);
    const j = await r.json();
    this.token = j.token;
    this.schoolId = schoolId ?? j.user.memberships?.[0]?.school?.id;
    TOKENS.set(login, { token: j.token, schoolId: this.schoolId, user: j });
    return j;
  }
  async post(path: string, data?: any, expected = [200, 201, 204]) {
    const r = await this.ctx.post(this.p(path), { data, headers: this.h() });
    expect(expected, `${path}: ${await r.text()}`).toContain(r.status());
    return r.status() === 204 ? null : r.json();
  }
  async get(path: string) {
    const r = await this.ctx.get(this.p(path), { headers: this.h() });
    expect(r.status(), `${path}: ${await r.text()}`).toBe(200);
    return r.json();
  }
  async put(path: string, data?: any) {
    const r = await this.ctx.put(this.p(path), { data, headers: this.h() });
    expect([200, 201, 204], `${path}: ${await r.text()}`).toContain(r.status());
    return r.status() === 204 ? null : r.json();
  }
  async patch(path: string, data?: any) {
    const r = await this.ctx.patch(this.p(path), { data, headers: this.h() });
    expect([200, 201], `${path}: ${await r.text()}`).toContain(r.status());
    return r.json();
  }
}

/** Text anywhere on screen: either a plain node or part of a merged semantics label (Flutter merges sibling texts into one aria-label). */
export function see(page: Page, text: string): Locator {
  const esc = text.replace(/"/g, '\\"');
  return page.getByText(text).or(page.locator(`[aria-label*="${esc}"]`)).first();
}

/** Semantics nodes of a canvas-rendered app can report as "outside the viewport"; a DOM click triggers Flutter's tap action. */
export async function tap(l: Locator) {
  await l.waitFor({ state: 'attached' });
  await l.dispatchEvent('click');
}

/** Flutter creates the real <input> only while a field is focused: focus via DOM click, then type like a user. */
export async function typeInto(page: Page, l: Locator, text: string) {
  await tap(l);
  await page.waitForTimeout(150);
  await page.keyboard.press('Control+A');
  await page.keyboard.press('Delete');
  await page.keyboard.type(text, { delay: 5 });
}

export async function uiLogin(page: Page, email: string, password: string, opts: { expectFail?: boolean } = {}) {
  await openApp(page, '/login');
  await typeInto(page, page.getByRole('textbox', { name: 'ایمیل یا شمارهٔ موبایل' }), email);
  await typeInto(page, page.getByRole('textbox', { name: 'رمز عبور' }), password);
  await tap(page.getByRole('button', { name: 'ورود', exact: true }));
  if (!opts.expectFail) await page.waitForURL((u) => !/^\/(login|register-school)/.test(u.pathname), { timeout: 30_000 });
}

export async function newUserContext(browser: Browser): Promise<[BrowserContext, Page]> {
  const ctx = await browser.newContext({ locale: 'fa-IR', viewport: { width: 1280, height: 800 }, permissions: ['microphone', 'camera'] });
  return [ctx, await ctx.newPage()];
}
