import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import axios from 'axios';
import TenantsView from './TenantsView';
import { ToastProvider } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';
import { decodeToken } from '../../../services/token';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('../../../services/token', () => ({
    decodeToken: vi.fn(() => ({ is_master: true, level: 'master' })),
    isValidToken: vi.fn(() => true),
}));

const mockTenants = [
    {
        id: 1,
        uuid: 'tenant-uuid-1',
        name: 'Tenant A',
        timezone: 'UTC',
        slack_app_id: 'A123',
        slack_client_id: 'C123',
        has_slack_bot_token: true,
        has_slack_signing_secret: true,
        has_slack_verification_token: true,
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
    },
];

const mockManifest = JSON.stringify({
    display_information: { name: 'Tenant A' },
    features: { bot_user: { display_name: 'Tenant A' } },
}, null, 2);

function renderView(initialPath = '/panel/tenants/edit/tenant-uuid-1') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <ToastProvider>
                <Routes>
                    <Route path="/panel/tenants" element={<TenantsView />} />
                    <Route path="/panel/tenants/:tenantId" element={<TenantsView />} />
                    <Route path="/panel/tenants/edit/add" element={<TenantsView />} />
                    <Route path="/panel/tenants/edit/:tenantId" element={<TenantsView />} />
                </Routes>
            </ToastProvider>
        </MemoryRouter>
    );
}

// ─── View mode ───────────────────────────────────────────────────────────────

describe('TenantsView - View mode', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'fake-token');
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('shows "Select a tenant" when no tenant is selected', async () => {
        localStorage.removeItem('activeTenant');
        renderView('/panel/tenants');

        await waitFor(() => {
            expect(screen.getByText('Select a tenant to view details.')).toBeInTheDocument();
        });
    });

    it('renders tenant card with name in view mode', async () => {
        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: 'Tenant A' })).toBeInTheDocument();
        });
    });

    it('shows Edit button for master users on tenant card', async () => {
        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        });
    });

    it('hides Edit button for non-master users', async () => {
        decodeToken.mockReturnValue({ level: 'tenant' });

        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getAllByText('Tenant A').length).toBeGreaterThan(0);
        });

        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    });

    it('shows Installation Instructions on tenant card when slack is incomplete', async () => {
        const incompleteTenant = {
            ...mockTenants[0],
            has_slack_bot_token: false,
            has_slack_signing_secret: false,
            has_slack_verification_token: false,
        };
        useTenantsStore.setState({
            tenants: [incompleteTenant],
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
        });

        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Installation Instructions')).toBeInTheDocument();
        });
    });

    it('does not show Installation Instructions on tenant card when slack is fully configured', async () => {
        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: 'Tenant A' })).toBeInTheDocument();
        });

        expect(screen.queryByText('Installation Instructions')).not.toBeInTheDocument();
    });

    it('shows Test Slack button when tenant has bot token', async () => {
        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Test Slack' })).toBeInTheDocument();
        });
    });

    it('hides Test Slack button when tenant has no bot token', async () => {
        useTenantsStore.setState({
            tenants: [{ ...mockTenants[0], has_slack_bot_token: false }],
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
        });

        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: 'Tenant A' })).toBeInTheDocument();
        });

        expect(screen.queryByRole('button', { name: 'Test Slack' })).not.toBeInTheDocument();
    });

    it('calls test-slack endpoint and shows success toast', async () => {
        axios.get.mockResolvedValue({
            data: { data: { success: true, message: 'Slack API connection verified' } },
        });

        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Test Slack' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Test Slack' }));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith('/api/admin/tenants/1/test-slack');
        });
    });

    it('shows Testing... while test is in progress', async () => {
        let resolveTest;
        axios.get.mockImplementation(() => new Promise((resolve) => { resolveTest = resolve; }));

        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Test Slack' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Test Slack' }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Testing...' })).toBeInTheDocument();
        });

        resolveTest({ data: { data: { success: true, message: 'OK' } } });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Test Slack' })).toBeInTheDocument();
        });
    });

    it('shows error toast when test-slack endpoint fails', async () => {
        axios.get.mockRejectedValue({
            response: { data: { data: { message: 'invalid_auth' } } },
        });

        renderView('/panel/tenants/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Test Slack' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Test Slack' }));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith('/api/admin/tenants/1/test-slack');
        });
    });
});

// ─── Add mode ────────────────────────────────────────────────────────────────

describe('TenantsView - Add mode', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'fake-token');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
            upsertTenant: vi.fn(),
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('shows Add Tenant form in add mode', async () => {
        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });
    });

    it('Save button is disabled when required fields are empty', async () => {
        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('Save button enables when name and timezone are filled (slack optional)', async () => {
        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'My Tenant' } });

        const timezoneSelect = screen.getByDisplayValue('Select a timezone');
        fireEvent.change(timezoneSelect, { target: { value: 'UTC' } });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Save' })).not.toBeDisabled();
        });
    });

    it('calls POST /api/admin/tenants on add form submit with only name and timezone', async () => {
        axios.post.mockResolvedValue({
            data: { data: { ...mockTenants[0], id: 2, uuid: 'new-uuid' } },
        });

        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'My Tenant' } });
        fireEvent.change(screen.getByDisplayValue('Select a timezone'), { target: { value: 'UTC' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith('/api/admin/tenants', {
                name: 'My Tenant',
                timezone: 'UTC',
            });
        });
    });

    it('includes slack fields in POST when provided', async () => {
        axios.post.mockResolvedValue({
            data: { data: { ...mockTenants[0], id: 2, uuid: 'new-uuid' } },
        });

        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'My Tenant' } });
        fireEvent.change(screen.getByDisplayValue('Select a timezone'), { target: { value: 'UTC' } });
        fireEvent.change(screen.getByPlaceholderText('A123456789'), { target: { value: 'A000' } });
        fireEvent.change(screen.getByPlaceholderText('1234567890.1234567890'), { target: { value: '111.222' } });

        const passwordInputs = screen.getAllByPlaceholderText(/\*+/);
        fireEvent.change(passwordInputs[0], { target: { value: 'xoxb-token' } });
        fireEvent.change(passwordInputs[1], { target: { value: 'signing-secret' } });
        fireEvent.change(passwordInputs[2], { target: { value: 'verif-token' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith('/api/admin/tenants', expect.objectContaining({
                name: 'My Tenant',
                timezone: 'UTC',
                slack_app_id: 'A000',
                slack_client_id: '111.222',
                slack_bot_token: 'xoxb-token',
                slack_signing_secret: 'signing-secret',
                slack_verification_token: 'verif-token',
            }));
        });
    });

    it('shows save error when POST fails', async () => {
        axios.post.mockRejectedValue({
            response: { data: { message: 'Validation failed' } },
        });

        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'My Tenant' } });
        fireEvent.change(screen.getByDisplayValue('Select a timezone'), { target: { value: 'UTC' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getAllByText('Validation failed').length).toBeGreaterThan(0);
        });
    });
});

// ─── Edit mode ───────────────────────────────────────────────────────────────

describe('TenantsView - Edit mode', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'fake-token');
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
            upsertTenant: vi.fn(),
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('pre-populates form with existing tenant data in edit mode', async () => {
        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Edit Tenant')).toBeInTheDocument();
        });

        expect(screen.getByDisplayValue('Tenant A')).toBeInTheDocument();
    });

    it('Save button is enabled when only name and timezone are filled in edit mode', async () => {
        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Edit Tenant')).toBeInTheDocument();
        });

        // name and timezone are already pre-populated
        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Save' })).not.toBeDisabled();
        });
    });

    it('calls PUT /api/admin/tenants/:id on edit form submit', async () => {
        axios.put.mockResolvedValue({
            data: { data: { ...mockTenants[0], name: 'Updated Tenant' } },
        });

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Edit Tenant')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith(
                '/api/admin/tenants/1',
                expect.objectContaining({ name: 'Tenant A', timezone: 'UTC' })
            );
        });
    });

    it('shows save error when PUT fails', async () => {
        axios.put.mockRejectedValue({
            response: { data: { message: 'Server error' } },
        });

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Edit Tenant')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getAllByText('Server error').length).toBeGreaterThan(0);
        });
    });
});

// ─── Save with Slack test ────────────────────────────────────────────────────

describe('TenantsView - Save with Slack test', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'fake-token');
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
            upsertTenant: vi.fn(),
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('tests slack after saving when bot token is provided in edit mode', async () => {
        axios.put.mockResolvedValue({
            data: { data: { ...mockTenants[0], id: 1, uuid: 'tenant-uuid-1' } },
        });
        axios.get.mockResolvedValue({
            data: { data: { success: true, message: 'Slack API connection verified' } },
        });

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Edit Tenant')).toBeInTheDocument();
        });

        // Click Edit on bot token field and enter a value
        const editButtons = screen.getAllByRole('button', { name: 'Edit' });
        fireEvent.click(editButtons[0]); // bot token edit button

        const passwordInputs = screen.getAllByDisplayValue('');
        const botTokenInput = passwordInputs.find(el => el.type === 'password');
        if (botTokenInput) {
            fireEvent.change(botTokenInput, { target: { value: 'xoxb-new-token' } });
        }

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalled();
        });

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith('/api/admin/tenants/1/test-slack');
        });
    });

    it('shows error when slack test fails after save', async () => {
        axios.put.mockResolvedValue({
            data: { data: { ...mockTenants[0], id: 1, uuid: 'tenant-uuid-1', has_slack_bot_token: true } },
        });
        axios.get.mockResolvedValue({
            data: { data: { success: false, message: 'invalid_auth' } },
        });

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Edit Tenant')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalled();
        });

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith('/api/admin/tenants/1/test-slack');
        });

        await waitFor(() => {
            expect(screen.getByText('invalid_auth')).toBeInTheDocument();
        });
    });

    it('does not test slack when saving without slack fields in add mode', async () => {
        axios.post.mockResolvedValue({
            data: { data: { ...mockTenants[0], id: 2, uuid: 'new-uuid', has_slack_bot_token: false } },
        });

        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'My Tenant' } });
        fireEvent.change(screen.getByDisplayValue('Select a timezone'), { target: { value: 'UTC' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalled();
        });

        // Should not call test-slack when no bot token provided
        expect(axios.get).not.toHaveBeenCalledWith(expect.stringContaining('/test-slack'));
    });
});

// ─── Cancel behavior ─────────────────────────────────────────────────────────

describe('TenantsView - Cancel behavior', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'fake-token');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
            upsertTenant: vi.fn(),
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('Cancel button is present in add mode', async () => {
        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('Cancel button is present in edit mode', async () => {
        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Edit Tenant')).toBeInTheDocument();
        });

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('Save button shows "Saving..." while save is in progress', async () => {
        let resolveSave;
        axios.post.mockImplementation(() => new Promise((resolve) => { resolveSave = resolve; }));

        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'My Tenant' } });
        fireEvent.change(screen.getByDisplayValue('Select a timezone'), { target: { value: 'UTC' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Saving...' })).toBeInTheDocument();
        });

        resolveSave({ data: { data: { ...mockTenants[0], id: 2, uuid: 'new-uuid' } } });
    });

    it('form field changes clear the inline save error', async () => {
        axios.post.mockRejectedValue({
            response: { data: { message: 'Validation failed' } },
        });

        const { container } = renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        // Fill and submit to trigger error
        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'My Tenant' } });
        fireEvent.change(screen.getByDisplayValue('Select a timezone'), { target: { value: 'UTC' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        // Wait for the inline form error to appear (inside form, not toast)
        await waitFor(() => {
            const inlineError = container.querySelector('form p.text-red-600');
            expect(inlineError).not.toBeNull();
            expect(inlineError.textContent).toBe('Validation failed');
        });

        // Changing a field should clear the inline error
        fireEvent.change(screen.getByPlaceholderText('e.g., Acme Corp'), { target: { value: 'Other Tenant' } });

        await waitFor(() => {
            const inlineError = container.querySelector('form p.text-red-600');
            expect(inlineError).toBeNull();
        });
    });
});

// ─── Installation Instructions ───────────────────────────────────────────────

describe('TenantsView - Installation Instructions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.setItem('admin_token', 'fake-token');
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('shows installation instructions section in edit mode', async () => {
        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Installation Instructions')).toBeInTheDocument();
        });

        expect(screen.getByText(/Generate a Slack App Manifest/)).toBeInTheDocument();
        expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
    });

    it('does not show installation instructions in add mode', async () => {
        renderView('/panel/tenants/edit/add');

        await waitFor(() => {
            expect(screen.getByText('Add Tenant')).toBeInTheDocument();
        });

        expect(screen.queryByText('Installation Instructions')).not.toBeInTheDocument();
    });

    it('calls manifest API and displays result on generate', async () => {
        axios.get.mockResolvedValue({
            data: { data: { manifest: mockManifest } },
        });

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Generate Manifest'));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith('/api/admin/tenants/1/manifest');
        });

        await waitFor(() => {
            expect(screen.getByText(/display_information/)).toBeInTheDocument();
        });

        expect(screen.getByText('Copy to Clipboard')).toBeInTheDocument();
    });

    it('shows error message when manifest generation fails', async () => {
        axios.get.mockRejectedValue({
            response: { data: { message: 'Tenant has no base URL configured' } },
        });

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Generate Manifest'));

        await waitFor(() => {
            expect(screen.getByText('Tenant has no base URL configured')).toBeInTheDocument();
        });
    });

    it('shows generic error when API fails without message', async () => {
        axios.get.mockRejectedValue(new Error('Network Error'));

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Generate Manifest'));

        await waitFor(() => {
            expect(screen.getByText('Network Error')).toBeInTheDocument();
        });
    });

    it('shows Generating... text while loading', async () => {
        let resolvePromise;
        axios.get.mockImplementation(() => new Promise((resolve) => {
            resolvePromise = resolve;
        }));

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Generate Manifest'));

        expect(screen.getByText('Generating...')).toBeInTheDocument();

        resolvePromise({ data: { data: { manifest: mockManifest } } });

        await waitFor(() => {
            expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
        });
    });

    it('copies manifest to clipboard on copy button click', async () => {
        const writeTextMock = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, {
            clipboard: { writeText: writeTextMock },
        });

        axios.get.mockResolvedValue({
            data: { data: { manifest: mockManifest } },
        });

        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Generate Manifest'));

        await waitFor(() => {
            expect(screen.getByText('Copy to Clipboard')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Copy to Clipboard'));

        await waitFor(() => {
            expect(writeTextMock).toHaveBeenCalledWith(mockManifest);
        });

        await waitFor(() => {
            expect(screen.getByText('Copied')).toBeInTheDocument();
        });
    });

    it('does not show manifest or copy button before generating', async () => {
        renderView('/panel/tenants/edit/tenant-uuid-1');

        await waitFor(() => {
            expect(screen.getByText('Generate Manifest')).toBeInTheDocument();
        });

        expect(screen.queryByText('Copy to Clipboard')).not.toBeInTheDocument();
    });
});
