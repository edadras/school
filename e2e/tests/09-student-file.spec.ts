import { test, expect } from '@playwright/test';
import { Api, ROOT_PW, openApp, see, tap, uiLogin } from './helpers';
import { PASSWORD, load, student } from './state';

test.describe.serial('student file, year-end promotion, erasure, platform metrics', () => {
  test('a student and a guardian see the continuous file; another family cannot', async ({ page }) => {
    const st = load();
    await uiLogin(page, student(st, 1), PASSWORD);
    await openApp(page, '/student/grades');
    await tap(page.getByRole('button', { name: 'پرونده تحصیلی' }));
    await expect(see(page, 'علی کریمی')).toBeVisible({ timeout: 20_000 });
    await expect(see(page, 'سوابق ثبت‌نام و ارتقا')).toBeVisible();
    await expect(see(page, 'نمرات تأییدشده به تفکیک دوره')).toBeVisible();
    const other = await Api.create(); await other.login(st.guardian4, PASSWORD);
    const r = await (other as any).ctx.get(`students/${st.students[0].id}/dossier`, { headers: { Authorization: `Bearer ${other.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(r.status()).toBe(404);
  });

  test('admin promotes the class into next year; history is kept; second run changes nothing', async ({ page }) => {
    const st = load();
    const a = await Api.create(); await a.login(st.ownerEmail, PASSWORD);
    const y = (await a.post('/academics/academic-years', { title: `next-${Date.now()}`, starts_on: '2027-09-23', ends_on: '2028-06-20' })).data;
    const to = (await a.post('/academics/sections', { grade_id: st.grade.id, academic_year_id: y.id, name: 'الف', capacity: 30 })).data;
    const res = await a.post('/students/promote', { from_section_id: st.secA.id, to_section_id: to.id, student_ids: [st.students[1].id] });
    expect(res.promoted).toBe(1);
    expect((await a.post('/students/promote', { from_section_id: st.secA.id, to_section_id: to.id, student_ids: [st.students[1].id] })).promoted).toBe(0);
    const d = (await a.get(`/students/${st.students[1].id}/dossier`)).data;
    expect(d.enrollments.map((e: any) => e.status)).toEqual(['promoted', 'active']);
    await uiLogin(page, st.ownerEmail, PASSWORD);
    await openApp(page, `/file/${st.students[1].id}`);
    await expect(see(page, 'ارتقا یافته')).toBeVisible({ timeout: 20_000 });
  });

  test('erasure removes identity but keeps the academic record', async () => {
    const st = load();
    const a = await Api.create(); await a.login(st.ownerEmail, PASSWORD);
    const s = (await a.post('/academics/students', { first_name: 'موقت', last_name: 'حذف', student_code: `del-${Date.now()}` })).data;
    await a.post(`/students/${s.id}/anonymize`, { confirm_code: 'wrong' }, [422]);
    await a.post(`/students/${s.id}/anonymize`, { confirm_code: s.student_code });
    const after = (await a.get(`/academics/students/${s.id}`)).data;
    expect(after.first_name).toBe('حذف‌شده');
    expect(after.status).toBe('archived');
  });

  test('platform dashboard shows measured API performance', async ({ page }) => {
    await uiLogin(page, 'root@e2e.test', ROOT_PW());
    await openApp(page, '/platform');
    await expect(see(page, 'عملکرد API (۲۴ ساعت اخیر)')).toBeVisible({ timeout: 20_000 });
    await expect(see(page, 'میانگین تأخیر (ms)')).toBeVisible();
  });
});
