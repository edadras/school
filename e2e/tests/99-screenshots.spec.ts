import { test } from '@playwright/test';
import * as fs from 'fs';
import { Api, ROOT_PW, enableSemantics, openApp, uiLogin } from './helpers';
import { PASSWORD, load, student } from './state';

/** Not part of the acceptance suite: renders every screen of every panel to PNG (docs/screenshots). */
const OUT = process.env.SHOTS_DIR ?? '../docs/screenshots';
const shot = async (page: any, dir: string, name: string) => {
  await page.waitForTimeout(5000);
  fs.mkdirSync(`${OUT}/${dir}`, { recursive: true });
  await page.screenshot({ path: `${OUT}/${dir}/${name}.png` });
};
async function tour(browser: any, login: string, pw: string, dir: string, pages: [string, string][], vp = { width: 1366, height: 800 }) {
  const ctx = await browser.newContext({ locale: 'fa-IR', viewport: vp, permissions: ['microphone', 'camera'], isMobile: vp.width < 600, hasTouch: vp.width < 600 });
  const page = await ctx.newPage();
  await uiLogin(page, login, pw);
  for (const [path, name] of pages) {
    await openApp(page, path);
    if (path.startsWith('/report-card')) await page.waitForTimeout(7000);
    await shot(page, dir, name);
  }
  await ctx.close();
}

test.describe.serial('screenshots of every panel', () => {
  test.setTimeout(10 * 60_000);
  const st = load();
  const ids: any = {};

  test('collect ids', async () => {
    const admin = await Api.create(); await admin.login(st.ownerEmail, PASSWORD);
    try { await admin.post('/report-cards/generate', { section_id: st.secA.id, term_id: st.term.id }); await admin.post('/report-cards/issue-section', { section_id: st.secA.id, term_id: st.term.id }); } catch {}
    ids.card = (await admin.get(`/report-cards?status=issued&student_id=${st.students[0].id}`)).data[0]?.id;
    const stu = await Api.create(); await stu.login(student(st, 1), PASSWORD);
    ids.conv = (await stu.get('/conversations')).data?.[0]?.id;
    ids.assignment = st.assignmentId; ids.exam = st.examId; ids.live = st.liveSession;
    const root = await Api.create(); await root.login('root@e2e.test', ROOT_PW());
    try { await root.post('/platform/operators', { name: 'پشتیبان نمونه', email: `support-${st.code}@e2e.test`, role: 'support', password: PASSWORD }); } catch {}
  });

  test('platform (super admin)', async ({ browser }) => {
    await tour(browser, 'root@e2e.test', ROOT_PW(), '1-platform', ['dashboard:', 'approvals', 'schools', 'support', 'announcements', 'operators', 'audit', 'settings'].map(p => [`/platform${p === 'dashboard:' ? '' : '/' + p}`, p.replace(':', '')] as [string, string]).concat([['/profile', 'profile'], ['/notifications', 'notifications']]));
  });

  test('school admin', async ({ browser }) => {
    const p = ['', '/people', '/structure', '/timetable', '/live', '/attendance', '/learning', '/grades', '/reports', '/messages', '/ai', '/settings'];
    await tour(browser, st.ownerEmail, PASSWORD, '2-admin', [...p.map(x => [`/admin${x}`, x === '' ? 'dashboard' : x.slice(1)] as [string, string]), ['/notifications', 'notifications'], ['/profile', 'profile']]);
  });

  test('teacher', async ({ browser }) => {
    const list: [string, string][] = [['/teacher', 'home'], ['/teacher/classes', 'classes'], [`/teacher/classes/${st.secA.id}/${st.math.id}`, 'class-detail'], ['/teacher/assignments', 'assignments'],
      [`/teacher/assignments/${ids.assignment}`, 'assignment-review'], ['/teacher/exams', 'exams'], [`/teacher/exams/${ids.exam}`, 'exam-manage'], ['/teacher/grades', 'gradebook'], ['/teacher/messages', 'messages'],
      ['/teacher/ai', 'ai'], [`/live/${ids.live}`, 'live-class'], ['/notifications', 'notifications'], ['/profile', 'profile']];
    if (ids.conv) list.push([`/chat/${ids.conv}`, 'chat']);
    await tour(browser, st.teacher1, PASSWORD, '3-teacher', list);
  });

  test('student', async ({ browser }) => {
    const list: [string, string][] = [['/student', 'home'], ['/student/assignments', 'assignments'], [`/assignment/${ids.assignment}`, 'assignment-detail'], ['/student/exams', 'exams'], ['/student/learn', 'learn'],
      ['/student/grades', 'grades'], ['/student/messages', 'messages'], ['/student/ai', 'ai'], ['/notifications', 'notifications'], ['/profile', 'profile'], [`/live/${ids.live}`, 'live-class']];
    if (ids.card) list.push([`/report-card/${ids.card}`, 'report-card']);
    if (ids.conv) list.push([`/chat/${ids.conv}`, 'chat']);
    await tour(browser, student(st, 1), PASSWORD, '4-student', list);
    await tour(browser, student(st, 2), PASSWORD, '4-student-phone', [['/student', 'home'], ['/student/assignments', 'assignments'], ['/student/grades', 'grades'], ['/student/messages', 'messages']], { width: 390, height: 844 });
  });

  test('guardian', async ({ browser }) => {
    const list: [string, string][] = ['', '/schedule', '/attendance', '/grades', '/assignments', '/messages', '/meetings'].map(x => [`/guardian${x}`, x === '' ? 'home' : x.slice(1)] as [string, string]);
    await tour(browser, st.guardian1, PASSWORD, '5-guardian', [...list, ['/notifications', 'notifications'], ['/profile', 'profile']]);
  });

  test('support operator', async ({ browser }) => {
    await tour(browser, `support-${st.code}@e2e.test`, PASSWORD, '6-support', [['/support', 'tickets']]);
  });

  test('public pages', async ({ browser }) => {
    const ctx = await browser.newContext({ locale: 'fa-IR', viewport: { width: 1366, height: 800 } });
    const page = await ctx.newPage();
    for (const [p, n] of [['/login', 'login'], ['/register-school', 'register-school'], ['/forgot', 'forgot']]) { await openApp(page, p); await shot(page, '0-public', n); }
    await ctx.close();
  });
});
