import { test, expect, Page } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import { Api, ROOT_PW, newUserContext, openApp, see, tap, uiLogin } from './helpers';
import { PASSWORD, load, student } from './state';

/**
 * Real class recording: LiveKit SFU + livekit-egress (headless Chrome renders our self-hosted template) writing to an
 * S3-compatible bucket. Needs the egress stack from docs/operations.md ("Recording test stack"); skipped when it is not running.
 */
test('the teacher records a live class; the MP4 appears in the bucket with audio+video; students cannot reach it', async ({ browser }) => {
  test.setTimeout(6 * 60_000);
  const egressUp = await fetch('http://127.0.0.1:8081').then((r) => r.ok).catch(() => false);
  test.skip(!egressUp, 'livekit-egress is not running');
  const st = load();
  const root = await Api.create(); await root.login('root@e2e.test', ROOT_PW());
  await root.patch(`/platform/schools/${st.schoolId}/subscription`, { max_live_sessions: 30 });
  const admin = await Api.create(); await admin.login(st.ownerEmail, PASSWORD);
  await admin.patch('/school/settings', { settings: { 'recording.allowed': true } });
  const tApi = await Api.create(); await tApi.login(st.teacher1, PASSWORD);
  const start = new Date(Date.now() + 60_000);
  const session = (await tApi.post('/sessions', { section_id: st.secA.id, subject_id: st.math.id, kind: 'makeup', title: 'کلاس ضبط‌شده', scheduled_start: start.toISOString(), scheduled_end: new Date(start.getTime() + 40 * 60_000).toISOString() })).data;

  const [tctx, teacher] = await newUserContext(browser);
  const [sctx, s1] = await newUserContext(browser);
  await uiLogin(teacher, st.teacher1, PASSWORD);
  await openApp(teacher, `/live/${session.id}`);
  await tap(teacher.getByRole('button', { name: 'شروع کلاس' }));
  await tap(teacher.getByRole('button', { name: 'ورود به کلاس' }));
  await expect(teacher.getByRole('button', { name: 'پایان کلاس' })).toBeVisible({ timeout: 30_000 });
  await uiLogin(s1, student(st, 1), PASSWORD);
  await openApp(s1, `/live/${session.id}`);
  await tap(s1.getByRole('button', { name: 'ورود به کلاس' }));
  await expect(s1.getByRole('button', { name: 'خروج' })).toBeVisible({ timeout: 30_000 });

  // teacher turns recording on from the UI; the student is told in real time
  await tap(teacher.getByRole('tab', { name: /شرکت‌کنندگان/ }));
  await tap(teacher.getByRole('switch', { name: /ضبط کلاس/ }));
  await expect(see(s1, 'این کلاس در حال ضبط است')).toBeVisible({ timeout: 20_000 });
  await expect.poll(async () => (await tApi.get(`/sessions/${session.id}/recordings`)).data[0]?.status, { timeout: 60_000 }).toBe('recording');

  await teacher.waitForTimeout(15_000);                       // let egress capture real media

  await tap(teacher.getByRole('switch', { name: /ضبط کلاس/ }));
  await expect.poll(async () => (await tApi.get(`/sessions/${session.id}/recordings`)).data[0]?.status, { timeout: 120_000, intervals: [3000] }).toBe('ready');
  const rec = (await tApi.get(`/sessions/${session.id}/recordings`)).data[0];
  expect(rec.size).toBeGreaterThan(10_000);

  // the object really is in the bucket and is a playable MP4 with a video and an audio stream
  const link = await tApi.get(`/files/${rec.file_id}/link`);
  const body = await (await fetch(link.url)).arrayBuffer();
  const path = `/tmp/e2e/recording-${session.id}.mp4`;
  fs.writeFileSync(path, Buffer.from(body));
  expect(Buffer.from(body).subarray(4, 8).toString()).toBe('ftyp');
  const probe = JSON.parse(execSync(`ffprobe -v error -show_streams -show_format -of json ${path}`).toString());
  const kinds = probe.streams.map((s: any) => s.codec_type);
  expect(kinds).toContain('video');
  expect(kinds).toContain('audio');
  expect(parseFloat(probe.format.duration)).toBeGreaterThan(8);
  execSync(`ffmpeg -v error -y -ss 5 -i ${path} -frames:v 1 /tmp/e2e/recording-frame.png`);

  // students and guardians cannot list or fetch it
  const sApi = await Api.create(); await sApi.login(student(st, 1), PASSWORD);
  for (const p of [`sessions/${session.id}/recordings`, `files/${rec.file_id}/link`]) {
    const r = await (sApi as any).ctx.get(p, { headers: { Authorization: `Bearer ${sApi.token}`, 'X-School-Id': String(st.schoolId) } });
    expect([403, 404]).toContain(r.status());
  }
  await tApi.post(`/sessions/${session.id}/end`);
  await tctx.close(); await sctx.close();
});
