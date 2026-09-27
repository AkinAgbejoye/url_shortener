import { expect, test } from '@playwright/test';

test('a visitor can shorten, copy, remember, and follow a URL', async ({ page, context }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { name: /Turn long links into/i })).toBeVisible();

    const destination = 'http://127.0.0.1:8011/api/health';
    await page.getByLabel('Long URL').fill(destination);
    const expiration = new Date(Date.now() + 24 * 60 * 60 * 1000);
    const localExpiration = new Date(expiration.getTime() - expiration.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
    await page
        .getByLabel(/Expiration/)
        .first()
        .fill(localExpiration);
    await page.getByRole('button', { name: 'Shorten URL' }).click();

    await expect(page.getByText('Your short link is ready')).toBeVisible();
    const result = page.locator('#short-url-result');
    const shortLink = result.getByRole('link').first();
    await expect(shortLink).toHaveAttribute('href', /^http:\/\/127\.0\.0\.1:8011\/[0-9A-Za-z]+$/);
    await expect(result).toContainText('Active · Expires');

    const storedToken = await page.evaluate(() => {
        const [link] = JSON.parse(localStorage.getItem('shortly.recent-links'));
        return link.management_token;
    });
    expect(storedToken).toMatch(/^[a-f0-9]{64}$/);
    await expect(page.locator('body')).not.toContainText(storedToken);

    await result.getByRole('button', { name: 'Disable link' }).click();
    await expect(result.getByRole('button', { name: 'Enable link' })).toBeVisible();
    await expect(result).toContainText('Disabled');
    await result.getByRole('button', { name: 'Enable link' }).click();
    await expect(result.getByRole('button', { name: 'Disable link' })).toBeVisible();
    await expect(result).toContainText('Active');

    await result.getByRole('button', { name: 'Copy short link' }).click();
    await expect(result.getByRole('button', { name: 'Copied!' })).toBeVisible();

    await expect(page.getByRole('heading', { name: 'Recent links' })).toBeVisible();
    await expect(page.locator('#recent-links-list')).toContainText(destination);

    const newPagePromise = context.waitForEvent('page');
    await result.getByRole('link', { name: 'Open link' }).click();
    const destinationPage = await newPagePromise;
    await destinationPage.waitForLoadState();

    await expect(destinationPage.locator('body')).toContainText('"status":"ok"');
});

test('a visitor can create, manage, and recover from custom alias collisions', async ({ page, context }) => {
    await page.goto('/');

    const destination = 'http://127.0.0.1:8011/api/health';
    const alias = `campaign-${Date.now()}`;
    const retryAlias = `${alias}-retry`;

    await page.getByLabel('Long URL').fill(destination);
    await page.getByLabel('Custom alias').fill(`  ${alias.toUpperCase()}  `);
    await page.getByRole('button', { name: 'Shorten URL' }).click();

    const result = page.locator('#short-url-result');
    await expect(result.getByRole('link', { name: `http://127.0.0.1:8011/${alias}` })).toBeVisible();
    await expect(page.locator('#recent-links-list')).toContainText(`http://127.0.0.1:8011/${alias}`);

    await result.getByRole('button', { name: 'Disable link' }).click();
    await expect(result.getByRole('button', { name: 'Enable link' })).toBeVisible();
    await expect(result).toContainText('Disabled');
    await result.getByRole('button', { name: 'Enable link' }).click();
    await expect(result.getByRole('button', { name: 'Disable link' })).toBeVisible();

    const newPagePromise = context.waitForEvent('page');
    await result.getByRole('link', { name: `http://127.0.0.1:8011/${alias}` }).click();
    const destinationPage = await newPagePromise;
    await destinationPage.waitForLoadState();
    await expect(destinationPage.locator('body')).toContainText('"status":"ok"');

    await page.getByRole('button', { name: 'Shorten another' }).click();
    await page.getByLabel('Long URL').fill('https://example.com/collision-retry');
    await page.getByLabel('Custom alias').fill(alias);
    await page.getByRole('button', { name: 'Shorten URL' }).click();

    await expect(page.locator('#custom-alias-error')).toHaveText('The custom alias has already been taken.');
    await expect(page.getByLabel('Long URL')).toHaveValue('https://example.com/collision-retry');

    await page.getByLabel('Custom alias').fill(retryAlias);
    await page.getByRole('button', { name: 'Shorten URL' }).click();
    await expect(result.getByRole('link', { name: `http://127.0.0.1:8011/${retryAlias}` })).toBeVisible();
});
