// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  timeout: 60_000,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: 'https://agencyjnie.pl.ddev.site:4444',
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
