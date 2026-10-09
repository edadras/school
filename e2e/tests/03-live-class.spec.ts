import { test, expect, Page } from '@playwright/test';
import { Api, ROOT_PW, newUserContext, openApp, see, tap, typeInto, uiLogin } from './helpers';
import { PASSWORD, load, save, student } from './state';

/** Real WebRTC: LiveKit SFU + Chromium fake camera/mic. One teacher and two students in the same room. */
test('teacher and students hold a real audio/video class with attendance, chat, hand raise and whiteboard', async ({ browser }) => {
  const st = load();
  // plan limits are enforced (free plan = 2 concurrent classes); the platform admin raises them for this school
  const root = await Api.create();
  await root.login('root@e2e.test', ROOT_PW());
  await root.patch(`/platform/schools/${st.schoolId}/subscription`, { max_live_sessions: 10 });
  const teacherApi = await Api.create();
  await teacherApi.login(st.teacher1, PASSWORD);
  const start = new Date(Date.now() + 60_000), end = new Date(Date.now() + 50 * 60_000);
  const session = (await teacherApi.post('/sessions', { section_id: st.secA.id, subject_id: st.math.id, kind: 'makeup', title: 'کلاس جبرانی ریاضی', scheduled_start: start.toISOString(), scheduled_end: end.toISOString() })).data;
  save({ liveSession: session.id });

  const [tctx, teacher] = await newUserContext(browser);
  const [s1ctx, s1] = await newUserContext(browser);
  const [s2ctx, s2] = await newUserContext(browser);

  // students are told the class exists
  const studentApi = await Api.create();
  await studentApi.login(student(st, 1), PASSWORD);
  const feed = await studentApi.get('/me/notifications');
  expect(feed.data.some((n: any) => n.type === 'session.scheduled')).toBeTruthy();

  // teacher starts the class from the UI
  await uiLogin(teacher, st.teacher1, PASSWORD);
  await openApp(teacher, `/live/${session.id}`);
  await tap(teacher.getByRole('button', { name: 'شروع کلاس' }));
  await expect(see(teacher, 'در حال برگزاری')).toBeVisible({ timeout: 20_000 });
  await tap(teacher.getByRole('button', { name: 'ورود به کلاس' }));
  await expect(teacher.getByRole('button', { name: 'پایان کلاس' })).toBeVisible({ timeout: 30_000 });

  // students join
  for (const [i, p] of [[1, s1], [2, s2]] as [number, Page][]) {
    await uiLogin(p, student(st, i), PASSWORD);
    await expect(see(p, 'سلام')).toBeVisible();
    await openApp(p, `/live/${session.id}`);
    await tap(p.getByRole('button', { name: 'ورود به کلاس' }));
    await expect(p.getByRole('button', { name: 'خروج' })).toBeVisible({ timeout: 30_000 });
  }

  // media really flows: the student's page has a decoded remote video frame (the teacher's fake camera)
  await expect.poll(async () => s1.evaluate(() => Array.from(document.querySelectorAll('video')).filter((v: any) => v.videoWidth > 0).length), { timeout: 30_000 }).toBeGreaterThan(0);
  await expect.poll(async () => teacher.evaluate(() => Array.from(document.querySelectorAll('video')).filter((v: any) => v.videoWidth > 0).length), { timeout: 30_000 }).toBeGreaterThan(0);

  // server truth: participants + automatic attendance
  const live = await teacherApi.get(`/sessions/${session.id}`);
  expect(live.participants.length).toBe(3);
  const att = await teacherApi.get(`/attendance?section_id=${st.secA.id}`);
  expect(att.data.filter((a: any) => a.lesson_session_id === session.id && (a.status === 'present' || a.status === 'late')).length).toBe(2);

  // hand raise → teacher sees the speaking queue
  await tap(s1.getByRole('button', { name: 'دست بلند کردن' }));
  await expect.poll(async () => (await teacherApi.get(`/sessions/${session.id}`)).hands.length).toBe(1);

  // chat both ways
  await tap(s1.getByRole('tab', { name: 'گفت‌وگو' }));
  await typeInto(s1, s1.getByRole('textbox', { name: 'پیام...' }), 'سلام معلم، صدا می‌آید؟');
  await s1.keyboard.press('Enter');
  await expect.poll(async () => JSON.stringify(await studentApi.get(`/conversations`)), { timeout: 20_000 }).toContain('سلام معلم');

  // whiteboard: teacher draws on the canvas; late events are persisted for replay
  await tap(teacher.getByRole('tab', { name: 'تخته' }));
  await teacher.waitForTimeout(500);
  const box = await teacher.locator('flt-glass-pane').boundingBox();
  await teacher.mouse.move(box!.x + 60, box!.y + 300);
  await teacher.mouse.down();
  for (let i = 0; i < 12; i++) await teacher.mouse.move(box!.x + 60 + i * 8, box!.y + 300 + i * 5);
  await teacher.mouse.up();
  await expect.poll(async () => (await teacherApi.get(`/sessions/${session.id}/whiteboard`)).data.length, { timeout: 15_000 }).toBeGreaterThan(0);

  // a student drops offline and returns: the app reconnects by itself
  await s2ctx.setOffline(true);
  await s2.waitForTimeout(3000);
  await s2ctx.setOffline(false);
  await expect(s2.getByRole('button', { name: 'خروج' })).toBeVisible({ timeout: 60_000 });

  // teacher ends the class: everyone is released, attendance is finalised, status = ended
  await tap(teacher.getByRole('button', { name: 'پایان کلاس' }));
  await tap(teacher.getByRole('button', { name: 'پایان کلاس' }).last());
  await expect(see(s1, 'کلاس پایان یافت')).toBeVisible({ timeout: 30_000 });
  const done = await teacherApi.get(`/sessions/${session.id}`);
  expect(done.data.status).toBe('ended');
  const absent = (await teacherApi.get(`/attendance?section_id=${st.secA.id}&status=absent`)).data.filter((a: any) => a.lesson_session_id === session.id);
  expect(absent.length).toBe(1);                       // third student never joined → absent (guardian notified)
  for (const c of [tctx, s1ctx, s2ctx]) await c.close();
});
