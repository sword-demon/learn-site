import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright smoke for the admin app. Mirrors web/playwright.config.ts.
 *   - ADMIN_PORT defaults to 8081
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.ADMIN_BASE_URL ?? 'http://127.0.0.1:8081',
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
