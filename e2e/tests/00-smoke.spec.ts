import { test, expect } from '@playwright/test';
import { openApp, see, uiLogin } from './helpers';

test('login page renders RTL Persian and rejects bad credentials', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await openApp(page, '/');
  await expect(see(page, 'ورود به سامانه مدرسه')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.dir)).toBe('rtl');
  await uiLogin(page, 'nobody@e2e.test', 'wrong-password-123', { expectFail: true });
  await expect(see(page, 'اطلاعات ورود نادرست است.')).toBeVisible();
  expect(errors).toEqual([]);
});
