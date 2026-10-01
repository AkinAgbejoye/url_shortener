import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';

const localOrigin = 'http://127.0.0.1:8011';
const applicationKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
const password = 'correct-horse-battery-staple';
const newPassword = 'new-correct-horse-battery-staple';

const accountLink = (purpose, email) =>
    execFileSync('php', ['artisan', 'e2e:account-link', purpose, email], {
        encoding: 'utf8',
        env: {
            ...process.env,
            APP_DEBUG: 'false',
            APP_ENV: 'testing',
            APP_KEY: applicationKey,
            APP_URL: localOrigin,
            CACHE_STORE: 'array',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: 'database/e2e.sqlite',
            MAIL_MAILER: 'array',
            QUEUE_CONNECTION: 'sync',
            SESSION_DRIVER: 'database',
        },
    }).trim();

const blockExternalRequests = async (context) => {
    await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());

        if (['http:', 'https:'].includes(url.protocol) && url.origin !== localOrigin) {
            await route.abort('blockedbyclient');
            return;
        }

        await route.continue();
    });
};

const logSizes = () => {
    const directory = resolve('storage/logs');

    return Object.fromEntries(
        readdirSync(directory)
            .filter((file) => file.endsWith('.log'))
            .map((file) => {
                const path = resolve(directory, file);
                return [path, statSync(path).size];
            }),
    );
};

const appendedLogs = (before) => {
    const directory = resolve('storage/logs');

    return readdirSync(directory)
        .filter((file) => file.endsWith('.log'))
        .map((file) => {
            const path = resolve(directory, file);
            return readFileSync(path)
                .subarray(before[path] ?? 0)
                .toString('utf8');
        })
        .join('\n');
};

test.describe('offline account security journey', () => {
    test.describe.configure({ mode: 'serial' });

    test('registration, ownership, scoped automation, revocation, and recovery stay isolated', async ({
        browser,
        request,
    }) => {
        test.setTimeout(120_000);
        const run = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        const ownerEmail = `owner-${run}@example.test`;
        const otherEmail = `other-${run}@example.test`;
        const destination = `${localOrigin}/api/health`;
        const logsBefore = logSizes();
        const ownerContext = await browser.newContext();
        await blockExternalRequests(ownerContext);
        const page = await ownerContext.newPage();
        const browserRequests = [];
        page.on('request', (browserRequest) => browserRequests.push(browserRequest.url()));

        await page.goto('/');
        await page.getByLabel('Long URL').fill(destination);
        await page.getByRole('button', { name: 'Shorten URL' }).click();
        await expect(page.getByText('Your short link is ready')).toBeVisible();
        const anonymous = await page.evaluate(() => JSON.parse(localStorage.getItem('shortly.recent-links'))[0]);
        expect(anonymous.management_token).toMatch(/^[a-f0-9]{64}$/);

        await page.getByRole('link', { name: 'Create account' }).click();
        await page.getByLabel('Name').fill('Primary Owner');
        await page.getByLabel('Email address').fill(ownerEmail);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByLabel('Confirm password').fill(password);
        await page.getByRole('button', { name: 'Create account' }).click();
        await expect(page.getByText(`Signed in as ${ownerEmail}`)).toBeVisible();

        await page.goto(accountLink('verify', ownerEmail));
        await expect(page.getByLabel('Key name')).toBeVisible();
        await page.getByRole('button', { name: 'Claim link' }).click();
        await expect(page.locator('#dashboard-status')).toHaveText('Recent link claimed.');

        await page.getByRole('button', { name: 'Log out' }).click();
        await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();
        await page.getByRole('link', { name: 'Log in' }).click();
        await page.getByLabel('Email address').fill(ownerEmail);
        await page.getByLabel('Password').fill(password);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page.getByText(`Signed in as ${ownerEmail}`)).toBeVisible();

        const ownedAlias = `owned-${run}`;
        await page.getByLabel('Destination URL').fill(destination);
        await page.getByLabel('Custom alias').fill(ownedAlias);
        await page.getByRole('button', { name: 'Create short link' }).click();
        const ownedItem = page.locator('#owner-links-list').getByRole('listitem').first();
        await expect(ownedItem).toContainText(ownedAlias);

        const redirect = await request.get(`${localOrigin}/${ownedAlias}`, { maxRedirects: 0 });
        expect(redirect.status()).toBe(302);
        await ownedItem.getByRole('button', { name: 'View analytics' }).click();
        await expect(ownedItem.getByRole('heading', { name: '1 total redirect' })).toBeVisible();

        await page.getByLabel('Key name').fill('End-to-end automation');
        for (const scope of ['urls:read', 'urls:write', 'analytics:read']) {
            await page.getByRole('checkbox', { name: new RegExp(`^${scope}`) }).check();
        }
        await page.getByLabel('Current password').fill(password);
        await page.getByRole('button', { name: 'Create API key' }).click();
        const apiKeyInput = page.locator('#api-key-secret');
        await expect(apiKeyInput).toHaveValue(/^ak_[a-z0-9]{20}\.[A-Za-z0-9]{64}$/);
        const apiKey = await apiKeyInput.inputValue();
        expect(apiKey).toMatch(/^ak_[a-z0-9]{20}\.[A-Za-z0-9]{64}$/);

        const apiList = await request.get(`${localOrigin}/api/v1/urls`, {
            headers: { Authorization: `Bearer ${apiKey}` },
        });
        expect(apiList.status()).toBe(200);
        const apiListPayload = await apiList.json();
        expect(apiListPayload.data.map((link) => link.short_code)).toEqual(
            expect.arrayContaining([ownedAlias, anonymous.short_code]),
        );
        const automated = await request.post(`${localOrigin}/api/v1/urls`, {
            headers: { Authorization: `Bearer ${apiKey}` },
            data: { long_url: destination },
        });
        expect(automated.status()).toBe(201);
        expect(automated.headers()['x-management-token']).toBeUndefined();
        const automatedPayload = await automated.json();
        for (const credential of [password, anonymous.management_token, apiKey]) {
            expect(JSON.stringify([apiListPayload, automatedPayload])).not.toContain(credential);
        }

        const otherContext = await browser.newContext();
        await blockExternalRequests(otherContext);
        const otherPage = await otherContext.newPage();
        await otherPage.goto('/register');
        await otherPage.getByLabel('Name').fill('Other Owner');
        await otherPage.getByLabel('Email address').fill(otherEmail);
        await otherPage.getByLabel('Password', { exact: true }).fill(password);
        await otherPage.getByLabel('Confirm password').fill(password);
        await otherPage.getByRole('button', { name: 'Create account' }).click();
        await otherPage.goto(accountLink('verify', otherEmail));
        const otherAlias = `other-${run}`;
        await otherPage.getByLabel('Destination URL').fill(destination);
        await otherPage.getByLabel('Custom alias').fill(otherAlias);
        await otherPage.getByRole('button', { name: 'Create short link' }).click();

        for (const suffix of ['', '/analytics']) {
            const response = await request.get(`${localOrigin}/api/v1/urls/${otherAlias}${suffix}`, {
                headers: { Accept: 'application/json', Authorization: `Bearer ${apiKey}` },
            });
            const missing = await request.get(`${localOrigin}/api/v1/urls/missing-${run}${suffix}`, {
                headers: { Accept: 'application/json', Authorization: `Bearer ${apiKey}` },
            });
            expect(response.status()).toBe(404);
            expect(missing.status()).toBe(404);
            expect(await response.json()).toEqual(await missing.json());
        }
        const unrelatedRedirect = await request.get(`${localOrigin}/${otherAlias}`, { maxRedirects: 0 });
        expect(unrelatedRedirect.status()).toBe(302);
        expect(unrelatedRedirect.headers().location).toBe(destination);
        await otherContext.close();

        page.once('dialog', (dialog) => dialog.accept(password));
        await page.getByRole('button', { name: 'Revoke key' }).click();
        await expect(page.locator('#api-key-status')).toHaveText('API key revoked.');
        await expect(page.locator('#api-key-list')).toContainText('revoked');
        expect(
            (
                await request.get(`${localOrigin}/api/v1/urls`, { headers: { Authorization: `Bearer ${apiKey}` } })
            ).status(),
        ).toBe(401);

        const recoveryContext = await browser.newContext();
        await blockExternalRequests(recoveryContext);
        const recoveryPage = await recoveryContext.newPage();
        await recoveryPage.goto('/forgot-password');
        await recoveryPage.getByLabel('Email address').fill(ownerEmail);
        await recoveryPage.getByRole('button', { name: 'Send reset link' }).click();
        await expect(recoveryPage.getByRole('status')).toContainText('If an account exists');
        await recoveryPage.goto(accountLink('reset', ownerEmail));
        await recoveryPage.getByLabel('New password', { exact: true }).fill(newPassword);
        await recoveryPage.getByLabel('Confirm new password').fill(newPassword);
        await recoveryPage.getByRole('button', { name: 'Reset password' }).click();
        await expect(recoveryPage.getByRole('status')).toContainText('Your password has been reset');

        await page.reload();
        await expect(page).toHaveURL(/\/login$/);
        await page.getByLabel('Email address').fill(ownerEmail);
        await page.getByLabel('Password').fill(newPassword);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page.getByText(`Signed in as ${ownerEmail}`)).toBeVisible();

        const cookies = JSON.stringify(await ownerContext.cookies());
        const storage = await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }));
        const markup = await page.content();
        const requestUrls = browserRequests.join('\n');
        const logs = appendedLogs(logsBefore);
        for (const secret of [password, newPassword, anonymous.management_token, apiKey]) {
            expect(cookies).not.toContain(secret);
            expect(markup).not.toContain(secret);
            expect(requestUrls).not.toContain(secret);
            expect(logs).not.toContain(secret);
        }
        expect(storage).not.toContain(apiKey);
        expect(storage).not.toContain(password);
        expect(storage).not.toContain(newPassword);
        expect(logs).not.toContain(ownerEmail);
        expect(logs).not.toContain(otherEmail);

        await recoveryContext.close();
        await ownerContext.close();
    });
});
