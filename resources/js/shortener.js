const form = document.querySelector('#shortener-form');

if (form) {
    const input = form.querySelector('#long-url');
    const button = form.querySelector('#shorten-button');
    const buttonLabel = button.querySelector('[data-button-label]');
    const buttonArrow = button.querySelector('[data-button-arrow]');
    const buttonSpinner = button.querySelector('[data-button-spinner]');
    const inputShell = form.querySelector('[data-input-shell]');
    const inputError = form.querySelector('#long-url-error');
    const result = document.querySelector('#short-url-result');

    const setLoading = (loading) => {
        button.disabled = loading;
        button.setAttribute('aria-busy', String(loading));
        buttonLabel.textContent = loading ? 'Shortening…' : 'Shorten URL';
        buttonArrow.classList.toggle('hidden', loading);
        buttonSpinner.classList.toggle('hidden', !loading);
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

        const temporaryInput = document.createElement('textarea');
        temporaryInput.value = text;
        temporaryInput.setAttribute('readonly', '');
        temporaryInput.className = 'fixed -left-[9999px] top-0';
        document.body.append(temporaryInput);
        temporaryInput.select();

        const copied = document.execCommand('copy');
        temporaryInput.remove();

        if (!copied) {
            throw new Error('The browser rejected the copy command.');
        }
    };

    const showResult = ({ long_url: longUrl, short_url: shortUrl }) => {
        const card = document.createElement('div');
        card.className = 'rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-5 text-left shadow-xl shadow-black/10 sm:p-6';

        const eyebrow = document.createElement('p');
        eyebrow.className = 'text-sm font-semibold text-emerald-300';
        eyebrow.textContent = 'Your short link is ready';

        const link = document.createElement('a');
        link.className = 'mt-2 block break-all text-xl font-semibold text-white underline decoration-emerald-400/50 underline-offset-4 hover:decoration-emerald-300 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-300';
        link.href = shortUrl;
        link.target = '_blank';
        link.rel = 'noreferrer';
        link.textContent = shortUrl;

        const original = document.createElement('p');
        original.className = 'mt-3 truncate text-sm text-slate-400';
        original.title = longUrl;
        original.textContent = `From: ${longUrl}`;

        const actions = document.createElement('div');
        actions.className = 'mt-5 flex flex-col gap-2 sm:flex-row';

        const copyButton = document.createElement('button');
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

        const openLink = document.createElement('a');
        openLink.className = 'inline-flex items-center justify-center rounded-lg border border-white/15 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300';
        openLink.href = shortUrl;
        openLink.target = '_blank';
        openLink.rel = 'noreferrer';
        openLink.textContent = 'Open link';

        const resetButton = document.createElement('button');
        resetButton.type = 'button';
        resetButton.className = 'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300 sm:ml-auto';
        resetButton.textContent = 'Shorten another';
        resetButton.addEventListener('click', () => {
            form.reset();
            clearFieldError();
            result.replaceChildren();
            result.hidden = true;
            input.focus();
        });

        actions.append(copyButton, openLink, resetButton);
        card.append(eyebrow, link, original, actions);
        result.replaceChildren(card);
        result.hidden = false;
        result.focus();
    };

    const showError = (text) => {
        const message = document.createElement('p');
        message.className = 'rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200';
        message.setAttribute('role', 'alert');
        message.textContent = text;
        result.replaceChildren(message);
        result.hidden = false;
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
}
