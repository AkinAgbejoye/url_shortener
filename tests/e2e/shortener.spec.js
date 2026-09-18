import { expect, test } from '@playwright/test';

test('a visitor can shorten, copy, remember, and follow a URL', async ({ page, context }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { name: /Turn long links into/i })).toBeVisible();

    const destination = 'http://127.0.0.1:8011/api/health';
    await page.getByLabel('Long URL').fill(destination);
    await page.getByRole('button', { name: 'Shorten URL' }).click();

    await expect(page.getByText('Your short link is ready')).toBeVisible();
    const result = page.locator('#short-url-result');
    const shortLink = result.getByRole('link').first();
    await expect(shortLink).toHaveAttribute('href', /^http:\/\/127\.0\.0\.1:8011\/[0-9A-Za-z]+$/);

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
