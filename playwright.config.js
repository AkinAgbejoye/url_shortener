import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'html' : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8011',
        permissions: ['clipboard-read', 'clipboard-write'],
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command:
            'APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=database/e2e.sqlite CACHE_STORE=array SESSION_DRIVER=file QUEUE_CONNECTION=sync APP_URL=http://127.0.0.1:8011 php artisan serve --host=127.0.0.1 --port=8011',
        url: 'http://127.0.0.1:8011/api/health',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
