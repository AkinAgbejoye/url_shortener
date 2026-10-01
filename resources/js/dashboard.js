const historyKey = 'shortly.recent-links';

const json = async (response) => {
    try {
        return await response.json();
    } catch {
        return {};
    }
};

export const initDashboard = (root = document) => {
    const dashboard = root.querySelector('#owner-dashboard');

    if (!dashboard) {
        return;
    }

    const endpoint = dashboard.dataset.endpoint;
    const csrf = root.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const createForm = root.querySelector('#owner-create-form');
    const createUrl = root.querySelector('#owner-long-url');
    const createAlias = root.querySelector('#owner-custom-alias');
    const createExpiration = root.querySelector('#owner-expires-at');
    const createError = root.querySelector('#owner-create-error');
    const linksHeading = root.querySelector('#links-heading');
    const list = root.querySelector('#owner-links-list');
    const empty = root.querySelector('#owner-links-empty');
    const status = root.querySelector('#dashboard-status');
    const retry = root.querySelector('#dashboard-retry');
    const pagination = root.querySelector('#dashboard-pagination');
    const pageLabel = root.querySelector('#dashboard-page-label');
    const previous = root.querySelector('#dashboard-previous');
    const next = root.querySelector('#dashboard-next');
    const claimSection = root.querySelector('#claim-links');
    const claimForm = root.querySelector('#claim-link-form');
    const claimSelect = root.querySelector('#claim-link-select');
    const claimButton = root.querySelector('#claim-link-button');
    const claimMessage = root.querySelector('#claim-message');
    let currentPage = 1;
    let lastPage = 1;
    let pendingFocusCode = null;

    const announce = (message, alert = false) => {
        status.setAttribute('role', alert ? 'alert' : 'status');
        status.textContent = message;
    };

    const failureMessage = (response, payload, fallback) => {
        if ([401, 419].includes(response.status)) {
            return 'Your session has expired. Log in again, then retry.';
        }
        if (response.status === 429) {
            return 'Too many requests. Wait a moment, then retry.';
        }
        if (response.status >= 500) {
            return 'The service is temporarily unavailable. Please retry.';
        }

        return payload.message ?? fallback;
    };

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
            const error = new Error(failureMessage(response, payload ?? {}, 'The request could not be completed.'));
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    };

    const setBusy = (busy) => {
        dashboard.setAttribute('aria-busy', String(busy));
        retry.disabled = busy;
        previous.disabled = busy || currentPage <= 1;
        next.disabled = busy || currentPage >= lastPage;
    };

    const formatDate = (value, fallback = 'No expiration') => {
        if (!value) {
            return fallback;
        }
        const date = new Date(value);

        return Number.isNaN(date.getTime()) ? fallback : date.toLocaleString();
    };

    const toLocalDateTime = (value) => {
        if (!value) {
            return '';
        }
        const date = new Date(value);

        return Number.isNaN(date.getTime())
            ? ''
            : new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    };

    const button = (label, className = '') => {
        const element = root.createElement('button');
        element.type = 'button';
        element.textContent = label;
        element.className = `rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold transition hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:opacity-60 dark:border-white/15 dark:hover:bg-white/10 ${className}`;

        return element;
    };

    const analyticsPanel = (link) => {
        const wrap = root.createElement('div');
        wrap.className = 'mt-4';
        const toggle = button('View analytics');
        toggle.setAttribute('aria-expanded', 'false');
        const panel = root.createElement('section');
        panel.hidden = true;
        panel.className = 'mt-3 rounded-xl bg-slate-50 p-4 dark:bg-white/[0.04]';
        panel.setAttribute('aria-label', `Analytics for ${link.short_url}`);
        const heading = root.createElement('h4');
        heading.className = 'font-semibold';
        heading.tabIndex = -1;
        const message = root.createElement('p');
        message.className = 'mt-2 text-sm text-slate-600 dark:text-slate-300';
        message.setAttribute('aria-live', 'polite');
        const controls = root.createElement('div');
        controls.className = 'mt-3 flex flex-wrap gap-2';
        const ranges = root.createElement('select');
        ranges.setAttribute('aria-label', 'Analytics range');
        ranges.className =
            'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-white/15 dark:bg-slate-950';
        [
            ['7d', '7 days'],
            ['30d', '30 days'],
            ['90d', '90 days'],
        ].forEach(([value, label]) => {
            ranges.add(new Option(label, value));
        });
        ranges.value = '30d';
        const retryAnalytics = button('Retry');
        retryAnalytics.hidden = true;
        const close = button('Close');
        controls.append(ranges, retryAnalytics, close);
        panel.append(heading, message, controls);

        const load = async () => {
            panel.hidden = false;
            panel.setAttribute('aria-busy', 'true');
            toggle.setAttribute('aria-expanded', 'true');
            toggle.disabled = true;
            ranges.disabled = true;
            retryAnalytics.hidden = true;
            message.setAttribute('role', 'status');
            message.textContent = 'Loading analytics…';
            try {
                const data = await request(`/${encodeURIComponent(link.short_code)}/analytics?range=${ranges.value}`);
                const total = Number(data.total_redirects) || 0;
                heading.textContent = `${total.toLocaleString()} total ${total === 1 ? 'redirect' : 'redirects'}`;
                message.textContent = `Showing ${ranges.options[ranges.selectedIndex].text}, ${formatDate(data.start_date, data.start_date)} through ${formatDate(data.end_date, data.end_date)}.`;
                heading.focus();
            } catch (error) {
                message.setAttribute('role', 'alert');
                message.textContent =
                    error instanceof TypeError
                        ? 'Unable to reach the service. Check your connection and retry.'
                        : error.message;
                retryAnalytics.hidden = false;
                retryAnalytics.focus();
            } finally {
                panel.setAttribute('aria-busy', 'false');
                toggle.disabled = false;
                ranges.disabled = false;
            }
        };

        toggle.addEventListener('click', load);
        ranges.addEventListener('change', load);
        retryAnalytics.addEventListener('click', load);
        close.addEventListener('click', () => {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        });
        wrap.append(toggle, panel);

        return wrap;
    };

    const renderLink = (link) => {
        const item = root.createElement('li');
        item.className =
            'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.04]';
        item.dataset.shortCode = link.short_code;
        const heading = root.createElement('h3');
        heading.className = 'truncate text-lg font-bold';
        heading.tabIndex = -1;
        const shortLink = root.createElement('a');
        shortLink.href = link.short_url;
        shortLink.textContent = link.short_url;
        shortLink.className =
            'text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:text-blue-300';
        heading.append(shortLink);
        const destination = root.createElement('p');
        destination.className = 'mt-1 truncate text-sm text-slate-600 dark:text-slate-300';
        destination.textContent = link.long_url;
        destination.title = link.long_url;
        const details = root.createElement('dl');
        details.className = 'mt-4 grid gap-3 text-sm sm:grid-cols-4';
        [
            ['Status', link.status],
            ['Expiration', formatDate(link.expires_at)],
            ['Origin', `${link.origin} alias`],
            ['Redirects', String(Number(link.total_redirects) || 0)],
        ].forEach(([term, value]) => {
            const group = root.createElement('div');
            const dt = root.createElement('dt');
            dt.className = 'text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400';
            dt.textContent = term;
            const dd = root.createElement('dd');
            dd.className = 'mt-1 font-medium capitalize';
            dd.textContent = value;
            group.append(dt, dd);
            details.append(group);
        });
        const feedback = root.createElement('p');
        feedback.className = 'mt-4 text-sm text-slate-600 dark:text-slate-300';
        feedback.setAttribute('aria-live', 'polite');
        const expirationLabel = root.createElement('label');
        expirationLabel.className = 'mt-4 block text-sm font-semibold';
        expirationLabel.textContent = 'Expiration';
        const expiration = root.createElement('input');
        expiration.type = 'datetime-local';
        expiration.value = toLocalDateTime(link.expires_at);
        expiration.className =
            'mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/15 dark:bg-slate-950 sm:max-w-xs';
        expirationLabel.append(expiration);
        const actions = root.createElement('div');
        actions.className = 'mt-3 flex flex-wrap gap-2';
        const update = button('Update expiration');
        const toggle = button(link.status === 'disabled' ? 'Enable link' : 'Disable link');
        const remove = button('Delete link', 'text-red-700 dark:text-red-300');
        actions.append(update, toggle, remove);
        const actionButtons = [update, toggle, remove];
        const setActionBusy = (busy) => {
            actionButtons.forEach((action) => {
                action.disabled = busy;
            });
            expiration.disabled = busy;
        };
        const mutate = async (operation, success, removed = false) => {
            setActionBusy(true);
            feedback.setAttribute('role', 'status');
            feedback.textContent = 'Updating link…';
            try {
                await operation();
                pendingFocusCode = removed ? null : link.short_code;
                await loadPage(currentPage, success);
                if (removed) {
                    linksHeading.focus();
                }
            } catch (error) {
                feedback.setAttribute('role', 'alert');
                feedback.textContent =
                    error instanceof TypeError
                        ? 'Unable to reach the service. Check your connection and retry.'
                        : error.message;
                setActionBusy(false);
            }
        };
        update.addEventListener('click', () => {
            const value = expiration.value ? new Date(expiration.value) : null;
            if (value && (Number.isNaN(value.getTime()) || value.getTime() <= Date.now())) {
                feedback.setAttribute('role', 'alert');
                feedback.textContent = 'Choose an expiration in the future.';
                expiration.focus();
                return;
            }
            mutate(
                () =>
                    request(`/${encodeURIComponent(link.short_code)}`, {
                        method: 'PATCH',
                        body: { expires_at: value?.toISOString() ?? null },
                    }),
                'Expiration updated.',
            );
        });
        toggle.addEventListener('click', () =>
            mutate(
                () =>
                    request(
                        `/${encodeURIComponent(link.short_code)}/${link.status === 'disabled' ? 'enable' : 'disable'}`,
                        { method: 'POST' },
                    ),
                link.status === 'disabled' ? 'Link enabled.' : 'Link disabled.',
            ),
        );
        remove.addEventListener('click', () => {
            if (!root.defaultView.confirm('Delete this short link? This action cannot be undone.')) {
                return;
            }
            mutate(
                () => request(`/${encodeURIComponent(link.short_code)}`, { method: 'DELETE' }),
                'Link deleted.',
                true,
            );
        });
        item.append(heading, destination, details, expirationLabel, actions, feedback, analyticsPanel(link));

        return item;
    };

    const render = (payload) => {
        const links = Array.isArray(payload.data) ? payload.data : [];
        const meta = payload.meta ?? {};
        currentPage = Number(meta.current_page) || 1;
        lastPage = Number(meta.last_page) || 1;
        list.replaceChildren(...links.map(renderLink));
        empty.hidden = links.length !== 0;
        pagination.hidden = lastPage <= 1;
        pageLabel.textContent = `Page ${currentPage} of ${lastPage}`;
        previous.disabled = currentPage <= 1;
        next.disabled = currentPage >= lastPage;
        if (pendingFocusCode) {
            list.querySelector(`[data-short-code="${CSS.escape(pendingFocusCode)}"] h3`)?.focus();
            pendingFocusCode = null;
        }
    };

    const loadPage = async (page = 1, completionMessage = null) => {
        let focusRetry = false;
        setBusy(true);
        retry.hidden = true;
        announce('Loading your links…');
        try {
            const payload = await request(`?page=${page}&per_page=10`);
            render(payload);
            announce(
                completionMessage ??
                    (payload.meta?.total === 1 ? 'Loaded 1 link.' : `Loaded ${payload.meta?.total ?? 0} links.`),
            );
        } catch (error) {
            announce(
                error instanceof TypeError
                    ? 'Unable to reach the service. Check your connection and retry.'
                    : error.message,
                true,
            );
            retry.hidden = false;
            focusRetry = true;
        } finally {
            setBusy(false);
            if (focusRetry) {
                retry.focus();
            }
        }
    };

    const recentLinks = () => {
        try {
            const links = JSON.parse(root.defaultView.localStorage.getItem(historyKey) ?? '[]');
            if (!Array.isArray(links)) {
                return [];
            }

            return links.filter((link) => {
                if (
                    typeof link?.short_code !== 'string' ||
                    !/^[a-zA-Z0-9-]{1,64}$/.test(link.short_code) ||
                    typeof link?.short_url !== 'string' ||
                    typeof link?.management_token !== 'string' ||
                    !/^[a-f0-9]{64}$/.test(link.management_token)
                ) {
                    return false;
                }
                try {
                    return ['http:', 'https:'].includes(new URL(link.short_url).protocol);
                } catch {
                    return false;
                }
            });
        } catch {
            return [];
        }
    };

    const renderClaims = () => {
        const links = recentLinks();
        claimSelect.replaceChildren();
        links.forEach((link, index) => {
            claimSelect.add(new Option(link.short_url, String(index)));
        });
        claimSection.hidden = links.length === 0;
        claimForm.dataset.count = String(links.length);
        return links;
    };

    createForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        createError.textContent = '';
        const value = createUrl.value.trim();
        try {
            const parsed = new URL(value);
            if (!['http:', 'https:'].includes(parsed.protocol)) {
                throw new Error();
            }
        } catch {
            createError.textContent = 'Enter a complete HTTP or HTTPS URL.';
            createUrl.setAttribute('aria-invalid', 'true');
            createUrl.focus();
            return;
        }
        createUrl.removeAttribute('aria-invalid');
        const submit = createForm.querySelector('button[type="submit"]');
        submit.disabled = true;
        announce('Creating your link…');
        const body = { long_url: value };
        if (createAlias.value.trim()) body.custom_alias = createAlias.value.trim().toLowerCase();
        if (createExpiration.value) body.expires_at = new Date(createExpiration.value).toISOString();
        try {
            const created = await request('', { method: 'POST', body });
            createForm.reset();
            pendingFocusCode = created.short_code;
            await loadPage(1, 'Link created.');
        } catch (error) {
            const field = error.payload?.errors?.custom_alias ? createAlias : createUrl;
            createError.textContent =
                error instanceof TypeError
                    ? 'Unable to reach the service. Check your connection and retry.'
                    : error.message;
            createError.setAttribute('role', 'alert');
            field.focus();
        } finally {
            submit.disabled = false;
        }
    });

    claimForm.addEventListener('submit', async (event) => {
        let focusClaim = false;
        event.preventDefault();
        const links = renderClaims();
        const link = links[Number(claimSelect.value)];
        if (!link) {
            claimMessage.textContent = 'Choose a valid recent link.';
            return;
        }
        claimButton.disabled = true;
        claimMessage.setAttribute('role', 'status');
        claimMessage.textContent = 'Claiming link…';
        try {
            await request(`/${encodeURIComponent(link.short_code)}/claim`, {
                method: 'POST',
                headers: { 'X-Management-Token': link.management_token },
            });
            const saved = JSON.parse(root.defaultView.localStorage.getItem(historyKey) ?? '[]');
            if (Array.isArray(saved)) {
                root.defaultView.localStorage.setItem(
                    historyKey,
                    JSON.stringify(saved.filter((item) => item?.short_code !== link.short_code)),
                );
            }
            pendingFocusCode = link.short_code;
            renderClaims();
            await loadPage(1, 'Recent link claimed.');
        } catch (error) {
            claimMessage.setAttribute('role', 'alert');
            claimMessage.textContent =
                error.status === 403
                    ? 'Verify your email before claiming a link.'
                    : error instanceof TypeError
                      ? 'Unable to reach the service. Check your connection and retry.'
                      : error.message;
            focusClaim = true;
        } finally {
            claimButton.disabled = false;
            if (focusClaim) {
                claimButton.focus();
            }
        }
    });

    retry.addEventListener('click', () => loadPage(currentPage));
    previous.addEventListener('click', () => loadPage(currentPage - 1));
    next.addEventListener('click', () => loadPage(currentPage + 1));
    renderClaims();
    loadPage();
};
