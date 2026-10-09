import { test, expect } from '@playwright/test';
import { BASE, enableSemantics, openApp, see, tap, uiLogin } from './helpers';
import { PASSWORD, load, student } from './state';

const viewports = { phone: { width: 390, height: 844 }, tablet: { width: 820, height: 1180 }, desktop: { width: 1366, height: 800 } };

test.describe('responsive shell, refresh and sign-out', () => {
  test('phone: bottom navigation, no side rail; pages stay inside the viewport', async ({ browser }) => {
    const st = load();
    const ctx = await browser.newContext({ locale: 'fa-IR', viewport: viewports.phone, isMobile: true, hasTouch: true });
    const page = await ctx.newPage();
    await uiLogin(page, student(st, 1), PASSWORD);
    await openApp(page, '/student');
    await expect(see(page, 'بیشتر')).toBeVisible();                        // bottom bar with the "more" entry
    for (const path of ['/student/assignments', '/student/exams', '/student/grades', '/student/messages']) {
      await openApp(page, path);
      await page.waitForTimeout(1200);
      const overflow = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
      expect(overflow.sw, `${path} must not scroll horizontally`).toBeLessThanOrEqual(overflow.cw);
    }
    await page.screenshot({ path: 'test-results/phone-messages.png' });
    await ctx.close();
  });

  test('tablet and desktop use the side rail instead of the bottom bar', async ({ browser }) => {
    const st = load();
    for (const [name, vp] of [['tablet', viewports.tablet], ['desktop', viewports.desktop]] as const) {
      const ctx = await browser.newContext({ locale: 'fa-IR', viewport: vp });
      const page = await ctx.newPage();
      await uiLogin(page, student(st, 1), PASSWORD);
      await openApp(page, '/student');
      await expect(see(page, 'بیشتر'), `${name}: no "more" tab`).toHaveCount(0);
      await page.screenshot({ path: `test-results/${name}-home.png` });
      await ctx.close();
    }
  });

  test('refresh keeps the session and the page; sign-out ends it and protected pages bounce to login', async ({ browser }) => {
    const st = load();
    const ctx = await browser.newContext({ locale: 'fa-IR', viewport: viewports.desktop });
    const page = await ctx.newPage();
    await uiLogin(page, student(st, 1), PASSWORD);
    await openApp(page, '/student/grades');
    await page.reload();
    await page.waitForSelector('flt-glass-pane', { state: 'attached', timeout: 60_000 });
    await enableSemantics(page);
    expect(new URL(page.url()).pathname).toBe('/student/grades');
    await expect(see(page, 'نمرات و کارنامه')).toBeVisible({ timeout: 20_000 });

    // sign out via the profile menu
    await openApp(page, '/profile');
    await tap(page.getByRole('button', { name: /خروج/ }).first());
    await expect.poll(() => new URL(page.url()).pathname, { timeout: 15_000 }).toBe('/login');
    await openApp(page, '/student/grades');
    expect(new URL(page.url()).pathname).toBe('/login');
    await ctx.close();
  });

  test('the API refuses an expired/forged token', async ({ request }) => {
    const r = await request.get(`${BASE}/api/v1/auth/me`, { headers: { Authorization: 'Bearer forged', Accept: 'application/json' } });
    expect(r.status()).toBe(401);
  });
});
