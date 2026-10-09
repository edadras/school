import * as fs from 'fs';

/** Shared scenario state between serial spec files (written by earlier specs, read by later ones). */
const FILE = process.env.E2E_STATE ?? '/tmp/e2e/state.json';

export function load(): any {
  try { return JSON.parse(fs.readFileSync(FILE, 'utf8')); } catch { return {}; }
}
export function save(patch: any) {
  fs.writeFileSync(FILE, JSON.stringify({ ...load(), ...patch }, null, 2));
}
export const PASSWORD = 'E2e-Passw0rd-123';

/** Login e-mail of the i-th seeded student (functions don't survive JSON). */
export const student = (st: any, i: number) => `s${i}-${st.code}@e2e.test`;
