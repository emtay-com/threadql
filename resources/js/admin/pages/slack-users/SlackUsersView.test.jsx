import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import axios from 'axios';
import SlackUsersView from './SlackUsersView';
import { ToastProvider } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));

const mockTenants = [
    { id: 1, uuid: 'tenant-uuid-1', name: 'Tenant A' },
    { id: 2, uuid: 'tenant-uuid-2', name: 'Tenant B' },
];

const mockSlackUsers = [
    {
        id: 1,
        tenant_id: 1,
        slack_user_id: 'U1234567890',
        real_name: 'John Doe',
        display_name: 'johndoe',
        avatar_url: 'https://example.com/avatar1.jpg',
        approved: true,
        created_at: '2026-01-01T00:00:00+00:00',
        deleted_at: null,
    },
    {
        id: 2,
        tenant_id: 1,
        slack_user_id: 'U0987654321',
        real_name: 'Jane Smith',
        display_name: 'janesmith',
        avatar_url: 'https://example.com/avatar2.jpg',
        approved: false,
        created_at: '2026-01-02T00:00:00+00:00',
        deleted_at: null,
    },
];

function renderView(initialPath = '/panel/slack-users/tenant-uuid-1') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <ToastProvider>
                <Routes>
                    <Route path="/panel/slack-users/:tenantId" element={<SlackUsersView />} />
                    <Route path="/panel/slack-users" element={<SlackUsersView />} />
                </Routes>
            </ToastProvider>
        </MemoryRouter>
    );
}

describe('SlackUsersView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/slack-users')) {
                return Promise.resolve({ data: { data: mockSlackUsers } });
            }
            if (url.includes('/tenants')) {
                return Promise.resolve({ data: { data: mockTenants } });
            }
            return Promise.resolve({ data: { data: [] } });
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('renders slack users for selected tenant', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('John Doe')).toBeInTheDocument();
            expect(screen.getByText('Jane Smith')).toBeInTheDocument();
        });
    });

    it('shows slack user ids', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('U1234567890')).toBeInTheDocument();
            expect(screen.getByText('U0987654321')).toBeInTheDocument();
        });
    });

    it('shows display names', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('@johndoe')).toBeInTheDocument();
            expect(screen.getByText('@janesmith')).toBeInTheDocument();
        });
    });

    it('renders approved switch for each user', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('John Doe')).toBeInTheDocument();
        });

        const switches = screen.getAllByRole('switch');
        expect(switches).toHaveLength(2);

        const johnSwitch = screen.getByRole('switch', { name: /Approved John Doe/i });
        expect(johnSwitch).toHaveAttribute('aria-checked', 'true');

        const janeSwitch = screen.getByRole('switch', { name: /Approved Jane Smith/i });
        expect(janeSwitch).toHaveAttribute('aria-checked', 'false');
    });

    it('toggles approval via PUT request', async () => {
        axios.put.mockResolvedValue({ status: 204 });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Jane Smith')).toBeInTheDocument();
        });

        const janeSwitch = screen.getByRole('switch', { name: /Approved Jane Smith/i });
        fireEvent.click(janeSwitch);

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith(
                '/api/admin/tenants/1/slack-users/2',
                { approved: true }
            );
        });
    });

    it('sends PUT request with name fields on edit save', async () => {
        axios.put.mockResolvedValue({ status: 204 });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('John Doe')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        const realNameInput = screen.getByPlaceholderText('Real name');
        fireEvent.change(realNameInput, { target: { value: 'John Updated' } });

        const saveButton = screen.getByLabelText('Save');
        fireEvent.click(saveButton);

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith(
                '/api/admin/tenants/1/slack-users/1',
                { real_name: 'John Updated', display_name: 'johndoe' }
            );
        });
    });

    it('sends DELETE request on delete', async () => {
        axios.delete.mockResolvedValue({ status: 204 });
        window.confirm = vi.fn(() => true);

        renderView();

        await waitFor(() => {
            expect(screen.getByText('John Doe')).toBeInTheDocument();
        });

        const deleteButtons = screen.getAllByLabelText('Delete');
        fireEvent.click(deleteButtons[0]);

        await waitFor(() => {
            expect(axios.delete).toHaveBeenCalledWith(
                '/api/admin/tenants/1/slack-users/1'
            );
        });
    });

    it('shows select tenant message when no tenant selected', async () => {
        localStorage.removeItem('activeTenant');
        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
        });

        renderView('/panel/slack-users');

        await waitFor(() => {
            expect(screen.getByText('Select a tenant to view Slack users.')).toBeInTheDocument();
        });
    });

    it('shows empty state when no users', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/slack-users')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('No Slack users yet.')).toBeInTheDocument();
        });
    });

    it('shows total count in header', async () => {
        renderView();

        await waitFor(() => {
            const heading = screen.getByRole('heading', { name: 'Slack Users' });
            expect(heading).toBeInTheDocument();
            expect(screen.getByText('2 total')).toBeInTheDocument();
        });
    });

    it('shows restore button for deleted users', async () => {
        const usersWithDeleted = [
            ...mockSlackUsers,
            {
                id: 3,
                tenant_id: 1,
                slack_user_id: 'U_DELETED',
                real_name: 'Deleted User',
                display_name: null,
                avatar_url: null,
                approved: false,
                created_at: '2026-01-03T00:00:00+00:00',
                deleted_at: '2026-02-01T00:00:00+00:00',
            },
        ];

        axios.get.mockImplementation((url) => {
            if (url.includes('/slack-users')) {
                return Promise.resolve({ data: { data: usersWithDeleted } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Deleted User')).toBeInTheDocument();
        });

        const restoreButton = screen.getByLabelText('Restore');
        expect(restoreButton).toBeInTheDocument();
    });

    it('sends PATCH request on restore', async () => {
        axios.patch.mockResolvedValue({ status: 204 });

        const usersWithDeleted = [
            {
                id: 3,
                tenant_id: 1,
                slack_user_id: 'U_DELETED',
                real_name: 'Deleted User',
                display_name: null,
                avatar_url: null,
                approved: false,
                created_at: '2026-01-03T00:00:00+00:00',
                deleted_at: '2026-02-01T00:00:00+00:00',
            },
        ];

        axios.get.mockImplementation((url) => {
            if (url.includes('/slack-users')) {
                return Promise.resolve({ data: { data: usersWithDeleted } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Deleted User')).toBeInTheDocument();
        });

        const restoreButton = screen.getByLabelText('Restore');
        fireEvent.click(restoreButton);

        await waitFor(() => {
            expect(axios.patch).toHaveBeenCalledWith(
                '/api/admin/tenants/1/slack-users/3'
            );
        });
    });
});
