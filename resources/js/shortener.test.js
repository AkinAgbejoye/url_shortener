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
});
