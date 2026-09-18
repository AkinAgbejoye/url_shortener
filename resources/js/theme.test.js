import { fireEvent, getByRole } from '@testing-library/dom';
import { beforeEach, describe, expect, it } from 'vitest';
import { initTheme } from './theme';

describe('theme toggle', () => {
    beforeEach(() => {
        document.documentElement.className = '';
        document.documentElement.style.colorScheme = '';
        localStorage.clear();
        document.body.innerHTML = `
            <button id="theme-toggle" type="button">
                <span data-theme-sun></span>
                <span data-theme-moon class="hidden"></span>
            </button>
        `;
    });

    it('toggles and persists the selected theme', () => {
        initTheme();
        const button = getByRole(document.body, 'button', { name: 'Switch to dark theme' });

        fireEvent.click(button);

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');
        expect(localStorage.getItem('shortly.theme')).toBe('dark');
        expect(button.getAttribute('aria-label')).toBe('Switch to light theme');
        expect(button.getAttribute('aria-pressed')).toBe('true');

        fireEvent.click(button);
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(localStorage.getItem('shortly.theme')).toBe('light');
    });
});
