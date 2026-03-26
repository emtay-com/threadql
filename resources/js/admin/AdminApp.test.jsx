import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import axios from 'axios';
import AdminApp from './AdminApp';
import { useTenantsStore } from './stores/tenantsStore';
import { isValidToken } from '../services/token';

vi.mock('../services/token', () => ({
    isValidToken: vi.fn(),
    decodeToken: vi.fn(() => null),
}));

// Avoid rendering real lazy-loaded pages
vi.mock('./routes', () => ({
    adminRoutes: [],
    mainNavRoutes: [],
    tenantNavRoutes: [],
    buildTenantPath: (path, id) => path.replace(':tenantId', id ?? ''),
}));

// MainMenu uses NavLink; mock to avoid route assertion complexity
vi.mock('./components/MainMenu', () => ({
    default: () => <nav data-testid="main-menu" />,
}));

const mockInterceptors = {
    response: {
        use: vi.fn(() => 0),
        eject: vi.fn(),
    },
};

vi.mock('axios', () => ({
    default: {
        post: vi.fn(() => Promise.resolve({ data: {} })),
        get: vi.fn(() => Promise.resolve({ data: {} })),
        defaults: { headers: { common: {} } },
        interceptors: {
            response: {
                use: vi.fn(() => 0),
                eject: vi.fn(),
            },
        },
    },
}));

describe('AdminApp', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.clear();
        localStorage.clear();
        useTenantsStore.setState({ tenants: [], isLoading: false, error: null });
    });

    afterEach(() => {
        sessionStorage.clear();
        localStorage.clear();
    });

    it('shows login screen when no token is stored', async () => {
        isValidToken.mockReturnValue(false);
        render(<AdminApp />);
        await waitFor(() => {
            expect(screen.getByText('Sign in to continue')).toBeInTheDocument();
        });
    });

    it('shows login screen when stored token is invalid', async () => {
        sessionStorage.setItem('admin_token', 'expired.token.here');
        isValidToken.mockReturnValue(false);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByText('Sign in to continue')).toBeInTheDocument();
        });
    });

    it('removes invalid token from sessionStorage', async () => {
        sessionStorage.setItem('admin_token', 'expired.token.here');
        isValidToken.mockReturnValue(false);

        render(<AdminApp />);

        await waitFor(() => {
            expect(sessionStorage.getItem('admin_token')).toBeNull();
        });
    });

    it('shows admin dashboard when stored token is valid', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByText('Admin Dashboard')).toBeInTheDocument();
        });
    });

    it('shows logout button when authenticated', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Logout' })).toBeInTheDocument();
        });
    });

    it('clears token from sessionStorage on logout', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Logout' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Logout' }));

        await waitFor(() => {
            expect(sessionStorage.getItem('admin_token')).toBeNull();
        });
    });

    it('calls POST /api/admin/token/logout on logout', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Logout' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Logout' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith('/api/admin/token/logout', {}, { withCredentials: true });
        });
    });

    it('shows login screen after logout', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Logout' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Logout' }));

        await waitFor(() => {
            expect(screen.getByText('Sign in to continue')).toBeInTheDocument();
        });
    });

    it('clears activeTenant from localStorage on logout', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        localStorage.setItem('activeTenant', 'some-tenant-uuid');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Logout' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Logout' }));

        await waitFor(() => {
            expect(localStorage.getItem('activeTenant')).toBeNull();
        });
    });

    it('sets axios Authorization header when valid token is present on load', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(axios.defaults.headers.common['Authorization']).toBe('Bearer valid.token.here');
        });
    });

    it('clears axios Authorization header on logout', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Logout' })).toBeInTheDocument();
        });

        axios.defaults.headers.common['Authorization'] = 'Bearer valid.token.here';
        fireEvent.click(screen.getByRole('button', { name: 'Logout' }));

        await waitFor(() => {
            expect(axios.defaults.headers.common['Authorization']).toBeUndefined();
        });
    });

    it('sets up axios response interceptor on mount', async () => {
        isValidToken.mockReturnValue(false);
        render(<AdminApp />);

        await waitFor(() => {
            expect(axios.interceptors.response.use).toHaveBeenCalled();
        });
    });

    it('resets tenants store on logout', async () => {
        sessionStorage.setItem('admin_token', 'valid.token.here');
        isValidToken.mockReturnValue(true);

        useTenantsStore.setState({ tenants: [{ id: 1, name: 'Acme' }], isLoading: false, error: null });

        render(<AdminApp />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Logout' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Logout' }));

        await waitFor(() => {
            expect(useTenantsStore.getState().tenants).toEqual([]);
        });
    });
});
