import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import MainMenu from './MainMenu';

vi.mock('../../services/token', () => ({
    decodeToken: vi.fn(),
}));

import { decodeToken } from '../../services/token';

function renderMenu(initialPath = '/panel/tenants') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <MainMenu />
        </MemoryRouter>
    );
}

describe('MainMenu', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.clear();
    });

    afterEach(() => {
        sessionStorage.clear();
    });

    it('renders the Tenants nav link for all users', () => {
        decodeToken.mockReturnValue({ level: 'tenant' });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu();

        expect(screen.getByRole('link', { name: 'Tenants' })).toBeInTheDocument();
    });

    it('renders the Settings nav link for master users (is_master flag)', () => {
        decodeToken.mockReturnValue({ is_master: true });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu();

        expect(screen.getByRole('link', { name: 'Settings' })).toBeInTheDocument();
    });

    it('renders the Settings nav link for master users (level === "master")', () => {
        decodeToken.mockReturnValue({ level: 'master' });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu();

        expect(screen.getByRole('link', { name: 'Settings' })).toBeInTheDocument();
    });

    it('hides the Settings nav link for non-master users', () => {
        decodeToken.mockReturnValue({ level: 'tenant' });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu();

        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
    });

    it('hides the Settings nav link when no token is present', () => {
        decodeToken.mockReturnValue(null);

        renderMenu();

        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
    });

    it('Tenants link points to /panel/tenants', () => {
        decodeToken.mockReturnValue({ level: 'tenant' });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu();

        expect(screen.getByRole('link', { name: 'Tenants' })).toHaveAttribute('href', '/panel/tenants');
    });

    it('Settings link points to /panel/settings', () => {
        decodeToken.mockReturnValue({ level: 'master' });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu();

        expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', '/panel/settings');
    });

    it('applies active styling to the current route link', () => {
        decodeToken.mockReturnValue({ level: 'master' });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu('/panel/tenants');

        const tenantsLink = screen.getByRole('link', { name: 'Tenants' });
        expect(tenantsLink.className).toContain('border-b-2');
    });

    it('does not apply active styling to non-current route links', () => {
        decodeToken.mockReturnValue({ level: 'master' });
        sessionStorage.setItem('admin_token', 'fake');

        renderMenu('/panel/tenants');

        const settingsLink = screen.getByRole('link', { name: 'Settings' });
        expect(settingsLink.className).not.toContain('border-b-2');
    });

    it('hides Settings when decodeToken returns undefined', () => {
        decodeToken.mockReturnValue(undefined);

        renderMenu();

        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
        // Tenants link should still be visible (not requiresMaster)
        expect(screen.getByRole('link', { name: 'Tenants' })).toBeInTheDocument();
    });
});
