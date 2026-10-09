import { test, expect } from '@playwright/test';
import { Api, newUserContext, openApp, see, tap, typeInto, uiLogin } from './helpers';
import { PASSWORD, load, save, student } from './state';

test.describe.serial('assignment lifecycle: publish → submit → return → resubmit → grade → approve → visible to student & parent', () => {
  let assignmentId = 0;

  test('teacher publishes an assignment; outsiders cannot see it', async () => {
    const st = load();
    const t = await Api.create();
    await t.login(st.teacher1, PASSWORD);
    const a = (await t.post('/assignments', {
      section_id: st.secA.id, subject_id: st.math.id, title: 'تمرین معادلهٔ درجه اول', description: 'مسئله‌های ۱ تا ۵ را حل کنید.', answer_types: ['text', 'file', 'math'],
      due_at: new Date(Date.now() + 2 * 86400_000).toISOString(), max_score: 20, publish: true, rubric: [{ title: 'روش حل', max: 10 }, { title: 'پاسخ نهایی', max: 10 }],
    })).data;
    assignmentId = a.id;
    save({ assignmentId });
    const other = await Api.create();                                   // student of the OTHER class
    await other.login(student(st, 4), PASSWORD);
    const r = await (other as any).ctx.get(`assignments/${a.id}`, { headers: { Authorization: `Bearer ${other.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(r.status()).toBe(404);
  });

  test('student writes an answer in the browser, attaches a file and submits', async ({ page }) => {
    const st = load();
    await uiLogin(page, student(st, 1), PASSWORD);
    await openApp(page, '/student/assignments');
    await expect(see(page, 'تمرین معادلهٔ درجه اول')).toBeVisible();
    await tap(see(page, 'تمرین معادلهٔ درجه اول'));
    await typeInto(page, page.getByRole('textbox', { name: /پاسخ را اینجا بنویسید/ }), 'x = 4 زیرا ۲x + ۳ = ۱۱');
    const chooser = page.waitForEvent('filechooser');
    await tap(page.getByRole('button', { name: /بارگذاری/ }));
    await (await chooser).setFiles({ name: 'solution.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64') });
    await expect(see(page, 'solution.png')).toBeVisible({ timeout: 20_000 });
    await tap(page.getByRole('button', { name: 'ارسال پاسخ' }));
    await expect(see(page, 'پاسخ شما ارسال شد.')).toBeVisible();
    const api = await Api.create();
    await api.login(student(st, 1), PASSWORD);
    const mine = (await api.get('/assignments')).data.find((x: any) => x.id === assignmentId);
    expect(mine.my_submission.status).toBe('submitted');
    expect((await api.get(`/assignments/${assignmentId}`)).my_submission.files).toHaveLength(1);
  });

  test('teacher reviews in the browser, returns it for revision; student resubmits; teacher finalises', async ({ browser }) => {
    const st = load();
    const [c, page] = await newUserContext(browser);
    await uiLogin(page, st.teacher1, PASSWORD);
    await openApp(page, `/teacher/assignments/${assignmentId}`);
    await expect(see(page, 'تمرین معادلهٔ درجه اول')).toBeVisible();
    await tap(see(page, 'علی کریمی'));
    await expect(see(page, 'x = 4')).toBeVisible();
    await typeInto(page, page.getByRole('textbox', { name: 'بازخورد' }), 'مرحلهٔ دوم را کامل‌تر بنویس.');
    await tap(page.getByRole('button', { name: 'بازگرداندن برای اصلاح' }));
    await expect(see(page, 'برای اصلاح بازگردانده شد.')).toBeVisible();

    const s = await Api.create();
    await s.login(student(st, 1), PASSWORD);
    let mine = (await s.get('/assignments')).data.find((x: any) => x.id === assignmentId);
    expect(mine.state).toBe('needs_revision');
    await s.post(`/assignments/${assignmentId}/submit`, { text_answer: 'x = 4؛ مرحله‌ها: ۲x = ۸ پس x = ۴' });

    await openApp(page, `/teacher/assignments/${assignmentId}`);
    await tap(see(page, 'علی کریمی'));
    await typeInto(page, page.getByRole('textbox', { name: /نمره/ }), '18');
    await tap(page.getByRole('button', { name: 'ثبت نهایی نمره' }));
    await expect(see(page, 'نمره ثبت شد')).toBeVisible();
    const t = await Api.create();
    await t.login(st.teacher1, PASSWORD);
    const hist = (await t.get(`/assignments/${assignmentId}/submissions`)).students.find((x: any) => x.student.id === st.students[0].id).submission;
    expect(hist.status).toBe('finalized');
    expect((await t.get(`/submissions/${hist.id}/history`)).data).toHaveLength(2);       // both attempts kept
    await c.close();
  });

  test('the grade stays hidden until the school approves it, then student and parent see it', async ({ page }) => {
    const st = load();
    const s = await Api.create();
    await s.login(student(st, 1), PASSWORD);
    expect((await s.get('/grades')).data).toHaveLength(0);                                // draft: invisible
    const admin = await Api.create();
    await admin.login(st.ownerEmail, PASSWORD);
    await admin.post('/grades/approve-bulk', { section_id: st.secA.id, term_id: st.term.id });
    expect((await s.get('/grades')).data).toHaveLength(1);

    await uiLogin(page, student(st, 1), PASSWORD);
    await openApp(page, '/student/grades');
    await expect(see(page, 'تمرین معادلهٔ درجه اول')).toBeVisible();
    // the parent sees the child; the other class's parent sees nothing of this child
    const p1 = await Api.create();
    await p1.login(st.guardian1, PASSWORD);
    expect((await p1.get('/grades')).data).toHaveLength(1);
    const p4 = await Api.create();
    await p4.login(st.guardian4, PASSWORD);
    expect((await p4.get('/grades')).data).toHaveLength(0);
    const kids = (await p4.get('/me/children')).data;
    expect(kids.map((k: any) => k.id)).not.toContain(st.students[0].id);
    const r = await (p4 as any).ctx.get(`attendance/students/${st.students[0].id}/summary`, { headers: { Authorization: `Bearer ${p4.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(r.status()).toBe(404);                                                           // knowing the id grants nothing
  });
});
