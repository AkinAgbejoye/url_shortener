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
    const expirationInput = form.querySelector('#expires-at');
    const expirationError = form.querySelector('#expires-at-error');
    const status = root.querySelector('#shortener-status');
    const result = root.querySelector('#short-url-result');
    const history = root.querySelector('#recent-links');
    const historyList = root.querySelector('#recent-links-list');
    const clearHistoryButton = root.querySelector('#clear-history');
    let currentResultLink = null;
    const storage = (() => {
        try {
            return root.defaultView?.localStorage;
        } catch {
            return null;
        }
    })();

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
        expirationInput?.removeAttribute('aria-invalid');
        if (expirationError) {
            expirationError.textContent = '';
            expirationError.classList.add('hidden');
        }
    };

    const showExpirationError = (message) => {
        expirationInput?.setAttribute('aria-invalid', 'true');
        if (expirationError) {
            expirationError.textContent = message;
            expirationError.classList.remove('hidden');
        }
        expirationInput?.focus();
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

    const expirationValue = () => {
        if (!expirationInput?.value) {
            return null;
        }

        const expiration = new Date(expirationInput.value);

        return Number.isNaN(expiration.getTime()) ? null : expiration;
    };

    const validateExpiration = () => {
        if (!expirationInput?.value) {
            return null;
        }

        const expiration = expirationValue();

        if (!expiration) {
            return 'Choose a valid expiration date and time.';
        }

        if (expiration.getTime() <= Date.now()) {
            return 'Choose an expiration in the future.';
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

        let copied;

        try {
            copied = document.execCommand('copy');
        } finally {
            temporaryInput.remove();
        }

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
                if (
                    typeof link?.long_url !== 'string' ||
                    typeof link?.short_url !== 'string' ||
                    typeof link?.created_at !== 'string'
                ) {
                    return false;
                }

                try {
                    const urls = [new URL(link.long_url), new URL(link.short_url)];

                    return (
                        urls.every((url) => ['http:', 'https:'].includes(url.protocol)) &&
                        !Number.isNaN(new Date(link.created_at).getTime())
                    );
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

    const formatExpiration = (expiresAt) => {
        if (!expiresAt) {
            return 'No expiration';
        }

        const date = new Date(expiresAt);

        return Number.isNaN(date.getTime()) ? 'No expiration' : `Expires ${date.toLocaleString()}`;
    };

    const toLocalDateTime = (expiresAt) => {
        if (!expiresAt) {
            return '';
        }

        const date = new Date(expiresAt);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    };

    const lifecycleDetails = ({ status: linkStatus = 'active', expires_at: expiresAt }) => {
        const details = root.createElement('p');
        details.className = 'mt-2 text-xs font-medium text-slate-500 dark:text-slate-400';
        details.textContent = `${linkStatus.charAt(0).toUpperCase()}${linkStatus.slice(1)} · ${formatExpiration(expiresAt)}`;

        return details;
    };

    const managementRequest = async (link, method, suffix = '', body = undefined) => {
        const response = await fetch(`/api/v1/urls/${encodeURIComponent(link.short_code)}${suffix}`, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Management-Token': link.management_token,
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });

        if (!response.ok) {
            const payload = await readJson(response);
            throw new Error(payload.message ?? 'The link could not be updated.');
        }

        return response.status === 204 ? null : response.json();
    };

    const managementControls = (link, onUpdated, onDeleted) => {
        if (
            typeof link.short_code !== 'string' ||
            typeof link.management_token !== 'string' ||
            !/^[a-f0-9]{64}$/.test(link.management_token)
        ) {
            return null;
        }

        const panel = root.createElement('div');
        panel.className = 'mt-4 border-t border-slate-200 pt-4 dark:border-white/10';
        panel.setAttribute('aria-label', `Manage short link ${link.short_url}`);

        const feedback = root.createElement('p');
        feedback.className = 'mb-3 text-sm text-slate-600 dark:text-slate-300';
        feedback.setAttribute('role', 'status');
        feedback.setAttribute('aria-live', 'polite');

        const expirationLabel = root.createElement('label');
        expirationLabel.className = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
        expirationLabel.textContent = 'Expiration';

        const expiration = root.createElement('input');
        expiration.type = 'datetime-local';
        expiration.value = toLocalDateTime(link.expires_at);
        expiration.className =
            'mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900';
        expirationLabel.append(expiration);

        const actions = root.createElement('div');
        actions.className = 'mt-3 flex flex-wrap gap-2';
        const buttons = [];
        const actionButton = (label, classes = '') => {
            const action = root.createElement('button');
            action.type = 'button';
            action.textContent = label;
            action.className = `rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400 dark:border-white/10 ${classes}`;
            buttons.push(action);
            actions.append(action);

            return action;
        };
        const setBusy = (busy) => {
            buttons.forEach((action) => {
                action.disabled = busy;
            });
            expiration.disabled = busy;
        };
        const perform = async (operation, successMessage) => {
            setBusy(true);
            feedback.removeAttribute('role');
            feedback.setAttribute('role', 'status');
            feedback.textContent = 'Updating link…';

            try {
                const updated = await operation();
                feedback.textContent = successMessage;
                if (updated) {
                    onUpdated({ ...link, ...updated });
                }
            } catch (error) {
                console.error(error);
                feedback.setAttribute('role', 'alert');
                feedback.textContent = error.message || 'The link could not be updated.';
            } finally {
                setBusy(false);
            }
        };

        const updateButton = actionButton('Update expiration');
        updateButton.addEventListener('click', () => {
            const value = expiration.value ? new Date(expiration.value) : null;
            if (value && (Number.isNaN(value.getTime()) || value.getTime() <= Date.now())) {
                feedback.setAttribute('role', 'alert');
                feedback.textContent = 'Choose an expiration in the future.';
                return;
            }
            perform(
                () => managementRequest(link, 'PATCH', '', { expires_at: value?.toISOString() ?? null }),
                'Expiration updated.',
            );
        });

        const toggleButton = actionButton(link.status === 'disabled' ? 'Enable link' : 'Disable link');
        toggleButton.addEventListener('click', () =>
            perform(
                () => managementRequest(link, 'POST', link.status === 'disabled' ? '/enable' : '/disable'),
                link.status === 'disabled' ? 'Link enabled.' : 'Link disabled.',
            ),
        );

        const deleteButton = actionButton('Delete link', 'text-red-600 dark:text-red-300');
        deleteButton.addEventListener('click', () => {
            if (!window.confirm('Delete this short link? This action cannot be undone.')) {
                return;
            }

            perform(async () => {
                await managementRequest(link, 'DELETE');
                onDeleted(link);
                return null;
            }, 'Link deleted.');
        });

        panel.append(feedback, expirationLabel, actions);

        return panel;
    };

    const makeHistoryItem = (storedLink) => {
        const { long_url: longUrl, short_url: shortUrl, created_at: createdAt } = storedLink;
        const item = root.createElement('li');
        item.className =
            'rounded-xl border border-slate-200 bg-white/70 p-4 sm:flex sm:items-center sm:gap-4 dark:border-white/10 dark:bg-white/[0.04]';

        const details = root.createElement('div');
        details.className = 'min-w-0 flex-1';

        const link = root.createElement('a');
        link.className =
            'block truncate font-semibold text-blue-300 hover:text-blue-200 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400';
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
        copyButton.className =
            'mt-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400 sm:mt-0 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white';
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

        details.append(link, original, lifecycleDetails(storedLink), time);
        item.append(details, copyButton);

        const controls = managementControls(
            storedLink,
            (updated) => applyLifecycleUpdate(updated),
            (deleted) => applyLifecycleDeletion(deleted.short_url),
        );
        if (controls) {
            item.append(controls);
        }

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
        links.unshift({ ...link, created_at: link.created_at ?? new Date().toISOString() });
        writeHistory(links.slice(0, 5));
        renderHistory();
    };

    const replaceRememberedLink = (updated) => {
        const links = readHistory().map((link) =>
            link.short_url === updated.short_url ? { ...link, ...updated } : link,
        );
        writeHistory(links);
        renderHistory();
    };

    const forgetRememberedLink = (shortUrl) => {
        writeHistory(readHistory().filter((link) => link.short_url !== shortUrl));
        renderHistory();
        status.textContent = 'Short link deleted.';
    };

    const applyLifecycleUpdate = (updated) => {
        replaceRememberedLink(updated);
        if (currentResultLink?.short_url === updated.short_url) {
            showResult({ ...currentResultLink, ...updated });
        }
    };

    const applyLifecycleDeletion = (shortUrl) => {
        forgetRememberedLink(shortUrl);
        if (currentResultLink?.short_url === shortUrl) {
            currentResultLink = null;
            result.replaceChildren();
            result.hidden = true;
            input.focus();
        }
    };

    const showResult = (createdLink) => {
        currentResultLink = createdLink;
        const { long_url: longUrl, short_url: shortUrl } = createdLink;
        const card = root.createElement('div');
        card.className =
            'rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-5 text-left shadow-xl shadow-black/10 sm:p-6';

        const eyebrow = root.createElement('p');
        eyebrow.className = 'text-sm font-semibold text-emerald-700 dark:text-emerald-300';
        eyebrow.textContent = 'Your short link is ready';

        const link = root.createElement('a');
        link.className =
            'mt-2 block break-all text-xl font-semibold text-slate-950 underline decoration-emerald-500/50 underline-offset-4 hover:decoration-emerald-600 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-500 dark:text-white dark:decoration-emerald-400/50 dark:hover:decoration-emerald-300';
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
        copyButton.className =
            'inline-flex items-center justify-center rounded-lg bg-emerald-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300';
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
        openLink.className =
            'inline-flex items-center justify-center rounded-lg border border-emerald-700/20 px-4 py-2.5 text-sm font-semibold text-slate-800 transition hover:bg-emerald-400/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 dark:border-white/15 dark:text-white dark:hover:bg-white/10';
        openLink.href = shortUrl;
        openLink.target = '_blank';
        openLink.rel = 'noreferrer';
        openLink.textContent = 'Open link';

        const resetButton = root.createElement('button');
        resetButton.type = 'button';
        resetButton.className =
            'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-200 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 sm:ml-auto dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white';
        resetButton.textContent = 'Shorten another';
        resetButton.addEventListener('click', () => {
            form.reset();
            clearFieldError();
            status.textContent = '';
            result.replaceChildren();
            result.hidden = true;
            currentResultLink = null;
            input.focus();
        });

        actions.append(copyButton, openLink, resetButton);
        card.append(eyebrow, link, original, lifecycleDetails(createdLink), actions);
        const controls = managementControls(
            createdLink,
            (updated) => showResult(updated),
            () => applyLifecycleDeletion(shortUrl),
        );
        if (controls) {
            card.append(controls);
        }
        result.replaceChildren(card);
        result.hidden = false;
        status.textContent = 'Short URL created successfully.';
        rememberLink(createdLink);
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
            if (payload.errors?.expires_at?.[0]) {
                showExpirationError(payload.errors.expires_at[0]);
            } else {
                showFieldError(payload.errors?.long_url?.[0] ?? 'Check the URL and try again.');
            }
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

        showError(
            response.status >= 500
                ? 'The shortening service is temporarily unavailable. Please try again shortly.'
                : (payload.message ?? 'We could not shorten that URL. Please try again.'),
        );
    };

    input.addEventListener('input', clearFieldError);
    expirationInput?.addEventListener('input', clearFieldError);
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
        const expirationValidationError = validateExpiration();

        if (validationError) {
            showFieldError(validationError);
            return;
        }

        if (expirationValidationError) {
            showExpirationError(expirationValidationError);
            return;
        }

        setLoading(true);
        result.hidden = true;

        try {
            const expiresAt = expirationValue();
            const response = await fetch(form.dataset.endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    long_url: input.value.trim(),
                    ...(expiresAt ? { expires_at: expiresAt.toISOString() } : {}),
                }),
            });

            if (!response.ok) {
                await handleFailedResponse(response);
                return;
            }

            showResult({
                ...(await response.json()),
                management_token: response.headers?.get('X-Management-Token') ?? undefined,
            });
        } catch (error) {
            console.error(error);
            showError('Unable to reach the service. Check your connection and try again.');
        } finally {
            setLoading(false);
        }
    });
};
