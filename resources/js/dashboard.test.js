import { fireEvent, getByRole, waitFor } from '@testing-library/dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { initDashboard } from './dashboard';

const page = () => `
    <meta name="csrf-token" content="csrf-value">
    <form id="owner-create-form">
        <input id="owner-long-url"><input id="owner-custom-alias"><input id="owner-expires-at">
        <p id="owner-create-error"></p><button type="submit">Create short link</button>
    </form>
    <section id="claim-links" hidden>
        <form id="claim-link-form"><select id="claim-link-select"></select><button id="claim-link-button">Claim link</button></form>
        <p id="claim-message"></p>
    </section>
    <section id="owner-dashboard" data-endpoint="/account/urls">
        <h2 id="links-heading" tabindex="-1">Owned links</h2>
        <p id="dashboard-status"></p><button id="dashboard-retry" hidden>Retry</button>
        <p id="owner-links-empty" hidden></p><ul id="owner-links-list"></ul>
        <nav id="dashboard-pagination" hidden>
            <button id="dashboard-previous">Previous</button><span id="dashboard-page-label"></span><button id="dashboard-next">Next</button>
        </nav>
    </section>
`;

const link = (overrides = {}) => ({
    id: 1,
    short_code: 'owned-link',
    short_url: 'http://localhost/owned-link',
    long_url: 'https://example.com/owned',
    expires_at: null,
    disabled_at: null,
    status: 'active',
    origin: 'generated',
    total_redirects: 4,
    created_at: '2026-10-01T10:00:00Z',
    ...overrides,
});

const listing = (links = [], overrides = {}) => ({
    data: links,
    meta: { current_page: 1, last_page: 1, per_page: 10, total: links.length, ...overrides },
});

const response = (body, status = 200) => ({
    ok: status >= 200 && status < 300,
    status,
    json: vi.fn().mockResolvedValue(body),
});

describe('owner link dashboard', () => {
    beforeEach(() => {
        document.body.innerHTML = page();
        localStorage.clear();
        global.fetch = vi.fn().mockResolvedValue(response(listing()));
        window.confirm = vi.fn().mockReturnValue(true);
    });

    it('does nothing outside the dashboard page', () => {
        document.body.innerHTML = '<main>Public page</main>';

        initDashboard();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('renders lifecycle, origin, expiration, and aggregate analytics without credentials', async () => {
        const credential = 'a'.repeat(64);
        fetch.mockResolvedValue(response(listing([link({ management_token: credential })])));
        initDashboard();

        await waitFor(() =>
            expect(getByRole(document.body, 'link', { name: 'http://localhost/owned-link' })).toBeTruthy(),
        );
        expect(document.body.textContent).toContain('active');
        expect(document.body.textContent).toContain('No expiration');
        expect(document.body.textContent).toContain('generated alias');
        expect(document.body.textContent).toContain('4');
        expect(document.body.innerHTML).not.toContain(credential);
        expect(fetch).toHaveBeenCalledWith(
            '/account/urls?page=1&per_page=10',
            expect.objectContaining({
                credentials: 'same-origin',
                headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'csrf-value' }),
            }),
        );
    });

    it('uses bounded page controls and announces page changes', async () => {
        fetch
            .mockResolvedValueOnce(response(listing([link()], { current_page: 1, last_page: 2, total: 11 })))
            .mockResolvedValueOnce(
                response(
                    listing([link({ short_code: 'page-two', short_url: 'http://localhost/page-two' })], {
                        current_page: 2,
                        last_page: 2,
                        total: 11,
                    }),
                ),
            );
        initDashboard();

        const next = await waitFor(() => getByRole(document.body, 'button', { name: 'Next' }));
        await waitFor(() => expect(next.disabled).toBe(false));
        fireEvent.click(next);

        await waitFor(() =>
            expect(getByRole(document.body, 'link', { name: 'http://localhost/page-two' })).toBeTruthy(),
        );
        expect(fetch).toHaveBeenLastCalledWith('/account/urls?page=2&per_page=10', expect.anything());
        expect(document.querySelector('#dashboard-page-label').textContent).toBe('Page 2 of 2');
    });

    it('ignores malformed history and sends claim credentials only in a protected header', async () => {
        const token = 'b'.repeat(64);
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                { short_code: 'recent', short_url: 'http://localhost/recent', management_token: token },
                { short_code: '../bad', short_url: 'javascript:alert(1)', management_token: token },
                { short_code: 'missing-token', short_url: 'http://localhost/missing-token' },
            ]),
        );
        fetch
            .mockResolvedValueOnce(response(listing()))
            .mockResolvedValueOnce(response(link({ short_code: 'recent' })))
            .mockResolvedValueOnce(
                response(listing([link({ short_code: 'recent', short_url: 'http://localhost/recent' })])),
            );
        initDashboard();

        await waitFor(() => expect(document.querySelector('#claim-links').hidden).toBe(false));
        expect(document.querySelectorAll('#claim-link-select option')).toHaveLength(1);
        expect(document.body.innerHTML).not.toContain(token);
        fireEvent.submit(document.querySelector('#claim-link-form'));

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        const [claimUrl, claimOptions] = fetch.mock.calls[1];
        expect(claimUrl).toBe('/account/urls/recent/claim');
        expect(claimUrl).not.toContain(token);
        expect(claimOptions.body).toBeUndefined();
        expect(claimOptions.headers['X-Management-Token']).toBe(token);
        expect(document.body.innerHTML).not.toContain(token);
        expect(JSON.parse(localStorage.getItem('shortly.recent-links'))).toHaveLength(2);
    });

    it('supports lifecycle actions, analytics retry, and focus management', async () => {
        fetch
            .mockResolvedValueOnce(response(listing([link()])))
            .mockResolvedValueOnce(response(link({ status: 'disabled' })))
            .mockResolvedValueOnce(response(listing([link({ status: 'disabled' })])))
            .mockResolvedValueOnce(response({ message: 'Unavailable' }, 503))
            .mockResolvedValueOnce(response({ total_redirects: 4, start_date: '2026-09-02', end_date: '2026-10-01' }));
        initDashboard();

        const disable = await waitFor(() => getByRole(document.body, 'button', { name: 'Disable link' }));
        fireEvent.click(disable);
        await waitFor(() => expect(getByRole(document.body, 'button', { name: 'Enable link' })).toBeTruthy());
        expect(document.activeElement.closest('li')?.dataset.shortCode).toBe('owned-link');

        fireEvent.click(getByRole(document.body, 'button', { name: 'View analytics' }));
        const retry = await waitFor(() => getByRole(document.body, 'button', { name: 'Retry' }));
        expect(document.activeElement).toBe(retry);
        fireEvent.click(retry);
        await waitFor(() => expect(document.activeElement.textContent).toContain('4 total redirects'));
    });

    it('focuses validation errors and offers retry for a stale session', async () => {
        fetch.mockResolvedValue(response({ message: 'Unauthenticated.' }, 401));
        initDashboard();

        const retry = await waitFor(() => getByRole(document.body, 'button', { name: 'Retry' }));
        expect(document.querySelector('#dashboard-status').textContent).toContain('session has expired');
        expect(document.activeElement).toBe(retry);

        document.querySelector('#owner-long-url').value = 'ftp://example.com/file';
        fireEvent.submit(document.querySelector('#owner-create-form'));
        expect(document.querySelector('#owner-create-error').textContent).toBe('Enter a complete HTTP or HTTPS URL.');
        expect(document.activeElement).toBe(document.querySelector('#owner-long-url'));
    });

    it('creates a custom expiring link and focuses the new result', async () => {
        const created = link({
            short_code: 'launch',
            short_url: 'http://localhost/launch',
            origin: 'custom',
            expires_at: '2030-01-02T12:30:00Z',
        });
        fetch
            .mockResolvedValueOnce(response(listing()))
            .mockResolvedValueOnce(response(created, 201))
            .mockResolvedValueOnce(response(listing([created])));
        initDashboard();
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

        document.querySelector('#owner-long-url').value = ' https://example.com/launch ';
        document.querySelector('#owner-custom-alias').value = ' Launch ';
        document.querySelector('#owner-expires-at').value = '2030-01-02T12:30';
        fireEvent.submit(document.querySelector('#owner-create-form'));

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        expect(fetch.mock.calls[1]).toEqual([
            '/account/urls',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({
                    long_url: 'https://example.com/launch',
                    custom_alias: 'launch',
                    expires_at: new Date('2030-01-02T12:30').toISOString(),
                }),
            }),
        ]);
        await waitFor(() => expect(document.activeElement.closest('li')?.dataset.shortCode).toBe('launch'));
        expect(document.body.textContent).toContain('custom alias');
    });

    it('validates expiration and supports update and confirmed deletion', async () => {
        fetch
            .mockResolvedValueOnce(response(listing([link()])))
            .mockResolvedValueOnce(response(link({ expires_at: '2030-01-02T12:30:00Z' })))
            .mockResolvedValueOnce(response(listing([link({ expires_at: '2030-01-02T12:30:00Z' })])))
            .mockResolvedValueOnce({ ok: true, status: 204, json: vi.fn() })
            .mockResolvedValueOnce(response(listing()));
        initDashboard();

        const update = await waitFor(() => getByRole(document.body, 'button', { name: 'Update expiration' }));
        const expiration = update.closest('li').querySelector('input[type="datetime-local"]');
        expiration.value = '2020-01-01T00:00';
        fireEvent.click(update);
        expect(document.activeElement).toBe(expiration);
        expect(update.closest('li').textContent).toContain('Choose an expiration in the future.');
        expect(fetch).toHaveBeenCalledTimes(1);

        expiration.value = '2030-01-02T12:30';
        fireEvent.click(update);
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        expect(fetch.mock.calls[1][0]).toBe('/account/urls/owned-link');
        expect(fetch.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'PATCH' }));

        window.confirm.mockReturnValueOnce(false);
        fireEvent.click(getByRole(document.body, 'button', { name: 'Delete link' }));
        expect(fetch).toHaveBeenCalledTimes(3);
        window.confirm.mockReturnValueOnce(true);
        fireEvent.click(getByRole(document.body, 'button', { name: 'Delete link' }));
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(5));
        expect(fetch.mock.calls[3][1]).toEqual(expect.objectContaining({ method: 'DELETE' }));
        await waitFor(() => expect(document.querySelector('#owner-links-empty').hidden).toBe(false));
        expect(document.querySelector('#dashboard-status').textContent).toBe('Link deleted.');
        expect(document.activeElement).toBe(document.querySelector('#links-heading'));
    });

    it('handles malformed storage and verification failures without exposing claim tokens', async () => {
        localStorage.setItem('shortly.recent-links', '{bad json');
        initDashboard();
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(document.querySelector('#claim-links').hidden).toBe(true);

        document.body.innerHTML = page();
        const token = 'c'.repeat(64);
        localStorage.setItem(
            'shortly.recent-links',
            JSON.stringify([
                { short_code: 'recent', short_url: 'http://localhost/recent', management_token: token },
                { short_code: 'broken-url', short_url: '::::', management_token: token },
            ]),
        );
        global.fetch = vi
            .fn()
            .mockResolvedValueOnce(response(listing()))
            .mockResolvedValueOnce(response({ message: 'Forbidden' }, 403));
        initDashboard();
        await waitFor(() => expect(document.querySelectorAll('#claim-link-select option')).toHaveLength(1));
        fireEvent.submit(document.querySelector('#claim-link-form'));

        await waitFor(() =>
            expect(document.querySelector('#claim-message').textContent).toBe(
                'Verify your email before claiming a link.',
            ),
        );
        expect(document.activeElement).toBe(document.querySelector('#claim-link-button'));
        expect(document.body.innerHTML).not.toContain(token);
        expect(localStorage.getItem('shortly.recent-links')).toContain(token);
    });
});
