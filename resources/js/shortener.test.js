import { fireEvent, getByRole, waitFor } from '@testing-library/dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { initShortener } from './shortener';

const page = () => `
    <form id="shortener-form" data-endpoint="/api/v1/urls" data-url-prefix="http://localhost/">
        <div data-input-shell class="ring-slate-200">
            <input id="long-url" name="long_url">
        </div>
        <p id="long-url-error" class="hidden"></p>
        <input id="expires-at" name="expires_at" type="datetime-local">
        <p id="expires-at-error" class="hidden"></p>
        <div data-alias-shell class="border-slate-200">
            <span data-url-prefix></span>
            <input id="custom-alias" name="custom_alias">
        </div>
        <p id="custom-alias-error" class="hidden"></p>
        <button id="shorten-button" type="submit">
            <span data-button-label>Shorten URL</span>
            <svg data-button-arrow></svg>
            <svg data-button-spinner class="hidden"></svg>
        </button>
    </form>
    <p id="shortener-status"></p>
    <div id="short-url-result" hidden></div>
    <section id="recent-links" hidden>
        <button id="clear-history" type="button">Clear history</button>
        <ul id="recent-links-list"></ul>
    </section>
`;

const response = (body, status = 201, managementToken = null) => ({
    ok: status >= 200 && status < 300,
    status,
    json: vi.fn().mockResolvedValue(body),
    headers: { get: vi.fn().mockReturnValue(managementToken) },
});

const analyticsPayload = (overrides = {}) => ({
    range: '30d',
    timezone: 'UTC',
    start_date: '2026-09-26',
    end_date: '2026-09-28',
    total_redirects: 5,
    series: [
        { date: '2026-09-26', redirect_count: 2 },
        { date: '2026-09-27', redirect_count: 0 },
        { date: '2026-09-28', redirect_count: 3 },
    ],
    ...overrides,
});

describe('URL shortener form', () => {
    beforeEach(() => {
        document.body.innerHTML = page();
        localStorage.clear();
        global.fetch = vi.fn();
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn().mockResolvedValue(undefined) },
        });
        Object.defineProperty(document, 'execCommand', {
            configurable: true,
            value: vi.fn(),
        });
        initShortener();
    });

    it('submits the URL and renders the returned short link', async () => {
        fetch.mockResolvedValue(
            response({
                long_url: 'https://example.com/a-long-path',
                short_url: 'http://localhost/1',
            }),
        );

        const input = document.querySelector('#long-url');
        input.value = 'https://example.com/a-long-path';
        fireEvent.submit(document.querySelector('#shortener-form'));

        expect(getByRole(document.body, 'button', { name: 'Shortening…' }).disabled).toBe(true);

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#short-url-result'), 'link', { name: 'http://localhost/1' }),
            ).toBeTruthy(),
        );
        expect(fetch).toHaveBeenCalledWith(
            '/api/v1/urls',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ long_url: 'https://example.com/a-long-path' }),
            }),
        );
        expect(getByRole(document.body, 'button', { name: 'Shorten URL' }).disabled).toBe(false);
        expect(document.querySelector('#short-url-result').textContent).not.toContain('Disable link');
    });

    it('normalizes and submits a valid custom alias', async () => {
        fetch.mockResolvedValue(
            response({
                short_code: 'spring-sale',
                long_url: 'https://example.com/campaign',
                short_url: 'http://localhost/spring-sale',
            }),
        );

        document.querySelector('#long-url').value = 'https://example.com/campaign';
        document.querySelector('#custom-alias').value = '  Spring-Sale  ';
        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#short-url-result'), 'link', {
                    name: 'http://localhost/spring-sale',
                }),
            ).toBeTruthy(),
        );
        expect(document.querySelector('#custom-alias').value).toBe('spring-sale');
        expect(fetch).toHaveBeenCalledWith(
            '/api/v1/urls',
            expect.objectContaining({
                body: JSON.stringify({
                    long_url: 'https://example.com/campaign',
                    custom_alias: 'spring-sale',
                }),
            }),
        );
        expect(JSON.parse(localStorage.getItem('shortly.recent-links'))[0].short_code).toBe('spring-sale');
    });

    it('normalizes aliases on blur and rejects invalid aliases before submitting', () => {
        const input = document.querySelector('#custom-alias');
        document.querySelector('#long-url').value = 'https://example.com/campaign';

        input.value = '  Bad--Alias  ';
        fireEvent.blur(input);
        fireEvent.submit(document.querySelector('#shortener-form'));

        expect(input.value).toBe('bad--alias');
        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('#custom-alias-error').textContent).toBe(
            'The custom alias may contain letters, numbers, and single hyphens between groups.',
        );
        expect(document.activeElement).toBe(input);
    });

    it('rejects reserved aliases before submitting', () => {
        document.querySelector('#long-url').value = 'https://example.com/campaign';
        document.querySelector('#custom-alias').value = 'API';

        fireEvent.submit(document.querySelector('#shortener-form'));

        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('#custom-alias-error').textContent).toBe(
            'The custom alias is reserved and cannot be used.',
        );
    });

    it('rejects past expirations before submitting', () => {
        document.querySelector('#long-url').value = 'https://example.com/campaign';
        document.querySelector('#expires-at').value = '2020-01-01T00:00';

        fireEvent.submit(document.querySelector('#shortener-form'));

        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('#expires-at-error').textContent).toBe('Choose an expiration in the future.');
        expect(document.activeElement).toBe(document.querySelector('#expires-at'));
    });

    it('renders expiration validation returned by the API', async () => {
        fetch.mockResolvedValue(
            response(
                {
                    errors: { expires_at: ['The expiration must be before the maximum lifetime.'] },
                },
                422,
            ),
        );
        document.querySelector('#long-url').value = 'https://example.com';
        document.querySelector('#expires-at').value = '2030-01-01T00:00';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(document.querySelector('#expires-at-error').textContent).toBe(
                'The expiration must be before the maximum lifetime.',
            ),
        );
        expect(document.activeElement).toBe(document.querySelector('#expires-at'));
    });

    it('renders alias conflicts beside the alias field and allows retry', async () => {
        fetch
            .mockResolvedValueOnce(
                response(
                    {
                        message: 'The custom alias has already been taken.',
                        errors: { custom_alias: ['The custom alias has already been taken.'] },
                    },
                    409,
                ),
            )
            .mockResolvedValueOnce(
                response({
                    short_code: 'new-campaign',
                    long_url: 'https://example.com/campaign',
                    short_url: 'http://localhost/new-campaign',
                }),
            );
        document.querySelector('#long-url').value = 'https://example.com/campaign';
        document.querySelector('#custom-alias').value = 'campaign';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(document.querySelector('#custom-alias-error').textContent).toBe(
                'The custom alias has already been taken.',
            ),
        );
        expect(document.querySelector('#long-url').value).toBe('https://example.com/campaign');
        expect(document.querySelector('#custom-alias').value).toBe('campaign');

        document.querySelector('#custom-alias').value = 'new-campaign';
        fireEvent.input(document.querySelector('#custom-alias'));
        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#short-url-result'), 'link', {
                    name: 'http://localhost/new-campaign',
                }),
            ).toBeTruthy(),
        );
        expect(fetch).toHaveBeenLastCalledWith(
            '/api/v1/urls',
            expect.objectContaining({
                body: JSON.stringify({
                    long_url: 'https://example.com/campaign',
                    custom_alias: 'new-campaign',
                }),
            }),
        );
    });

    it('shows generic conflict responses when no field error is returned', async () => {
        fetch.mockResolvedValue(response({ message: 'This idempotency key was used for another URL.' }, 409));
        document.querySelector('#long-url').value = 'https://example.com/conflict';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(getByRole(document.querySelector('#short-url-result'), 'alert').textContent).toBe(
                'This idempotency key was used for another URL.',
            ),
        );
    });

    it('shows a rate limit message for too many shortening requests', async () => {
        fetch.mockResolvedValue(response({ message: 'Too many requests.' }, 429));
        document.querySelector('#long-url').value = 'https://example.com/rate-limited';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(getByRole(document.querySelector('#short-url-result'), 'alert').textContent).toBe(
                'You have shortened too many links. Wait a minute and try again.',
            ),
        );
    });

    it('shows a service unavailable message for server errors', async () => {
        fetch.mockResolvedValue(response({}, 503));
        document.querySelector('#long-url').value = 'https://example.com/unavailable';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(getByRole(document.querySelector('#short-url-result'), 'alert').textContent).toBe(
                'The shortening service is temporarily unavailable. Please try again shortly.',
            ),
        );
    });

    it('rejects invalid input before making an API request', () => {
        const input = document.querySelector('#long-url');
        input.value = 'ftp://example.com/file';

        fireEvent.submit(document.querySelector('#shortener-form'));

        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('#long-url-error').textContent).toBe(
            'Use a URL beginning with http:// or https://.',
        );
        expect(input.getAttribute('aria-invalid')).toBe('true');
    });

    it('shows validation details returned by the API', async () => {
        fetch.mockResolvedValue(
            response(
                {
                    errors: { long_url: ['The URL is not allowed.'] },
                },
                422,
            ),
        );
        document.querySelector('#long-url').value = 'https://example.com';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(document.querySelector('#long-url-error').textContent).toBe('The URL is not allowed.'),
        );
        expect(document.activeElement).toBe(document.querySelector('#long-url'));
    });

    it('shows a useful message when the service cannot be reached', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        fetch.mockRejectedValue(new TypeError('Failed to fetch'));
        document.querySelector('#long-url').value = 'https://example.com';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(getByRole(document.body, 'alert').textContent).toContain('Unable to reach the service'),
        );
    });

    it('copies the short link and resets the form', async () => {
        fetch.mockResolvedValue(
            response({
                long_url: 'https://example.com',
                short_url: 'http://localhost/2',
            }),
        );
        const input = document.querySelector('#long-url');
        input.value = 'https://example.com';
        fireEvent.submit(document.querySelector('#shortener-form'));

        const copyButton = await waitFor(() => getByRole(document.body, 'button', { name: 'Copy short link' }));
        fireEvent.click(copyButton);
        await waitFor(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('http://localhost/2'));
        await waitFor(() => expect(copyButton.textContent).toBe('Copied!'));

        fireEvent.click(getByRole(document.body, 'button', { name: 'Shorten another' }));
        expect(input.value).toBe('');
        expect(document.querySelector('#short-url-result').hidden).toBe(true);
        expect(document.activeElement).toBe(input);
    });

    it('persists recent links and clears the history', async () => {
        fetch.mockResolvedValue(
            response({
                long_url: 'https://example.com/persisted',
                short_url: 'http://localhost/3',
            }),
        );
        document.querySelector('#long-url').value = 'https://example.com/persisted';
        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() => expect(document.querySelector('#recent-links').hidden).toBe(false));
        expect(JSON.parse(localStorage.getItem('shortly.recent-links'))).toEqual([
            expect.objectContaining({
                long_url: 'https://example.com/persisted',
                short_url: 'http://localhost/3',
            }),
        ]);
        expect(getByRole(document.querySelector('#recent-links'), 'link', { name: 'http://localhost/3' })).toBeTruthy();

        fireEvent.click(getByRole(document.body, 'button', { name: 'Clear history' }));
        expect(localStorage.getItem('shortly.recent-links')).toBeNull();
        expect(document.querySelector('#recent-links').hidden).toBe(true);
    });

    it('submits expiration in UTC and stores the management token without rendering it', async () => {
        const token = 'a'.repeat(64);
        const localExpiration = '2030-01-02T12:30';
        fetch.mockResolvedValue(
            response(
                {
                    short_code: 'managed',
                    long_url: 'https://example.com/managed',
                    short_url: 'http://localhost/managed',
                    expires_at: new Date(localExpiration).toISOString(),
                    status: 'active',
                },
                201,
                token,
            ),
        );
        document.querySelector('#long-url').value = 'https://example.com/managed';
        document.querySelector('#expires-at').value = localExpiration;

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#short-url-result'), 'button', { name: 'Disable link' }),
            ).toBeTruthy(),
        );
        expect(fetch).toHaveBeenCalledWith(
            '/api/v1/urls',
            expect.objectContaining({
                body: JSON.stringify({
                    long_url: 'https://example.com/managed',
                    expires_at: new Date(localExpiration).toISOString(),
                }),
            }),
        );
        expect(JSON.parse(localStorage.getItem('shortly.recent-links'))[0].management_token).toBe(token);
        expect(document.body.textContent).not.toContain(token);
        expect(document.querySelector('#short-url-result').textContent).toContain('Expires');
    });

    it('loads accessible analytics for managed recent links without exposing the token', async () => {
        const token = 'b'.repeat(64);
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                {
                    short_code: 'managed',
                    long_url: 'https://example.com/managed',
                    short_url: 'http://localhost/managed',
                    created_at: '2026-09-28T10:00:00.000Z',
                    management_token: token,
                    status: 'active',
                },
            ]),
        );
        document.body.innerHTML = page();
        initShortener();
        fetch.mockResolvedValue(response(analyticsPayload(), 200));

        fireEvent.click(getByRole(document.querySelector('#recent-links'), 'button', { name: 'View analytics' }));

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#recent-links'), 'heading', { name: '5 total redirects' }),
            ).toBeTruthy(),
        );
        expect(fetch).toHaveBeenCalledWith(
            '/api/v1/urls/managed/analytics?range=30d',
            expect.objectContaining({
                method: 'GET',
                headers: expect.objectContaining({
                    Accept: 'application/json',
                    'X-Management-Token': token,
                }),
            }),
        );
        expect(fetch.mock.calls[0][0]).not.toContain(token);
        expect(document.body.textContent).not.toContain(token);
        expect(getByRole(document.querySelector('#recent-links'), 'img')).toBeTruthy();
        expect(
            getByRole(document.querySelector('#recent-links'), 'table', { name: 'Daily redirect counts' }),
        ).toBeTruthy();
    });

    it('reloads analytics when the selected range changes', async () => {
        const token = 'c'.repeat(64);
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                {
                    short_code: 'range-test',
                    long_url: 'https://example.com/range',
                    short_url: 'http://localhost/range-test',
                    created_at: '2026-09-28T10:00:00.000Z',
                    management_token: token,
                },
            ]),
        );
        document.body.innerHTML = page();
        initShortener();
        fetch
            .mockResolvedValueOnce(response(analyticsPayload({ total_redirects: 1 }), 200))
            .mockResolvedValueOnce(response(analyticsPayload({ range: '7d', total_redirects: 7 }), 200));

        fireEvent.click(getByRole(document.querySelector('#recent-links'), 'button', { name: 'View analytics' }));
        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#recent-links'), 'heading', { name: '1 total redirect' }),
            ).toBeTruthy(),
        );

        const range = getByRole(document.querySelector('#recent-links'), 'combobox', { name: 'Analytics range' });
        range.value = '7d';
        fireEvent.change(range);

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#recent-links'), 'heading', { name: '7 total redirects' }),
            ).toBeTruthy(),
        );
        expect(fetch).toHaveBeenLastCalledWith(
            '/api/v1/urls/range-test/analytics?range=7d',
            expect.objectContaining({
                headers: expect.objectContaining({ 'X-Management-Token': token }),
            }),
        );
    });

    it('shows an empty analytics state and keeps the recent link visible', async () => {
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                {
                    short_code: 'empty',
                    long_url: 'https://example.com/empty',
                    short_url: 'http://localhost/empty',
                    created_at: '2026-09-28T10:00:00.000Z',
                    management_token: 'd'.repeat(64),
                },
            ]),
        );
        document.body.innerHTML = page();
        initShortener();
        fetch.mockResolvedValue(response(analyticsPayload({ total_redirects: 0 }), 200));

        fireEvent.click(getByRole(document.querySelector('#recent-links'), 'button', { name: 'View analytics' }));

        await waitFor(() =>
            expect(document.querySelector('#recent-links').textContent).toContain(
                'No redirects have been recorded for this range yet.',
            ),
        );
        expect(
            getByRole(document.querySelector('#recent-links'), 'link', { name: 'http://localhost/empty' }),
        ).toBeTruthy();
    });

    it.each([
        [404, 'Analytics are unavailable for this link. The token may be stale or the link may no longer exist.'],
        [429, 'Analytics are rate limited right now. Wait a moment, then retry.'],
    ])('shows a retryable analytics error for %s responses', async (statusCode, message) => {
        const token = 'e'.repeat(64);
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                {
                    short_code: 'retryable',
                    long_url: 'https://example.com/retryable',
                    short_url: 'http://localhost/retryable',
                    created_at: '2026-09-28T10:00:00.000Z',
                    management_token: token,
                },
            ]),
        );
        document.body.innerHTML = page();
        initShortener();
        vi.spyOn(console, 'error').mockImplementation(() => {});
        fetch
            .mockResolvedValueOnce(response({ message }, statusCode))
            .mockResolvedValueOnce(response(analyticsPayload(), 200));

        fireEvent.click(getByRole(document.querySelector('#recent-links'), 'button', { name: 'View analytics' }));

        await waitFor(() =>
            expect(getByRole(document.querySelector('#recent-links'), 'alert').textContent).toBe(message),
        );

        fireEvent.click(getByRole(document.querySelector('#recent-links'), 'button', { name: 'Retry' }));

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#recent-links'), 'heading', { name: '5 total redirects' }),
            ).toBeTruthy(),
        );
        expect(JSON.parse(localStorage.getItem('shortly.recent-links'))[0].short_url).toBe(
            'http://localhost/retryable',
        );
    });

    it('handles analytics network failures, retry, and close focus management', async () => {
        const token = 'f'.repeat(64);
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                {
                    short_code: 'network',
                    long_url: 'https://example.com/network',
                    short_url: 'http://localhost/network',
                    created_at: '2026-09-28T10:00:00.000Z',
                    management_token: token,
                },
            ]),
        );
        document.body.innerHTML = page();
        initShortener();
        vi.spyOn(console, 'error').mockImplementation(() => {});
        fetch
            .mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockResolvedValueOnce(response(analyticsPayload(), 200));

        const analyticsButton = getByRole(document.querySelector('#recent-links'), 'button', {
            name: 'View analytics',
        });
        fireEvent.click(analyticsButton);

        await waitFor(() =>
            expect(getByRole(document.querySelector('#recent-links'), 'alert').textContent).toBe(
                'Unable to reach analytics. Check your connection and retry.',
            ),
        );
        fireEvent.click(getByRole(document.querySelector('#recent-links'), 'button', { name: 'Retry' }));
        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#recent-links'), 'heading', { name: '5 total redirects' }),
            ).toBeTruthy(),
        );

        fireEvent.click(getByRole(document.querySelector('#recent-links'), 'button', { name: 'Close' }));

        expect(analyticsButton.getAttribute('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(analyticsButton);
    });

    it('does not expose analytics actions for links without a valid stored token', () => {
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                {
                    short_code: 'missing-token',
                    long_url: 'https://example.com/missing-token',
                    short_url: 'http://localhost/missing-token',
                    created_at: '2026-09-28T10:00:00.000Z',
                },
                {
                    short_code: 'bad-token',
                    long_url: 'https://example.com/bad-token',
                    short_url: 'http://localhost/bad-token',
                    created_at: '2026-09-28T10:00:00.000Z',
                    management_token: 'not-a-token',
                },
            ]),
        );
        document.body.innerHTML = page();

        expect(() => initShortener()).not.toThrow();
        expect(document.querySelector('#recent-links').textContent).not.toContain('View analytics');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('updates lifecycle state only after a successful management response', async () => {
        const token = 'b'.repeat(64);
        fetch
            .mockResolvedValueOnce(
                response(
                    {
                        short_code: 'managed',
                        long_url: 'https://example.com/managed',
                        short_url: 'http://localhost/managed',
                        expires_at: null,
                        status: 'active',
                    },
                    201,
                    token,
                ),
            )
            .mockResolvedValueOnce(response({ message: 'Service unavailable' }, 503))
            .mockResolvedValueOnce(
                response({
                    short_code: 'managed',
                    long_url: 'https://example.com/managed',
                    short_url: 'http://localhost/managed',
                    expires_at: null,
                    disabled_at: '2026-09-27T10:00:00+00:00',
                    status: 'disabled',
                }),
            );
        vi.spyOn(console, 'error').mockImplementation(() => {});
        document.querySelector('#long-url').value = 'https://example.com/managed';
        fireEvent.submit(document.querySelector('#shortener-form'));

        let disable = await waitFor(() =>
            getByRole(document.querySelector('#short-url-result'), 'button', { name: 'Disable link' }),
        );
        fireEvent.click(disable);
        await waitFor(() => expect(getByRole(document.querySelector('#short-url-result'), 'alert')).toBeTruthy());
        expect(document.querySelector('#short-url-result').textContent).toContain('Active');

        disable = getByRole(document.querySelector('#short-url-result'), 'button', { name: 'Disable link' });
        fireEvent.click(disable);
        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#short-url-result'), 'button', { name: 'Enable link' }),
            ).toBeTruthy(),
        );
        expect(fetch).toHaveBeenLastCalledWith(
            '/api/v1/urls/managed/disable',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'X-Management-Token': token }),
            }),
        );
        expect(document.querySelector('#short-url-result').textContent).toContain('Disabled');
    });

    it('requires confirmation before deleting a managed link', async () => {
        const token = 'c'.repeat(64);
        fetch.mockResolvedValue(
            response(
                {
                    short_code: 'managed',
                    long_url: 'https://example.com/managed',
                    short_url: 'http://localhost/managed',
                    expires_at: null,
                    status: 'active',
                },
                201,
                token,
            ),
        );
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        document.querySelector('#long-url').value = 'https://example.com/managed';
        fireEvent.submit(document.querySelector('#shortener-form'));

        const deleteButton = await waitFor(() =>
            getByRole(document.querySelector('#short-url-result'), 'button', { name: 'Delete link' }),
        );
        fireEvent.click(deleteButton);

        expect(window.confirm).toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(document.querySelector('#short-url-result').hidden).toBe(false);
    });

    it('uses the legacy clipboard fallback and removes its temporary textarea', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: undefined,
        });
        document.execCommand.mockReturnValue(true);
        fetch.mockResolvedValue(
            response({
                long_url: 'https://example.com/fallback',
                short_url: 'http://localhost/4',
            }),
        );
        document.querySelector('#long-url').value = 'https://example.com/fallback';
        fireEvent.submit(document.querySelector('#shortener-form'));

        const copyButton = await waitFor(() => getByRole(document.body, 'button', { name: 'Copy short link' }));
        fireEvent.click(copyButton);

        await waitFor(() => expect(document.execCommand).toHaveBeenCalledWith('copy'));
        expect(document.querySelector('textarea.fixed')).toBeNull();
        expect(copyButton.textContent).toBe('Copied!');
    });

    it.each([
        ['returns false', () => document.execCommand.mockReturnValue(false)],
        [
            'throws an error',
            () =>
                document.execCommand.mockImplementation(() => {
                    throw new Error('Copy is unavailable.');
                }),
        ],
    ])('removes the fallback textarea when execCommand %s', async (_scenario, configureFallback) => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: undefined,
        });
        configureFallback();
        fetch.mockResolvedValue(
            response({
                long_url: 'https://example.com/rejected-copy',
                short_url: 'http://localhost/5',
            }),
        );
        document.querySelector('#long-url').value = 'https://example.com/rejected-copy';
        fireEvent.submit(document.querySelector('#shortener-form'));

        const copyButton = await waitFor(() => getByRole(document.body, 'button', { name: 'Copy short link' }));
        fireEvent.click(copyButton);

        await waitFor(() => expect(copyButton.textContent).toBe('Unable to copy'));
        expect(document.querySelector('textarea.fixed')).toBeNull();
    });

    it('renders only complete HTTP or HTTPS history entries with valid timestamps', () => {
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                {
                    long_url: 'https://example.com/valid',
                    short_url: 'https://sho.rt/valid',
                    created_at: '2026-09-26T10:00:00.000Z',
                },
                {
                    long_url: 'http://example.com/also-valid',
                    short_url: 'http://sho.rt/also-valid',
                    created_at: '2026-09-26T11:00:00.000Z',
                },
                { short_url: 'https://sho.rt/missing-long', created_at: '2026-09-26T10:00:00.000Z' },
                { long_url: 'https://example.com/missing-short', created_at: '2026-09-26T10:00:00.000Z' },
                {
                    long_url: 'https://example.com/unsafe-short',
                    short_url: 'javascript:alert(1)',
                    created_at: '2026-09-26T10:00:00.000Z',
                },
                {
                    long_url: 'ftp://example.com/unsafe-long',
                    short_url: 'https://sho.rt/unsafe-long',
                    created_at: '2026-09-26T10:00:00.000Z',
                },
                {
                    long_url: 'https://example.com/bad-date',
                    short_url: 'https://sho.rt/bad-date',
                    created_at: 'not-a-date',
                },
            ]),
        );
        document.body.innerHTML = page();

        initShortener();

        expect([...document.querySelectorAll('#recent-links-list a')].map((link) => link.textContent)).toEqual([
            'https://sho.rt/valid',
            'http://sho.rt/also-valid',
        ]);
    });

    it.each([
        ['invalid JSON', '{not-json'],
        ['a non-array value', JSON.stringify({ short_url: 'https://sho.rt/not-an-array' })],
    ])('ignores history containing %s', (_scenario, savedHistory) => {
        localStorage.setItem('shortly.recent-links', savedHistory);
        document.body.innerHTML = page();

        expect(() => initShortener()).not.toThrow();
        expect(document.querySelector('#recent-links').hidden).toBe(true);
        expect(document.querySelector('#recent-links-list').children).toHaveLength(0);
    });

    it('remains usable when history reads fail', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('Storage access denied.');
        });
        document.body.innerHTML = page();
        initShortener();
        fetch.mockResolvedValue(
            response({
                long_url: 'https://example.com/read-failure',
                short_url: 'http://localhost/6',
            }),
        );
        document.querySelector('#long-url').value = 'https://example.com/read-failure';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() =>
            expect(
                getByRole(document.querySelector('#short-url-result'), 'link', {
                    name: 'http://localhost/6',
                }),
            ).toBeTruthy(),
        );
    });

    it('remains usable when history writes fail', async () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('Storage quota exceeded.');
        });
        document.body.innerHTML = page();
        initShortener();
        fetch.mockResolvedValue(
            response(
                {
                    short_code: '7',
                    long_url: 'https://example.com/write-failure',
                    short_url: 'http://localhost/7',
                    expires_at: null,
                    status: 'active',
                },
                201,
                'd'.repeat(64),
            ),
        );
        document.querySelector('#long-url').value = 'https://example.com/write-failure';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() => expect(setItem).toHaveBeenCalled());
        expect(
            getByRole(document.querySelector('#short-url-result'), 'link', {
                name: 'http://localhost/7',
            }),
        ).toBeTruthy();
        expect(getByRole(document.querySelector('#short-url-result'), 'button', { name: 'Disable link' })).toBeTruthy();
    });
});
