export const initTheme = (root = document) => {
    const button = root.querySelector('#theme-toggle');

    if (!button) {
        return;
    }

    const storageKey = 'shortly.theme';
    const sun = button.querySelector('[data-theme-sun]');
    const moon = button.querySelector('[data-theme-moon]');

    const render = () => {
        const dark = root.documentElement.classList.contains('dark');
        root.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        button.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
        button.setAttribute('aria-pressed', String(dark));
        sun.classList.toggle('hidden', dark);
        moon.classList.toggle('hidden', !dark);
    };

    button.addEventListener('click', () => {
        root.documentElement.classList.toggle('dark');

        try {
            root.defaultView?.localStorage.setItem(
                storageKey,
                root.documentElement.classList.contains('dark') ? 'dark' : 'light',
            );
        } catch {
            // The selected theme still applies for this page view.
        }

        render();
    });

    render();
};
