const json = async (response) => {
    try {
        return await response.json();
    } catch {
        return {};
    }
};

export const initApiKeys = (root = document) => {
    const panel = root.querySelector('#api-keys-panel');

    if (!panel) {
        return;
    }

    const endpoint = panel.dataset.endpoint;
    const csrf = root.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const form = root.querySelector('#api-key-form');
    const name = root.querySelector('#api-key-name');
    const expiresAt = root.querySelector('#api-key-expires-at');
    const password = root.querySelector('#api-key-password');
    const submit = root.querySelector('#api-key-submit');
    const status = root.querySelector('#api-key-status');
    const result = root.querySelector('#api-key-created');
    const secret = root.querySelector('#api-key-secret');
    const list = root.querySelector('#api-key-list');
    const empty = root.querySelector('#api-key-empty');
    const retry = root.querySelector('#api-key-retry');
    const scopes = [...root.querySelectorAll('input[name="api_key_scopes[]"]')];

    const request = async (path = '', options = {}) => {
        const headers = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...(options.headers ?? {}),
        };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(`${endpoint}${path}`, {
            credentials: 'same-origin',
            ...options,
            headers,
            ...(options.body === undefined ? {} : { body: JSON.stringify(options.body) }),
        });
        const payload = response.status === 204 ? null : await json(response);

        if (!response.ok) {
            const error = new Error(payload?.message ?? 'The request could not be completed.');
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    };

    const announce = (message, alert = false) => {
        status.setAttribute('role', alert ? 'alert' : 'status');
        status.textContent = message;
    };

    const formatDate = (value, fallback = 'Never') => {
        if (!value) {
            return fallback;
        }
        const date = new Date(value);

        return Number.isNaN(date.getTime()) ? fallback : date.toLocaleString();
    };

    const selectedScopes = () => scopes.filter((scope) => scope.checked).map((scope) => scope.value);

    const errorMessage = (error) => {
        if (error instanceof TypeError) {
            return 'Unable to reach the service. Check your connection and retry.';
        }
        if (error.status === 401 || error.status === 419) {
            return 'Your session has expired. Log in again, then retry.';
        }
        if (error.status === 429) {
            return 'Too many requests. Wait a moment, then retry.';
        }

        return error.message;
    };

    const renderKey = (apiKey) => {
        const item = root.createElement('li');
        item.className =
            'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.04]';
        item.dataset.apiKeyId = String(apiKey.id);
        const heading = root.createElement('h3');
        heading.className = 'text-lg font-bold';
        heading.tabIndex = -1;
        heading.textContent = apiKey.name;
        const publicId = root.createElement('p');
        publicId.className = 'mt-1 font-mono text-sm text-slate-600 dark:text-slate-300';
        publicId.textContent = apiKey.public_id;
        const details = root.createElement('dl');
        details.className = 'mt-4 grid gap-3 text-sm sm:grid-cols-4';
        [
            ['Status', apiKey.status],
            ['Scopes', (apiKey.scopes ?? []).join(', ')],
            ['Expires', formatDate(apiKey.expires_at)],
            ['Last used', formatDate(apiKey.last_used_at, 'Not used')],
        ].forEach(([term, value]) => {
            const group = root.createElement('div');
            const dt = root.createElement('dt');
            dt.className = 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400';
            dt.textContent = term;
            const dd = root.createElement('dd');
            dd.className = 'mt-1 font-medium';
            dd.textContent = value;
            group.append(dt, dd);
            details.append(group);
        });
        const feedback = root.createElement('p');
        feedback.className = 'mt-3 text-sm text-slate-600 dark:text-slate-300';
        feedback.setAttribute('aria-live', 'polite');
        const revoke = root.createElement('button');
        revoke.type = 'button';
        revoke.textContent = apiKey.status === 'revoked' ? 'Revoked' : 'Revoke key';
        revoke.disabled = apiKey.status === 'revoked';
        revoke.className =
            'mt-4 rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:opacity-60 dark:border-red-300/40 dark:text-red-300';
        revoke.addEventListener('click', async () => {
            const confirmation = root.defaultView.prompt('Enter your password to revoke this API key.');
            if (!confirmation) {
                return;
            }
            revoke.disabled = true;
            feedback.setAttribute('role', 'status');
            feedback.textContent = 'Revoking key...';
            try {
                await request(`/${apiKey.id}`, { method: 'DELETE', body: { password: confirmation } });
                await load('API key revoked.');
            } catch (error) {
                feedback.setAttribute('role', 'alert');
                feedback.textContent = errorMessage(error);
                revoke.disabled = false;
                revoke.focus();
            }
        });
        item.append(heading, publicId, details, revoke, feedback);

        return item;
    };

    const render = (payload) => {
        const keys = Array.isArray(payload.data) ? payload.data : [];
        list.replaceChildren(...keys.map(renderKey));
        empty.hidden = keys.length !== 0;
    };

    const load = async (message = null) => {
        retry.hidden = true;
        panel.setAttribute('aria-busy', 'true');
        announce('Loading API keys...');
        try {
            const payload = await request('?page=1&per_page=10');
            render(payload);
            announce(
                message ??
                    (payload.meta?.total === 1 ? 'Loaded 1 API key.' : `Loaded ${payload.meta?.total ?? 0} API keys.`),
            );
        } catch (error) {
            announce(errorMessage(error), true);
            retry.hidden = false;
            retry.focus();
        } finally {
            panel.setAttribute('aria-busy', 'false');
        }
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = {
            name: name.value.trim(),
            scopes: selectedScopes(),
            password: password.value,
        };
        if (expiresAt.value) {
            body.expires_at = new Date(expiresAt.value).toISOString();
        }
        if (body.scopes.length === 0) {
            announce('Choose at least one API-key scope.', true);
            scopes[0]?.focus();
            return;
        }
        submit.disabled = true;
        announce('Creating API key...');
        try {
            const payload = await request('', { method: 'POST', body });
            secret.value = payload.plain_text_key;
            result.hidden = false;
            form.reset();
            await load('API key created. Copy it now; it will not be shown again.');
            secret.focus();
        } catch (error) {
            announce(errorMessage(error), true);
            password.focus();
        } finally {
            submit.disabled = false;
        }
    });

    retry.addEventListener('click', () => load());
    load();
};
