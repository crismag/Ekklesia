import { defineConfig, devices } from '@playwright/test';

const port = process.env.PORTAL_E2E_PORT ?? '8765';
const baseURL = process.env.PORTAL_E2E_BASE_URL ?? `http://127.0.0.1:${port}`;

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ['json', { outputFile: 'reports/generated/playwright-results.json' }],
  ],
  timeout: 30_000,
  expect: { timeout: 5_000 },
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: process.env.PORTAL_E2E_SKIP_WEBSERVER
    ? undefined
    : {
        command: `php -S 127.0.0.1:${port} -t public public/index.php`,
        url: `${baseURL}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
        stdout: 'pipe',
        stderr: 'pipe',
      },
});
