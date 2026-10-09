import { defineConfig } from '@playwright/test';

// Real stack: Flutter web build + Laravel API + MariaDB + Redis + Reverb + LiveKit SFU (see scripts/e2e-stack.sh).
export default defineConfig({
  testDir: './tests',
  timeout: 180_000,
  expect: { timeout: 15_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
    headless: true,
    locale: 'fa-IR',
    viewport: { width: 1280, height: 800 },
    launchOptions: {
      executablePath: process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
      args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required', '--no-sandbox'],
    },
    permissions: ['microphone', 'camera'],
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
});
