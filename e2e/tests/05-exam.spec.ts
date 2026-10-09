import { test, expect } from '@playwright/test';
import { Api, newUserContext, openApp, see, tap, typeInto, uiLogin } from './helpers';
import { PASSWORD, load, save, student } from './state';
const EXAM_TITLE = `آزمون میان‌ترم ریاضی ${Date.now()}`;

test.describe.serial('timed exam: take, survive refresh and network loss, auto + manual grading, controlled result release', () => {
  let examId = 0;
  const q: Record<string, number> = {};

  test('teacher builds the bank and a timed exam', async () => {
    const st = load();
    const t = await Api.create();
    await t.login(st.teacher1, PASSWORD);
    const sub = st.math.id;
    q.mcq = (await t.post('/questions', { subject_id: sub, type: 'mcq', body: 'حاصل ۲ + ۲ چند است؟', topic: 'جمع', points: 2, options: [{ text: '۳' }, { text: '۴', is_correct: true }, { text: '۵' }] })).data.id;
    q.fill = (await t.post('/questions', { subject_id: sub, type: 'fill', body: 'پایتخت ایران ____ است.', topic: 'جغرافیا', points: 1, accepted_answers: ['تهران'] })).data.id;
    q.essay = (await t.post('/questions', { subject_id: sub, type: 'essay', body: 'قضیهٔ فیثاغورس را توضیح دهید.', topic: 'هندسه', points: 5, rubric: 'بیان قضیه ۳ نمره، مثال ۲ نمره' })).data.id;
    const exam = (await t.post('/exams', {
      section_id: st.secA.id, subject_id: sub, title: EXAM_TITLE, start_at: new Date(Date.now() + 3000).toISOString(), end_at: new Date(Date.now() + 40 * 60_000).toISOString(),
      duration_minutes: 20, max_attempts: 1, shuffle_questions: true, shuffle_options: true, show_score: 'manual', show_answers: 'after_end',
      questions: [{ question_id: q.mcq }, { question_id: q.fill }, { question_id: q.essay }],
    })).data;
    await t.post(`/exams/${exam.id}/publish`);
    examId = exam.id;
    save({ examId, q });
    await new Promise((r) => setTimeout(r, 4000));
  });

  test('student takes the exam in the browser; refresh and offline do not lose answers', async ({ browser }) => {
    const st = load();
    const [ctx, page] = await newUserContext(browser);
    await uiLogin(page, student(st, 1), PASSWORD);
    await openApp(page, '/student/exams');
    await expect(see(page, EXAM_TITLE)).toBeVisible();
    await tap(page.getByLabel(EXAM_TITLE).getByRole('button', { name: 'شروع / ادامهٔ آزمون' }).first());
    await tap(page.getByRole('button', { name: 'شروع', exact: true }));
    await expect(page.getByRole('button', { name: 'ارسال نهایی' })).toBeVisible({ timeout: 20_000 });
    const url = page.url();

    // find the fill question wherever shuffling put it, answer it
    const answerFill = async () => {
      for (let i = 0; i < 3; i++) {
        const box = page.getByRole('textbox', { name: /پایتخت ایران/ });
        if (await box.count()) { await typeInto(page, box, 'تهران'); return; }
        await tap(page.getByRole('button', { name: 'بعدی' }));
        await page.waitForTimeout(300);
      }
    };
    await answerFill();
    await page.waitForTimeout(2500);                                   // autosave debounce

    const api = await Api.create();
    await api.login(student(st, 1), PASSWORD);
    const attemptId = Number(new URL(url).pathname.split('/').pop());
    const saved = async () => (await api.get(`/exam-attempts/${attemptId}`)).questions.filter((x: any) => x.saved_answer).length;
    expect(await saved()).toBe(1);                                      // server has it

    // refresh the page: same attempt, saved answer, timer from the server deadline
    await openApp(page, new URL(url).pathname);
    await expect(page.getByRole('button', { name: 'ارسال نهایی' })).toBeVisible({ timeout: 20_000 });
    expect(await saved()).toBe(1);

    // lose the network, answer the multiple-choice question, come back: it is delivered
    await ctx.setOffline(true);
    for (let i = 0; i < 3; i++) {
      const opt = page.getByRole('button', { name: '۴', exact: true });
      if (await opt.count()) { await tap(opt); break; }
      await tap(page.getByRole('button', { name: 'بعدی' }));
      await page.waitForTimeout(300);
    }
    await page.waitForTimeout(2500);
    expect(await saved()).toBe(1);                                      // not yet on the server
    await ctx.setOffline(false);
    await expect.poll(saved, { timeout: 30_000 }).toBe(2);

    await tap(page.getByRole('button', { name: 'ارسال نهایی' }));
    await tap(page.getByRole('button', { name: 'ارسال نهایی' }).last());
    await expect(see(page, 'پاسخ‌های شما ثبت شد.')).toBeVisible({ timeout: 30_000 });
    await ctx.close();
  });

  test('objective answers are auto-graded; the essay waits for the teacher; nothing leaks before release', async () => {
    const st = load();
    const s = await Api.create();
    await s.login(student(st, 1), PASSWORD);
    const res = await s.get(`/exams/${examId}/my-result`);
    expect(res.score_visible).toBe(false);
    expect(res.answers_visible).toBe(false);
    const t = await Api.create();
    await t.login(st.teacher1, PASSWORD);
    const attempts = (await t.get(`/exams/${examId}/attempts`)).data;
    expect(attempts).toHaveLength(1);
    expect(Number(attempts[0].auto_score)).toBe(3);                      // mcq 2 + fill 1
    expect(attempts[0].status).toBe('submitted');
    const detail = await t.get(`/exam-attempts/${attempts[0].id}/detail`);
    const essay = detail.answers.find((a: any) => a.question.type === 'essay');
    await t.put(`/exam-answers/${essay.id}/grade`, { score: 4, feedback: 'مثال کم بود' });
    const after = (await t.get(`/exams/${examId}/attempts`)).data[0];
    expect([after.status, Number(after.total_score)]).toEqual(['graded', 7]);
    await t.post(`/exams/${examId}/release`);
    const out = await s.get(`/exams/${examId}/my-result`);
    expect(out.score_visible).toBe(true);
    expect(Number(out.total_score)).toBe(7);
    expect(out.answers_visible).toBe(false);                            // window still open: correct answers stay hidden
  });

  test('a student who already used the attempt cannot start again; analysis is available to the teacher only', async () => {
    const st = load();
    const s = await Api.create();
    await s.login(student(st, 1), PASSWORD);
    const r = await (s as any).ctx.post(`exams/${examId}/start`, { headers: { Authorization: `Bearer ${s.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(r.status()).toBe(422);
    const a = await (s as any).ctx.get(`exams/${examId}/analysis`, { headers: { Authorization: `Bearer ${s.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(a.status()).toBe(403);
    const t = await Api.create();
    await t.login(st.teacher1, PASSWORD);
    const an = (await t.get(`/exams/${examId}/analysis`)).data;
    expect(an.attempts).toBe(1);
    expect(an.questions).toHaveLength(3);
  });
});
