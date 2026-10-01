import { fireEvent, getByRole, waitFor } from '@testing-library/dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { initApiKeys } from './apiKeys';

const page = () => `
    <meta name="csrf-token" content="csrf-value">
    <section id="api-keys-panel" data-endpoint="/account/api-keys">
        <form id="api-key-form">
            <input id="api-key-name">
            <input id="api-key-expires-at">
            <input id="api-key-password">
            <label><input type="checkbox" name="api_key_scopes[]" value="urls:read" checked> urls:read</label>
            <label><input type="checkbox" name="api_key_scopes[]" value="analytics:read"> analytics:read</label>
            <button id="api-key-submit" type="submit">Create API key</button>
        </form>
        <div id="api-key-created" hidden><input id="api-key-secret"></div>
        <p id="api-key-status"></p><button id="api-key-retry" hidden>Retry</button>
        <p id="api-key-empty" hidden></p><ul id="api-key-list"></ul>
    </section>
`;

const apiKey = (overrides = {}) => ({
    id: 1,
    name: 'Deploy bot',
    public_id: 'ak_public',
    scopes: ['urls:read'],
    status: 'active',
    expires_at: null,
    last_used_at: null,
    revoked_at: null,
    created_at: '2026-10-01T10:00:00Z',
    ...overrides,
});

const listing = (keys = []) => ({
    data: keys,
    meta: { current_page: 1, last_page: 1, per_page: 10, total: keys.length },
});

const response = (body, status = 200) => ({
    ok: status >= 200 && status < 300,
    status,
    json: vi.fn().mockResolvedValue(body),
});

describe('api key account panel', () => {
    beforeEach(() => {
        document.body.innerHTML = page();
        window.prompt = vi.fn().mockReturnValue('password');
        global.fetch = vi.fn().mockResolvedValue(response(listing()));
    });

    it('does nothing outside the account api key panel', () => {
        document.body.innerHTML = '<main>Public page</main>';

        initApiKeys();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('renders metadata without exposing stored hashes or full secrets', async () => {
        fetch.mockResolvedValue(response(listing([apiKey({ secret_hash: 'hidden', plain_text_key: 'secret' })])));

        initApiKeys();

        await waitFor(() => expect(document.body.textContent).toContain('Deploy bot'));
        expect(document.body.textContent).toContain('ak_public');
        expect(document.body.textContent).toContain('active');
        expect(document.querySelector('#api-key-list').innerHTML).not.toContain('hidden');
        expect(document.querySelector('#api-key-list').innerHTML).not.toContain('secret');
        expect(fetch).toHaveBeenCalledWith(
            '/account/api-keys?page=1&per_page=10',
            expect.objectContaining({
                credentials: 'same-origin',
                headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'csrf-value' }),
            }),
        );
    });

    it('creates a key and shows the plain text key exactly in the one-time result', async () => {
        fetch
            .mockResolvedValueOnce(response(listing()))
            .mockResolvedValueOnce(
                response(
                    {
                        api_key: apiKey(),
                        plain_text_key: 'ak_public.secret-value',
                        message: 'Copy this API key now.',
                    },
                    201,
                ),
            )
            .mockResolvedValueOnce(response(listing([apiKey()])));
        initApiKeys();
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));

        document.querySelector('#api-key-name').value = 'Deploy bot';
        document.querySelector('input[value="analytics:read"]').checked = true;
        document.querySelector('#api-key-password').value = 'password';
        fireEvent.submit(document.querySelector('#api-key-form'));

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        expect(fetch.mock.calls[1]).toEqual([
            '/account/api-keys',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({
                    name: 'Deploy bot',
                    scopes: ['urls:read', 'analytics:read'],
                    password: 'password',
                }),
            }),
        ]);
        expect(document.querySelector('#api-key-secret').value).toBe('ak_public.secret-value');
        await waitFor(() => expect(document.activeElement).toBe(document.querySelector('#api-key-secret')));
    });

    it('requires a selected scope and revokes with password confirmation', async () => {
        fetch
            .mockResolvedValueOnce(response(listing([apiKey()])))
            .mockResolvedValueOnce(response({ api_key: apiKey({ status: 'revoked' }) }))
            .mockResolvedValueOnce(response(listing([apiKey({ status: 'revoked' })])));
        initApiKeys();
        await waitFor(() => expect(getByRole(document.body, 'button', { name: 'Revoke key' })).toBeTruthy());

        document.querySelector('input[value="urls:read"]').checked = false;
        fireEvent.submit(document.querySelector('#api-key-form'));
        expect(document.querySelector('#api-key-status').textContent).toBe('Choose at least one API-key scope.');
        expect(fetch).toHaveBeenCalledTimes(1);

        fireEvent.click(getByRole(document.body, 'button', { name: 'Revoke key' }));
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        expect(fetch.mock.calls[1]).toEqual([
            '/account/api-keys/1',
            expect.objectContaining({
                method: 'DELETE',
                body: JSON.stringify({ password: 'password' }),
            }),
        ]);
    });

    it('offers retry after stale sessions and reports create failures', async () => {
        fetch
            .mockResolvedValueOnce(response({ message: 'Unauthenticated.' }, 401))
            .mockResolvedValueOnce(response(listing()))
            .mockResolvedValueOnce(response({ message: 'Validation failed.' }, 422));
        initApiKeys();

        const retry = await waitFor(() => getByRole(document.body, 'button', { name: 'Retry' }));
        expect(document.querySelector('#api-key-status').textContent).toContain('session has expired');
        expect(document.activeElement).toBe(retry);
        fireEvent.click(retry);

        await waitFor(() => expect(document.querySelector('#api-key-status').textContent).toBe('Loaded 0 API keys.'));
        document.querySelector('#api-key-name').value = 'Expiring bot';
        document.querySelector('#api-key-expires-at').value = '2030-01-02T12:30';
        document.querySelector('#api-key-password').value = 'password';
        fireEvent.submit(document.querySelector('#api-key-form'));

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        expect(fetch.mock.calls[2][1].body).toBe(
            JSON.stringify({
                name: 'Expiring bot',
                scopes: ['urls:read'],
                password: 'password',
                expires_at: new Date('2030-01-02T12:30').toISOString(),
            }),
        );
        await waitFor(() => expect(document.querySelector('#api-key-status').textContent).toBe('Validation failed.'));
        expect(document.activeElement).toBe(document.querySelector('#api-key-password'));
    });

    it('keeps cancelled revocation local and restores controls on revoke throttling', async () => {
        window.prompt = vi.fn().mockReturnValueOnce('').mockReturnValueOnce('password');
        fetch
            .mockResolvedValueOnce(
                response(
                    listing([
                        apiKey({
                            expires_at: 'not-a-date',
                            last_used_at: '2026-10-01T10:00:00Z',
                        }),
                    ]),
                ),
            )
            .mockResolvedValueOnce(response({ message: 'Too many requests.' }, 429));
        initApiKeys();

        const revoke = await waitFor(() => getByRole(document.body, 'button', { name: 'Revoke key' }));
        expect(document.body.textContent).toContain('Never');
        expect(document.body.textContent).not.toContain('Not used');

        fireEvent.click(revoke);
        expect(fetch).toHaveBeenCalledTimes(1);

        fireEvent.click(revoke);
        await waitFor(() => expect(revoke.closest('li').textContent).toContain('Too many requests'));
        expect(revoke.disabled).toBe(false);
        expect(document.activeElement).toBe(revoke);
    });

    it('renders revoked keys as inactive controls', async () => {
        fetch.mockResolvedValue(response(listing([apiKey({ status: 'revoked' })])));

        initApiKeys();

        const revoked = await waitFor(() => getByRole(document.body, 'button', { name: 'Revoked' }));
        expect(revoked.disabled).toBe(true);
    });
});
