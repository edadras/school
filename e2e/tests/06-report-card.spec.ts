import { test, expect } from '@playwright/test';
import { Api, openApp, see, tap, uiLogin } from './helpers';
import { PASSWORD, load, student } from './state';

test.describe.serial('report card: draft is private, issued card reaches student and guardian as a Persian PDF, outsiders get nothing', () => {
  let cardId = 0;

  test('admin generates drafts from approved grades only; nobody else sees a draft', async () => {
    const st = load();
    const admin = await Api.create();
    await admin.login(st.ownerEmail, PASSWORD);
    await admin.post('/grades/approve-bulk', { section_id: st.secA.id, term_id: st.term.id });
    const gen = await admin.post('/report-cards/generate', { section_id: st.secA.id, term_id: st.term.id });
    expect(gen.generated).toBeGreaterThan(0);
    const cards = (await admin.get(`/report-cards?section_id=${st.secA.id}&term_id=${st.term.id}&student_id=${st.students[0].id}`)).data;
    expect(cards.length).toBeGreaterThan(0);
    cardId = cards[0].id;
    expect(cards[0].status).toBe('draft');

    const s = await Api.create();
    await s.login(student(st, 1), PASSWORD);
    expect((await s.get('/report-cards')).data).toHaveLength(0);             // draft invisible to the student…
    const p = await Api.create();
    await p.login(st.guardian1, PASSWORD);
    expect((await p.get('/report-cards')).data).toHaveLength(0);             // …and to the guardian
  });

  test('issuing makes it visible to exactly the right student and guardian', async () => {
    const st = load();
    const admin = await Api.create();
    await admin.login(st.ownerEmail, PASSWORD);
    await admin.post(`/report-cards/${cardId}/issue`);
    const s = await Api.create();
    await s.login(student(st, 1), PASSWORD);
    expect((await s.get('/report-cards')).data.map((c: any) => c.id)).toContain(cardId);
    const p = await Api.create();
    await p.login(st.guardian1, PASSWORD);
    expect((await p.get('/report-cards')).data.map((c: any) => c.id)).toContain(cardId);
    const other = await Api.create();
    await other.login(st.guardian4, PASSWORD);
    expect((await other.get('/report-cards')).data.map((c: any) => c.id)).not.toContain(cardId);
    const r = await (other as any).ctx.get(`report-cards/${cardId}/pdf`, { headers: { Authorization: `Bearer ${other.token}`, 'X-School-Id': String(st.schoolId) } });
    expect(r.status()).toBe(404);                                            // another family cannot fetch the PDF by id
  });

  test('guardian opens the PDF from the UI; the server returns a real private PDF', async ({ page }) => {
    const st = load();
    await uiLogin(page, st.guardian1, PASSWORD);
    await openApp(page, '/guardian/grades');
    await expect(see(page, 'کارنامه — معدل')).toBeVisible({ timeout: 20_000 });
    const pdf = page.waitForResponse(r => r.url().includes(`/report-cards/${cardId}/pdf`));
    await tap(see(page, 'کارنامه — معدل'));
    const res = await pdf;
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('application/pdf');
    expect(res.headers()['cache-control']).toContain('no-store');
    expect((await res.body()).subarray(0, 4).toString()).toBe('%PDF');
    await page.waitForTimeout(3000);
    await expect(see(page, 'خطا')).toHaveCount(0);
    await page.screenshot({ path: 'test-results/report-card-viewer.png' });
  });

  test('a revoked card disappears for the family but stays in the school record', async () => {
    const st = load();
    const admin = await Api.create();
    await admin.login(st.ownerEmail, PASSWORD);
    await admin.post(`/report-cards/${cardId}/revoke`, { reason: 'اصلاح نمره' });
    const p = await Api.create();
    await p.login(st.guardian1, PASSWORD);
    expect((await p.get('/report-cards')).data.map((c: any) => c.id)).not.toContain(cardId);
    expect((await admin.get(`/report-cards/${cardId}`)).data.status).toBe('revoked');
  });
});
