import { expect, test } from '@playwright/test';

const localOrigin = 'http://127.0.0.1:8011';

test.beforeEach(async ({ context }) => {
    await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());

        if (['http:', 'https:'].includes(url.protocol) && url.origin !== localOrigin) {
            await route.abort('blockedbyclient');
            return;
        }

        await route.continue();
    });
});

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

test('an owner can verify private analytics from local redirects through recovery states', async ({
    page,
    request,
}) => {
    const destination = `${localOrigin}/api/health`;
    const visitorValues = ['203.0.113.77', 'analytics-e2e-private-agent', 'https://referrer.invalid/private-campaign'];
    const browserRequestUrls = [];
    page.on('request', (browserRequest) => browserRequestUrls.push(browserRequest.url()));

    await page.goto('/');
    await page.getByLabel('Long URL').fill(destination);
    await page.getByRole('button', { name: 'Shorten URL' }).click();

    const result = page.locator('#short-url-result');
    const recentLinks = page.locator('#recent-links-list');
    const shortLink = result.getByRole('link').first();
    const shortUrl = await shortLink.getAttribute('href');
    const managedLink = await page.evaluate(() => JSON.parse(localStorage.getItem('shortly.recent-links'))[0]);
    const managementToken = managedLink.management_token;

    expect(shortUrl).toMatch(/^http:\/\/127\.0\.0\.1:8011\/[0-9A-Za-z]+$/);
    expect(managementToken).toMatch(/^[a-f0-9]{64}$/);

    const managedItem = recentLinks.getByRole('listitem').first();
    await managedItem.getByRole('button', { name: 'View analytics' }).click();
    await expect(managedItem.getByText('No redirects have been recorded for this range yet.')).toBeVisible();
    await managedItem.getByRole('button', { name: 'Close' }).click();

    for (let redirect = 0; redirect < 2; redirect += 1) {
        const response = await request.get(shortUrl, {
            headers: {
                Referer: visitorValues[2],
                'User-Agent': visitorValues[1],
                'X-Forwarded-For': visitorValues[0],
            },
            maxRedirects: 0,
        });

        expect(response.status()).toBe(302);
        expect(response.headers().location).toBe(destination);
    }

    const analyticsResponsePromise = page.waitForResponse(
        (response) => response.url().includes('/analytics?range=30d') && response.status() === 200,
    );
    await managedItem.getByRole('button', { name: 'View analytics' }).click();
    const analyticsResponse = await analyticsResponsePromise;
    const analyticsPayload = await analyticsResponse.json();

    await expect(recentLinks.getByRole('heading', { name: '2 total redirects' })).toBeVisible();
    await expect(recentLinks.getByRole('table', { name: 'Daily redirect counts' })).toBeVisible();

    const serializedPayload = JSON.stringify(analyticsPayload);
    for (const forbidden of [managementToken, destination, ...visitorValues]) {
        expect(serializedPayload).not.toContain(forbidden);
    }
    for (const forbiddenField of ['url_id', 'short_code', 'long_url', 'management_token']) {
        expect(serializedPayload).not.toContain(`"${forbiddenField}"`);
    }

    const sevenDayResponsePromise = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname.endsWith('/analytics') && url.searchParams.get('range') === '7d';
    });
    await recentLinks.getByRole('combobox', { name: 'Analytics range' }).selectOption('7d');
    expect((await sevenDayResponsePromise).status()).toBe(200);
    await expect(recentLinks.getByRole('heading', { name: '2 total redirects' })).toBeVisible();

    await page.evaluate(() => {
        const links = JSON.parse(localStorage.getItem('shortly.recent-links'));
        links[0].management_token = '0'.repeat(64);
        localStorage.setItem('shortly.recent-links', JSON.stringify(links));
    });
    await page.reload();
    await managedItem.getByRole('button', { name: 'View analytics' }).click();
    await expect(managedItem.getByRole('alert')).toHaveText(
        'Analytics are unavailable for this link. The token may be stale or the link may no longer exist.',
    );

    await page.evaluate((token) => {
        const links = JSON.parse(localStorage.getItem('shortly.recent-links'));
        links[0].management_token = token;
        localStorage.setItem('shortly.recent-links', JSON.stringify(links));
    }, managementToken);
    await page.reload();
    await managedItem.getByRole('button', { name: 'View analytics' }).click();
    await expect(managedItem.getByRole('heading', { name: '2 total redirects' })).toBeVisible();

    const markup = await page.content();
    expect(markup).not.toContain(managementToken);
    for (const visitorValue of visitorValues) {
        expect(markup).not.toContain(visitorValue);
        expect(page.url()).not.toContain(visitorValue);
    }
    expect(page.url()).not.toContain(managementToken);
    expect(browserRequestUrls.every((url) => !url.includes(managementToken))).toBe(true);
});
