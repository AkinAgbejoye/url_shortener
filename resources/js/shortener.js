export const initShortener = (root = document) => {
    const historyKey = 'shortly.recent-links';
    const form = root.querySelector('#shortener-form');

    if (!form) {
        return;
    }

    const input = form.querySelector('#long-url');
    const button = form.querySelector('#shorten-button');
    const buttonLabel = button.querySelector('[data-button-label]');
    const buttonArrow = button.querySelector('[data-button-arrow]');
    const buttonSpinner = button.querySelector('[data-button-spinner]');
    const inputShell = form.querySelector('[data-input-shell]');
    const inputError = form.querySelector('#long-url-error');
    const status = root.querySelector('#shortener-status');
    const result = root.querySelector('#short-url-result');
    const history = root.querySelector('#recent-links');
    const historyList = root.querySelector('#recent-links-list');
    const clearHistoryButton = root.querySelector('#clear-history');
    const storage = root.defaultView?.localStorage;

    const setLoading = (loading) => {
        button.disabled = loading;
        button.setAttribute('aria-busy', String(loading));
        buttonLabel.textContent = loading ? 'Shortening…' : 'Shorten URL';
        buttonArrow.classList.toggle('hidden', loading);
        buttonSpinner.classList.toggle('hidden', !loading);
        form.setAttribute('aria-busy', String(loading));

        if (loading) {
            status.textContent = 'Shortening your URL. Please wait.';
        }
    };

    const clearFieldError = () => {
        input.removeAttribute('aria-invalid');
        inputError.textContent = '';
        inputError.classList.add('hidden');
        inputShell.classList.remove('ring-red-400');
        inputShell.classList.add('ring-slate-200');
    };

    const showFieldError = (message) => {
        input.setAttribute('aria-invalid', 'true');
        inputError.textContent = message;
        inputError.classList.remove('hidden');
        inputShell.classList.remove('ring-slate-200');
        inputShell.classList.add('ring-red-400');
        input.focus();
    };

    const validateUrl = () => {
        const value = input.value.trim();

        if (!value) {
            return 'Enter a URL to shorten.';
        }

        if (value.length > 2048) {
            return 'The URL must be 2,048 characters or fewer.';
        }

        try {
            const url = new URL(value);

            if (!['http:', 'https:'].includes(url.protocol)) {
                return 'Use a URL beginning with http:// or https://.';
            }
        } catch {
            return 'Enter a complete URL, such as https://example.com.';
        }

        return null;
    };

    const copyText = async (text) => {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const temporaryInput = root.createElement('textarea');
        temporaryInput.value = text;
        temporaryInput.setAttribute('readonly', '');
        temporaryInput.className = 'fixed -left-[9999px] top-0';
        root.body.append(temporaryInput);
        temporaryInput.select();

        const copied = document.execCommand('copy');
        temporaryInput.remove();

        if (!copied) {
            throw new Error('The browser rejected the copy command.');
        }
    };

    const readHistory = () => {
        try {
            const saved = JSON.parse(storage?.getItem(historyKey) ?? '[]');

            if (!Array.isArray(saved)) {
                return [];
            }

            return saved.filter((link) => {
                if (typeof link?.long_url !== 'string' || typeof link?.short_url !== 'string' || typeof link?.created_at !== 'string') {
                    return false;
                }

                try {
                    return ['http:', 'https:'].includes(new URL(link.short_url).protocol)
                        && !Number.isNaN(new Date(link.created_at).getTime());
                } catch {
                    return false;
                }
            });
        } catch {
            return [];
        }
    };

    const writeHistory = (links) => {
        try {
            storage?.setItem(historyKey, JSON.stringify(links));
        } catch {
            // Browsers may disable storage in private or restricted contexts.
        }
    };

    const makeHistoryItem = ({ long_url: longUrl, short_url: shortUrl, created_at: createdAt }) => {
        const item = root.createElement('li');
        item.className = 'rounded-xl border border-slate-200 bg-white/70 p-4 sm:flex sm:items-center sm:gap-4 dark:border-white/10 dark:bg-white/[0.04]';

        const details = root.createElement('div');
        details.className = 'min-w-0 flex-1';

        const link = root.createElement('a');
        link.className = 'block truncate font-semibold text-blue-300 hover:text-blue-200 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400';
        link.href = shortUrl;
        link.target = '_blank';
        link.rel = 'noreferrer';
        link.textContent = shortUrl;

        const original = root.createElement('p');
        original.className = 'mt-1 truncate text-sm text-slate-600 dark:text-slate-400';
        original.title = longUrl;
        original.textContent = longUrl;

        const time = root.createElement('p');
        time.className = 'mt-1 text-xs text-slate-500';
        time.textContent = new Date(createdAt).toLocaleString();

        const copyButton = root.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'mt-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400 sm:mt-0 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white';
        copyButton.textContent = 'Copy';
        copyButton.setAttribute('aria-label', `Copy short link ${shortUrl}`);
        copyButton.addEventListener('click', async () => {
            try {
                await copyText(shortUrl);
                copyButton.textContent = 'Copied!';
            } catch (error) {
                console.error(error);
                copyButton.textContent = 'Unable to copy';
            }
        });

        details.append(link, original, time);
        item.append(details, copyButton);

        return item;
    };

    const renderHistory = () => {
        if (!history || !historyList) {
            return;
        }

        const links = readHistory();
        historyList.replaceChildren(...links.map(makeHistoryItem));
        history.hidden = links.length === 0;
    };

    const rememberLink = (link) => {
        const links = readHistory().filter(({ short_url: shortUrl }) => shortUrl !== link.short_url);
        links.unshift({ ...link, created_at: new Date().toISOString() });
        writeHistory(links.slice(0, 5));
        renderHistory();
    };

    const showResult = ({ long_url: longUrl, short_url: shortUrl }) => {
        const card = root.createElement('div');
        card.className = 'rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-5 text-left shadow-xl shadow-black/10 sm:p-6';

        const eyebrow = root.createElement('p');
        eyebrow.className = 'text-sm font-semibold text-emerald-700 dark:text-emerald-300';
        eyebrow.textContent = 'Your short link is ready';

        const link = root.createElement('a');
        link.className = 'mt-2 block break-all text-xl font-semibold text-slate-950 underline decoration-emerald-500/50 underline-offset-4 hover:decoration-emerald-600 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-500 dark:text-white dark:decoration-emerald-400/50 dark:hover:decoration-emerald-300';
        link.href = shortUrl;
        link.target = '_blank';
        link.rel = 'noreferrer';
        link.textContent = shortUrl;

        const original = root.createElement('p');
        original.className = 'mt-3 truncate text-sm text-slate-600 dark:text-slate-400';
        original.title = longUrl;
        original.textContent = `From: ${longUrl}`;

        const actions = root.createElement('div');
        actions.className = 'mt-5 flex flex-col gap-2 sm:flex-row';

        const copyButton = root.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'inline-flex items-center justify-center rounded-lg bg-emerald-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300';
        copyButton.textContent = 'Copy short link';
        copyButton.addEventListener('click', async () => {
            try {
                await copyText(shortUrl);
                copyButton.textContent = 'Copied!';
                window.setTimeout(() => {
                    copyButton.textContent = 'Copy short link';
                }, 2000);
            } catch (error) {
                console.error(error);
                copyButton.textContent = 'Unable to copy';
            }
        });

        const openLink = root.createElement('a');
        openLink.className = 'inline-flex items-center justify-center rounded-lg border border-emerald-700/20 px-4 py-2.5 text-sm font-semibold text-slate-800 transition hover:bg-emerald-400/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:border-white/15 dark:text-white dark:hover:bg-white/10';
        openLink.href = shortUrl;
        openLink.target = '_blank';
        openLink.rel = 'noreferrer';
        openLink.textContent = 'Open link';

        const resetButton = root.createElement('button');
        resetButton.type = 'button';
        resetButton.className = 'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-200 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 sm:ml-auto dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white';
        resetButton.textContent = 'Shorten another';
        resetButton.addEventListener('click', () => {
            form.reset();
            clearFieldError();
            status.textContent = '';
            result.replaceChildren();
            result.hidden = true;
            input.focus();
        });

        actions.append(copyButton, openLink, resetButton);
        card.append(eyebrow, link, original, actions);
        result.replaceChildren(card);
        result.hidden = false;
        status.textContent = 'Short URL created successfully.';
        rememberLink({ long_url: longUrl, short_url: shortUrl });
        result.focus();
    };

    const showError = (text) => {
        const message = root.createElement('p');
        message.className = 'rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200';
        message.setAttribute('role', 'alert');
        message.textContent = text;
        result.replaceChildren(message);
        result.hidden = false;
        result.focus();
    };

    const readJson = async (response) => {
        try {
            return await response.json();
        } catch {
            return {};
        }
    };

    const handleFailedResponse = async (response) => {
        const payload = await readJson(response);

        if (response.status === 422) {
            showFieldError(payload.errors?.long_url?.[0] ?? 'Check the URL and try again.');
            return;
        }

        if (response.status === 429) {
            showError('You have shortened too many links. Wait a minute and try again.');
            return;
        }

        if (response.status === 409) {
            showError(payload.message ?? 'This request conflicts with an earlier request.');
            return;
        }

        showError(response.status >= 500
            ? 'The shortening service is temporarily unavailable. Please try again shortly.'
            : (payload.message ?? 'We could not shorten that URL. Please try again.'));
    };

    input.addEventListener('input', clearFieldError);
    clearHistoryButton?.addEventListener('click', () => {
        try {
            storage?.removeItem(historyKey);
        } catch {
            // The UI can still clear even when storage access is restricted.
        }

        renderHistory();
        status.textContent = 'Recent link history cleared.';
    });

    renderHistory();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        clearFieldError();
        const validationError = validateUrl();

        if (validationError) {
            showFieldError(validationError);
            return;
        }

        setLoading(true);
        result.hidden = true;

        try {
            const response = await fetch(form.dataset.endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ long_url: input.value.trim() }),
            });

            if (!response.ok) {
                await handleFailedResponse(response);
                return;
            }

            showResult(await response.json());
        } catch (error) {
            console.error(error);
            showError('Unable to reach the service. Check your connection and try again.');
        } finally {
            setLoading(false);
        }
    });
};
