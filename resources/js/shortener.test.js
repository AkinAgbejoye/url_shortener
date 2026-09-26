import { fireEvent, getByRole, waitFor } from '@testing-library/dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { initShortener } from './shortener';

const page = () => `
    <form id="shortener-form" data-endpoint="/api/v1/urls">
        <div data-input-shell class="ring-slate-200">
            <input id="long-url" name="long_url">
        </div>
        <p id="long-url-error" class="hidden"></p>
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

const response = (body, status = 201) => ({
    ok: status >= 200 && status < 300,
    status,
    json: vi.fn().mockResolvedValue(body),
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
            response({
                long_url: 'https://example.com/write-failure',
                short_url: 'http://localhost/7',
            }),
        );
        document.querySelector('#long-url').value = 'https://example.com/write-failure';

        fireEvent.submit(document.querySelector('#shortener-form'));

        await waitFor(() => expect(setItem).toHaveBeenCalled());
        expect(
            getByRole(document.querySelector('#short-url-result'), 'link', {
                name: 'http://localhost/7',
            }),
        ).toBeTruthy();
    });
});
