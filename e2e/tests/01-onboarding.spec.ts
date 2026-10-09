import { test, expect } from '@playwright/test';
import { Api, ROOT_PW, openApp, see, tap, typeInto, uiLogin } from './helpers';
import { PASSWORD, save } from './state';

const code = 'hope' + Date.now().toString(36);
const ownerEmail = `owner-${code}@e2e.test`;

test.describe.serial('school onboarding: register → platform approval → activation', () => {
  test('owner registers a school through the real form', async ({ page }) => {
    await openApp(page, '/register-school');
    await expect(see(page, 'ثبت‌نام سازمانی مدرسه')).toBeVisible();
    const f = (n: RegExp) => page.getByRole('textbox', { name: n });
    await typeInto(page, f(/^نام مدرسه/), 'مدرسهٔ امید');
    await typeInto(page, f(/شناسهٔ یکتا/), code);
    await typeInto(page, f(/^شهر/), 'تهران');
    await typeInto(page, f(/نام و نام خانوادگی/), 'مدیر نمونه');
    await typeInto(page, f(/ایمیل \(نام کاربری\)/), ownerEmail);
    await typeInto(page, f(/^رمز عبور \(حداقل/), PASSWORD);
    await typeInto(page, f(/^تکرار رمز عبور/), PASSWORD);
    await tap(page.getByRole('button', { name: 'ارسال درخواست' }));
    await expect(see(page, 'درخواست شما ثبت شد')).toBeVisible();
    save({ code, ownerEmail });
  });

  test('a pending school cannot operate: owner sees the approval status page', async ({ page }) => {
    await uiLogin(page, ownerEmail, PASSWORD);
    await expect(see(page, 'وضعیت درخواست مدرسه')).toBeVisible();
    await expect(see(page, 'در انتظار بررسی')).toBeVisible();
    // and the API refuses school data
    const api = await Api.create();
    const me = await api.login(ownerEmail, PASSWORD);
    const r = await (api as any).ctx.get('academics/grades', { headers: { Authorization: `Bearer ${api.token}`, 'X-School-Id': String(me.user.memberships[0].school.id) } });
    expect(r.status()).toBe(403);
  });

  test('platform admin approves it from the panel', async ({ page }) => {
    await uiLogin(page, 'root@e2e.test', ROOT_PW());
    await expect(see(page, 'نمای کلی سامانه')).toBeVisible();
    await openApp(page, '/platform/approvals');                       // direct URL + refresh keeps the session
    await expect(see(page, 'درخواست‌های فعال‌سازی مدرسه')).toBeVisible();
    await expect(see(page, code)).toBeVisible();
    await tap(page.getByRole('button', { name: 'تأیید' }).first());
    await tap(page.getByRole('button', { name: 'تأیید و فعال‌سازی' }));
    await expect(see(page, 'ثبت شد.')).toBeVisible();
  });

  test('after approval the owner lands in the school admin dashboard', async ({ page }) => {
    await uiLogin(page, ownerEmail, PASSWORD);
    await expect(see(page, 'داشبورد مدیریت')).toBeVisible();
    await expect(see(page, 'مدرسهٔ امید')).toBeVisible();
    const api = await Api.create();
    const me = await api.login(ownerEmail, PASSWORD);
    save({ schoolId: me.user.memberships[0].school.id });
    // audit trail exists for the decision
    const root = await Api.create();
    await root.login('root@e2e.test', ROOT_PW());
    const audit = await root.get('/platform/audit?action=school.approved');
    expect(audit.data.length).toBeGreaterThan(0);
  });
});
