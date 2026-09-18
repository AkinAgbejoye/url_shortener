const form = document.querySelector('#shortener-form');

if (form) {
    const input = form.querySelector('#long-url');
    const button = form.querySelector('#shorten-button');
    const buttonLabel = button.querySelector('[data-button-label]');
    const buttonArrow = button.querySelector('[data-button-arrow]');
    const buttonSpinner = button.querySelector('[data-button-spinner]');
    const result = document.querySelector('#short-url-result');

    const setLoading = (loading) => {
        button.disabled = loading;
        button.setAttribute('aria-busy', String(loading));
        buttonLabel.textContent = loading ? 'Shortening…' : 'Shorten URL';
        buttonArrow.classList.toggle('hidden', loading);
        buttonSpinner.classList.toggle('hidden', !loading);
    };

    const showResult = ({ long_url: longUrl, short_url: shortUrl }) => {
        const card = document.createElement('div');
        card.className = 'rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-5 text-left';

        const eyebrow = document.createElement('p');
        eyebrow.className = 'text-sm font-semibold text-emerald-300';
        eyebrow.textContent = 'Your short link is ready';

        const link = document.createElement('a');
        link.className = 'mt-2 block break-all text-lg font-semibold text-white underline decoration-emerald-400/50 underline-offset-4 hover:decoration-emerald-300';
        link.href = shortUrl;
        link.target = '_blank';
        link.rel = 'noreferrer';
        link.textContent = shortUrl;

        const original = document.createElement('p');
        original.className = 'mt-3 truncate text-sm text-slate-400';
        original.title = longUrl;
        original.textContent = `From: ${longUrl}`;

        card.append(eyebrow, link, original);
        result.replaceChildren(card);
        result.hidden = false;
    };

    const showError = () => {
        const message = document.createElement('p');
        message.className = 'rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200';
        message.textContent = 'We could not shorten that URL. Please try again.';
        result.replaceChildren(message);
        result.hidden = false;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!form.reportValidity()) {
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
                throw new Error(`Shortening failed with status ${response.status}`);
            }

            showResult(await response.json());
        } catch (error) {
            console.error(error);
            showError();
        } finally {
            setLoading(false);
        }
    });
}
