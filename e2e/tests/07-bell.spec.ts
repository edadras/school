import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { Api, newUserContext, openApp, see, uiLogin } from './helpers';
import { PASSWORD, load, save, student } from './state';

/** HH:MM and weekday (0 = Saturday) in the school's timezone, `plusMin` minutes from now. */
function local(plusMin: number, tz = 'Asia/Tehran') {
  const d = new Date(Date.now() + plusMin * 60_000);
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', hourCycle: 'h23', weekday: 'short' }).formatToParts(d).map(p => [p.type, p.value]));
  const wd = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'].indexOf(parts.weekday);
  return { hm: `${parts.hour}:${parts.minute}`, wd };
}

test.describe.serial('bell engine: server-time schedule fires once, notifies the right people, survives repeated ticks', () => {
  test.setTimeout(8 * 60_000);
  const tag = `${Date.now()}`;
  let periodId = 0, entryId = 0;

  test('a lesson starting in ~2 minutes is scheduled and activated', async () => {
    const st = load();
    const admin = await Api.create();
    await admin.login(st.ownerEmail, PASSWORD);
    const s = local(2), e = local(4);
    test.skip(s.wd !== e.wd || s.hm > e.hm, 'window crosses midnight');
    const tt = (await admin.post('/timetables', {
      academic_year_id: st.year.id, title: `bell-${tag}`, working_days: [0, 1, 2, 3, 4, 5, 6],
      periods: [{ kind: 'lesson', title: 'زنگ آزمایشی', starts_at: s.hm, ends_at: e.hm }],
    })).data;
    const detail = (await admin.get(`/timetables/${tt.id}`)).data;
    periodId = detail.periods[0].id;
    const entry = (await admin.post(`/timetables/${tt.id}/entries`, { period_id: periodId, weekday: s.wd, section_id: st.secA.id, subject_id: st.math.id, teacher_id: st.t1.id })).data;
    entryId = entry.id;
    await admin.post(`/timetables/${tt.id}/activate`);
    save({ bellTimetable: tt.id });
  });

  test('at the start time the teacher and the class get exactly one bell; a lesson session exists', async ({ browser }) => {
    const st = load();
    // a student is online in a real browser while the bell fires
    const [c, page] = await newUserContext(browser);
    await uiLogin(page, student(st, 1), PASSWORD);
    await openApp(page, '/student');

    const stu = await Api.create(); await stu.login(student(st, 1), PASSWORD);
    const tea = await Api.create(); await tea.login(st.teacher1, PASSWORD);
    const bells = async (api: Api) => (await api.get('/me/notifications')).data.filter((n: any) => n.type === 'bell.lesson_start' && n.data?.entry_id === entryId);
    await expect.poll(async () => (await bells(stu)).length, { timeout: 5 * 60_000, intervals: [5000] }).toBe(1);
    expect(await bells(tea)).toHaveLength(1);
    const outsider = await Api.create(); await outsider.login(student(st, 4), PASSWORD);
    expect(await bells(outsider)).toHaveLength(0);                              // other class hears nothing

    const sessions = (await tea.get('/sessions')).data.filter((x: any) => x.timetable_entry_id === entryId);
    expect(sessions).toHaveLength(1);
    save({ bellSession: sessions[0].id });

    // repeated / overlapping ticks must not duplicate anything
    for (let i = 0; i < 3; i++) execSync('php artisan bell:tick', { cwd: '../backend', env: { ...process.env, APP_ENV: 'e2e' }, stdio: 'ignore' });
    expect(await bells(stu)).toHaveLength(1);
    expect((await tea.get('/sessions')).data.filter((x: any) => x.timetable_entry_id === entryId)).toHaveLength(1);

    await openApp(page, '/notifications');
    await expect(see(page, 'شروع زنگ آزمایشی')).toBeVisible({ timeout: 20_000 });
    await c.close();
  });

  test('a class nobody started is reported "not held" when its period ends', async () => {
    const st = load();
    const admin = await Api.create(); await admin.login(st.ownerEmail, PASSWORD);
    const sessionOf = async () => (await admin.get('/sessions')).data.find((x: any) => x.id === st.bellSession);
    await expect.poll(async () => (await sessionOf())?.status, { timeout: 4 * 60_000, intervals: [5000] }).toBe('not_held');
    const mgr = (await admin.get('/me/notifications')).data;
    expect(mgr.some((n: any) => n.type === 'session.not_held' && n.data?.session_id === st.bellSession)).toBeTruthy();
  });
});
