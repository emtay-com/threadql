import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import axios from 'axios';
import SettingsPage from './SettingsPage';
import { decodeToken } from '../../services/token';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('../../services/token', () => ({
    decodeToken: vi.fn(),
}));

function LocationDisplay() {
    const location = useLocation();

    return <div data-testid="location">{location.pathname}</div>;
}

function renderSettings(initialPath = '/panel/settings/users') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <Routes>
                <Route path="/panel/settings" element={<SettingsPage />} />
                <Route path="/panel/settings/users" element={<SettingsPage />} />
                <Route path="/panel/tenants" element={<div>Tenants</div>} />
            </Routes>
            <LocationDisplay />
        </MemoryRouter>
    );
}

const mockGeneralSettings = [
    {
        id: 1,
        setting: 'max_rows_inline_csv',
        value: '100',
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 2,
        setting: 'max_priority_tables',
        value: '20',
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 3,
        setting: 'llm_resume_max_steps',
        value: '10',
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 4,
        setting: 'start_of_week',
        value: 'monday',
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 5,
        setting: 'week_definition',
        value: 'iso',
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 6,
        setting: 'max_tokens',
        value: '64000',
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
    },
];

function mockMasterResponses() {
    axios.get.mockImplementation((url) => {
        if (url === '/api/admin/settings') {
            return Promise.resolve({
                data: {
                    data: mockGeneralSettings,
                },
            });
        }

        if (url === '/api/admin/users') {
            return Promise.resolve({
                data: {
                    data: [
                        {
                            id: 1,
                            username: 'alice',
                            email: 'alice@example.com',
                            tenant_id: 10,
                            tenant_name: 'Tenant A',
                            level: 'tenant',
                        },
                    ],
                },
            });
        }

        if (url === '/api/admin/tenants') {
            return Promise.resolve({
                data: {
                    data: [
                        { id: 10, name: 'Tenant A' },
                    ],
                },
            });
        }

        return Promise.resolve({ data: { data: [] } });
    });
}

describe('SettingsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'token');
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('uses a sidebar-style settings menu and loads users for master', async () => {
        decodeToken.mockReturnValue({ level: 'master' });
        mockMasterResponses();

        renderSettings('/panel/settings/users');

        expect(screen.getAllByRole('button', { name: 'General' }).length).toBeGreaterThan(0);
        expect(screen.getAllByRole('button', { name: 'Users' }).length).toBeGreaterThan(0);

        await waitFor(() => {
            expect(screen.getByText('alice@example.com')).toBeInTheDocument();
        });
    });

    it('puts tenant as the first field in the user form', async () => {
        decodeToken.mockReturnValue({ level: 'master' });
        mockMasterResponses();

        const { container } = renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByText('alice@example.com')).toBeInTheDocument();
        });

        const labelTexts = Array.from(container.querySelectorAll('form label')).map((label) => label.textContent?.trim());
        expect(labelTexts[0]).toBe('Tenant');
    });

    it('uses icon action buttons with accessible labels in the users table', async () => {
        decodeToken.mockReturnValue({ level: 'master' });
        mockMasterResponses();

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /Delete user alice/i })).toBeInTheDocument();
        });

        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('redirects non-master users away from settings routes', async () => {
        decodeToken.mockReturnValue({ level: 'tenant' });
        axios.get.mockResolvedValue({ data: { data: [] } });

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByTestId('location')).toHaveTextContent('/panel/tenants');
        });

        expect(screen.queryByRole('button', { name: 'General' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Users' })).not.toBeInTheDocument();
    });
});

describe('SettingsPage - User CRUD', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'token');
        decodeToken.mockReturnValue({ level: 'master' });
        mockMasterResponses();
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('shows loading state while fetching users', async () => {
        let resolve;
        axios.get.mockImplementation(() => new Promise((r) => { resolve = r; }));

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByText('Loading users...')).toBeInTheDocument();
        });

        resolve({ data: { data: [] } });
    });

    it('shows "No users found" when user list is empty', async () => {
        axios.get.mockResolvedValue({ data: { data: [] } });

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByText('No users found.')).toBeInTheDocument();
        });
    });

    it('creates a user via POST when form is submitted', async () => {
        axios.post.mockResolvedValue({
            data: { data: { id: 99, username: 'bob', email: 'bob@example.com', tenant_id: 10, tenant_name: 'Tenant A', level: 'tenant' } },
        });

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByText('alice@example.com')).toBeInTheDocument();
        });

        const selects = screen.getAllByRole('combobox');
        fireEvent.change(selects[0], { target: { value: '10' } });

        const textboxes = screen.getAllByRole('textbox');
        fireEvent.change(textboxes[0], { target: { value: 'bob' } });
        fireEvent.change(textboxes[1], { target: { value: 'bob@example.com' } });

        const passwordInputs = document.querySelectorAll('input[type="password"]');
        fireEvent.change(passwordInputs[0], { target: { value: 'secret' } });

        fireEvent.submit(screen.getByRole('button', { name: /Create User/i }).closest('form'));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith('/api/admin/users', expect.objectContaining({
                username: 'bob',
                email: 'bob@example.com',
            }));
        });
    });

    it('shows validation error when required fields are missing on submit', async () => {
        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByText('alice@example.com')).toBeInTheDocument();
        });

        fireEvent.submit(screen.getByRole('button', { name: /Create User/i }).closest('form'));

        await waitFor(() => {
            expect(screen.getByText('Username, email and tenant are required.')).toBeInTheDocument();
        });
    });

    it('shows validation error when password missing for new user', async () => {
        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByText('alice@example.com')).toBeInTheDocument();
        });

        // Fill tenant, username, email but not password
        const selects = screen.getAllByRole('combobox');
        fireEvent.change(selects[0], { target: { value: '10' } });

        const textInputs = screen.getAllByRole('textbox');
        fireEvent.change(textInputs[0], { target: { value: 'bob' } });
        fireEvent.change(textInputs[1], { target: { value: 'bob@example.com' } });

        fireEvent.submit(screen.getByRole('button', { name: /Create User/i }).closest('form'));

        await waitFor(() => {
            expect(screen.getByText('Password is required for new users.')).toBeInTheDocument();
        });
    });

    it('populates form with user data when Edit is clicked', async () => {
        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Edit user alice/i }));

        expect(screen.getByDisplayValue('alice')).toBeInTheDocument();
        expect(screen.getByDisplayValue('alice@example.com')).toBeInTheDocument();
        expect(screen.getByText('Edit User')).toBeInTheDocument();
    });

    it('shows Cancel button when editing an existing user', async () => {
        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Edit user alice/i }));

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('resets form on Cancel during edit', async () => {
        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Edit user alice/i }));

        // In edit mode, the submit button says "Update User"
        expect(screen.getByRole('button', { name: /Update User/i })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        // After cancel, back to "Create User" mode (submit button and heading)
        expect(screen.getByRole('button', { name: /Create User/i })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
    });

    it('calls PUT /api/admin/users/:id when editing an existing user', async () => {
        axios.put.mockResolvedValue({
            data: { data: { id: 1, username: 'alice-updated', email: 'alice@example.com', tenant_id: 10, tenant_name: 'Tenant A', level: 'tenant' } },
        });

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Edit user alice/i }));

        const usernameInput = screen.getByDisplayValue('alice');
        fireEvent.change(usernameInput, { target: { value: 'alice-updated' } });

        fireEvent.submit(screen.getByRole('button', { name: /Update User/i }).closest('form'));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith('/api/admin/users/1', expect.objectContaining({
                username: 'alice-updated',
            }));
        });
    });

    it('shows error when save fails', async () => {
        axios.put.mockRejectedValue({
            response: { data: { error: 'Email already taken' } },
        });

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Edit user alice/i }));
        fireEvent.submit(screen.getByRole('button', { name: /Update User/i }).closest('form'));

        await waitFor(() => {
            expect(screen.getByText('Email already taken')).toBeInTheDocument();
        });
    });

    it('deletes a user after confirming', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        axios.delete.mockResolvedValue({ data: {} });

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Delete user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Delete user alice/i }));

        await waitFor(() => {
            expect(axios.delete).toHaveBeenCalledWith('/api/admin/users/1');
        });

        await waitFor(() => {
            expect(screen.queryByText('alice@example.com')).not.toBeInTheDocument();
        });
    });

    it('does not delete user when confirm is cancelled', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Delete user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Delete user alice/i }));

        expect(axios.delete).not.toHaveBeenCalled();
    });

    it('shows error when delete fails', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        axios.delete.mockRejectedValue(new Error('Delete failed'));

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Delete user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Delete user alice/i }));

        await waitFor(() => {
            expect(screen.getByText('Failed to delete user')).toBeInTheDocument();
        });
    });

    it('resets form when the user being edited is deleted', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        axios.delete.mockResolvedValue({ data: {} });

        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
        });

        // Start editing alice
        fireEvent.click(screen.getByRole('button', { name: /Edit user alice/i }));
        expect(screen.getByRole('button', { name: /Update User/i })).toBeInTheDocument();

        // Now delete the user being edited
        fireEvent.click(screen.getByRole('button', { name: /Delete user alice/i }));

        await waitFor(() => {
            expect(axios.delete).toHaveBeenCalledWith('/api/admin/users/1');
        });

        // Form should reset back to Create User mode
        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Create User/i })).toBeInTheDocument();
        });

        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
    });

    it('shows "Update User" button label when editing', async () => {
        renderSettings('/panel/settings/users');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Edit user alice/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Edit user alice/i }));

        expect(screen.getByRole('button', { name: /Update User/i })).toBeInTheDocument();
    });
});

describe('SettingsPage - General section', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'token');
        decodeToken.mockReturnValue({ level: 'master' });
        mockMasterResponses();
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('loads and renders general settings fields', async () => {
        renderSettings('/panel/settings');

        await waitFor(() => {
            expect(screen.getByText('General Settings')).toBeInTheDocument();
        });

        expect(screen.getByRole('spinbutton', { name: 'Max Rows Inline CSV' })).toHaveValue(100);
        expect(screen.getByRole('spinbutton', { name: 'Max Priority Tables' })).toHaveValue(20);
        expect(screen.getByRole('spinbutton', { name: 'LLM Resume Max Steps' })).toHaveValue(10);
        expect(screen.getByRole('spinbutton', { name: 'Max Tokens' })).toHaveValue(64000);
        expect(screen.getByRole('combobox', { name: 'Start of Week' })).toHaveValue('monday');
        expect(screen.getByRole('combobox', { name: 'Week Definition' })).toHaveValue('iso');
    });

    it('shows Settings heading on the general section', async () => {
        renderSettings('/panel/settings');

        await waitFor(() => {
            // Both the sidebar label and main content area have an h2 "Settings"
            expect(screen.getAllByRole('heading', { name: 'Settings' }).length).toBeGreaterThanOrEqual(1);
        });
    });

    it('enables save when a general setting changes', async () => {
        renderSettings('/panel/settings');

        await waitFor(() => {
            expect(screen.getByRole('spinbutton', { name: 'Max Rows Inline CSV' })).toBeInTheDocument();
        });

        const saveButton = screen.getByRole('button', { name: 'Save' });
        expect(saveButton).toBeDisabled();

        fireEvent.change(screen.getByRole('spinbutton', { name: 'Max Rows Inline CSV' }), {
            target: { value: '150' },
        });

        expect(saveButton).not.toBeDisabled();
    });

    it('saves all general settings with the required payload shape', async () => {
        axios.put.mockResolvedValue({ status: 204 });

        renderSettings('/panel/settings');

        await waitFor(() => {
            expect(screen.getByRole('spinbutton', { name: 'Max Rows Inline CSV' })).toBeInTheDocument();
        });

        fireEvent.change(screen.getByRole('spinbutton', { name: 'Max Rows Inline CSV' }), {
            target: { value: '150' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith('/api/admin/settings', {
                settings: [
                    { setting: 'max_rows_inline_csv', value: '150' },
                    { setting: 'max_priority_tables', value: '20' },
                    { setting: 'llm_resume_max_steps', value: '10' },
                    { setting: 'start_of_week', value: 'monday' },
                    { setting: 'week_definition', value: 'iso' },
                    { setting: 'max_tokens', value: '64000' },
                ],
            });
        });
    });

    it('saves enum general settings using select inputs', async () => {
        axios.put.mockResolvedValue({ status: 204 });

        renderSettings('/panel/settings');

        await waitFor(() => {
            expect(screen.getByRole('combobox', { name: 'Start of Week' })).toBeInTheDocument();
        });

        fireEvent.change(screen.getByRole('combobox', { name: 'Start of Week' }), {
            target: { value: 'sunday' },
        });
        fireEvent.change(screen.getByRole('combobox', { name: 'Week Definition' }), {
            target: { value: 'us' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith('/api/admin/settings', {
                settings: [
                    { setting: 'max_rows_inline_csv', value: '100' },
                    { setting: 'max_priority_tables', value: '20' },
                    { setting: 'llm_resume_max_steps', value: '10' },
                    { setting: 'start_of_week', value: 'sunday' },
                    { setting: 'week_definition', value: 'us' },
                    { setting: 'max_tokens', value: '64000' },
                ],
            });
        });
    });
});
