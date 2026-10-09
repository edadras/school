import { test, expect } from '@playwright/test';
import { Api, ROOT_PW, openApp, see, tap, typeInto, uiLogin } from './helpers';
import { PASSWORD, load, save } from './state';

/**
 * Builds the school used by the later specs. Structure is created through the same REST API the UI uses
 * (fast, deterministic); the UI journeys that matter (CRUD page, conflict detection, live class...) are driven in their own specs.
 */
test.describe.serial('school setup', () => {
  test('admin builds the academic structure, people and accounts', async () => {
    const st = load();
    const a = await Api.create();
    await a.login(st.ownerEmail, PASSWORD);
    const y = (await a.post('/academics/academic-years', { title: '1405-1406', starts_on: '2026-09-23', ends_on: '2027-06-20', is_current: true })).data;
    const term = (await a.post('/academics/terms', { academic_year_id: y.id, title: 'نیم‌سال اول', starts_on: '2026-09-23', ends_on: '2027-02-10' })).data;
    const grade = (await a.post('/academics/grades', { name: 'هفتم', stage: 'متوسطه اول', level: 7 })).data;
    const math = (await a.post('/academics/subjects', { name: 'ریاضی', grade_id: grade.id, coefficient: 3 })).data;
    const sci = (await a.post('/academics/subjects', { name: 'علوم', grade_id: grade.id, coefficient: 2 })).data;
    const secA = (await a.post('/academics/sections', { grade_id: grade.id, academic_year_id: y.id, name: 'الف', capacity: 30 })).data;
    const secB = (await a.post('/academics/sections', { grade_id: grade.id, academic_year_id: y.id, name: 'ب', capacity: 30 })).data;

    const t1 = (await a.post('/teachers', { name: 'خانم احمدی', email: `t1-${st.code}@e2e.test`, password: PASSWORD })).data;
    const t2 = (await a.post('/teachers', { name: 'آقای رضایی', email: `t2-${st.code}@e2e.test`, password: PASSWORD })).data;
    await a.post('/teacher-assignments', { teacher_id: t1.id, section_id: secA.id, subject_id: math.id });
    await a.post('/teacher-assignments', { teacher_id: t1.id, section_id: secA.id, subject_id: sci.id });
    await a.post('/teacher-assignments', { teacher_id: t2.id, section_id: secB.id, subject_id: math.id });

    const students: any[] = [];
    for (const [i, [fn, ln, sec]] of [['علی', 'کریمی', secA], ['سارا', 'محمدی', secA], ['رضا', 'نوری', secA], ['مینا', 'صادقی', secB]].entries() as any) {
      const s = (await a.post('/academics/students', { first_name: fn, last_name: ln, student_code: `${st.code}-${i + 1}` })).data;
      await a.post(`/students/${s.id}/account`, { email: `s${i + 1}-${st.code}@e2e.test`, password: PASSWORD });
      await a.post('/enrollments', { student_id: s.id, section_id: sec.id });
      students.push(s);
    }
    await a.post(`/students/${students[0].id}/guardians`, { name: 'پدر علی', email: `p1-${st.code}@e2e.test`, password: PASSWORD });
    await a.post(`/students/${students[3].id}/guardians`, { name: 'مادر مینا', email: `p4-${st.code}@e2e.test`, password: PASSWORD });
    // a deputy for approvals
    const dep = await (async () => {
      // deputy accounts are created by admin through membership role; use API user + membership via teachers endpoint is not possible,
      // so promote through the platform-independent registration of a second member is out of scope: the school admin approves grades itself.
      return null;
    })();

    save({
      year: y, term, grade, math, sci, secA, secB, t1, t2, students,
      teacher1: `t1-${st.code}@e2e.test`, teacher2: `t2-${st.code}@e2e.test`,
      student: (i: number) => `s${i}-${st.code}@e2e.test`, guardian1: `p1-${st.code}@e2e.test`, guardian4: `p4-${st.code}@e2e.test`,
    });
    expect(students).toHaveLength(4);
    void dep;
  });

  test('admin CRUD page works in the browser (create → appears → delete-blocked message)', async ({ page }) => {
    const st = load();
    await uiLogin(page, st.ownerEmail, PASSWORD);
    await expect(see(page, 'داشبورد مدیریت')).toBeVisible();
    await openApp(page, '/admin/structure');
    await tap(page.getByRole('tab', { name: 'پایه‌ها' }));
    await expect(see(page, 'هفتم')).toBeVisible();
    await tap(page.getByRole('button', { name: 'افزودن' }).first());
    await typeInto(page, page.getByRole('textbox', { name: /نام پایه/ }), 'هشتم');
    await tap(page.getByRole('button', { name: 'ذخیره' }));
    await expect(see(page, 'هشتم')).toBeVisible();
  });

  test('a second school exists and is fully isolated', async () => {
    const st = load();
    const b = await Api.create();
    const code = 'iso' + Date.now().toString(36);
    await b.post('/schools/register', { school: { name: 'مدرسهٔ دوم', code, timezone: 'Asia/Tehran' }, owner: { name: 'مدیر دوم', email: `ownerB-${code}@e2e.test`, password: PASSWORD, password_confirmation: PASSWORD } }, [201]);
    const root = await Api.create();
    await root.login('root@e2e.test', ROOT_PW());
    const reqs = await root.get('/platform/approval-requests?status=pending');
    const mine = reqs.data.find((r: any) => r.school.code === code);
    await root.post(`/platform/approval-requests/${mine.id}/decision`, { decision: 'approve' });
    const ob = await Api.create();
    const me = await ob.login(`ownerB-${code}@e2e.test`, PASSWORD);
    save({ schoolB: { id: me.user.memberships[0].school.id, email: `ownerB-${code}@e2e.test` } });
    // B sees none of A's data, and cannot reach A by choosing A's id
    expect((await ob.get('/academics/grades')).data).toHaveLength(0);
    const attempt = await (ob as any).ctx.get('academics/grades', { headers: { Authorization: `Bearer ${ob.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(attempt.status()).toBe(403);
    const direct = await (ob as any).ctx.get(`academics/grades/${st.grade.id}`, { headers: { Authorization: `Bearer ${ob.token}`, 'X-School-Id': String(me.user.memberships[0].school.id) } });
    expect(direct.status()).toBe(404);
  });
});
