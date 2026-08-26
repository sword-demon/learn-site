import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright smoke for the learner (web) app.
 *
 * Targets the local stack from `quickstart.md`:
 *   - WEB_PORT defaults to 8080 (compose.yaml)
 *   - API_PORT defaults to 8787
 *
 * The smoke is intentionally minimal: captcha login, catalog, enroll.
 * T107 keeps this single file; expand only when a real user journey
 * needs more coverage.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.WEB_BASE_URL ?? 'http://127.0.0.1:8080',
    trace: 'off',
    headless: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
