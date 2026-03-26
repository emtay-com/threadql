import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import axios from 'axios';
import TenantSettingsView from './TenantSettingsView';
import { ToastProvider } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

const mockTenants = [
    { id: 1, uuid: 'tenant-uuid-1', name: 'Tenant A' },
    { id: 2, uuid: 'tenant-uuid-2', name: 'Tenant B' },
];

const mockSettings = [
    {
        id: 1,
        tenant_id: 1,
        name: 'auto_approve_users',
        value: '1',
        created_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 2,
        tenant_id: 1,
        name: 'user_rate_limit',
        value: '5',
        created_at: '2026-01-01T00:00:00+00:00',
    },
    {
        id: 3,
        tenant_id: 1,
        name: 'table_scan_schedule',
        value: '02:00',
        created_at: '2026-01-01T00:00:00+00:00',
    },
];

function renderView(initialPath = '/panel/tenant-settings/tenant-uuid-1') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <ToastProvider>
                <Routes>
                    <Route path="/panel/tenant-settings/:tenantId" element={<TenantSettingsView />} />
                    <Route path="/panel/tenant-settings" element={<TenantSettingsView />} />
                </Routes>
            </ToastProvider>
        </MemoryRouter>
    );
}

describe('TenantSettingsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/settings')) {
                return Promise.resolve({ data: { data: mockSettings } });
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

    it('renders settings for selected tenant', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Auto Approve Users')).toBeInTheDocument();
            expect(screen.getByText('User Rate Limit')).toBeInTheDocument();
        });
    });

    it('renders boolean setting as a switch', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Auto Approve Users')).toBeInTheDocument();
        });

        const toggle = screen.getByRole('switch', { name: 'Auto Approve Users' });
        expect(toggle).toBeInTheDocument();
        expect(toggle).toHaveAttribute('aria-checked', 'true');
    });

    it('renders numeric setting as a number input', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('User Rate Limit')).toBeInTheDocument();
        });

        const input = screen.getByRole('spinbutton', { name: 'User Rate Limit' });
        expect(input).toBeInTheDocument();
        expect(input.value).toBe('5');
    });

    it('toggles boolean switch value', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Auto Approve Users')).toBeInTheDocument();
        });

        const toggle = screen.getByRole('switch', { name: 'Auto Approve Users' });
        expect(toggle).toHaveAttribute('aria-checked', 'true');

        fireEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-checked', 'false');
    });

    it('changes numeric input value', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('User Rate Limit')).toBeInTheDocument();
        });

        const input = screen.getByRole('spinbutton', { name: 'User Rate Limit' });
        fireEvent.change(input, { target: { value: '10' } });

        expect(input.value).toBe('10');
    });

    it('enables save button when settings are modified', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Auto Approve Users')).toBeInTheDocument();
        });

        const saveButton = screen.getByText('Save');
        expect(saveButton).toBeDisabled();

        const toggle = screen.getByRole('switch', { name: 'Auto Approve Users' });
        fireEvent.click(toggle);

        expect(saveButton).not.toBeDisabled();
    });

    it('sends PUT request on save', async () => {
        axios.put.mockResolvedValue({ status: 204 });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Auto Approve Users')).toBeInTheDocument();
        });

        const toggle = screen.getByRole('switch', { name: 'Auto Approve Users' });
        fireEvent.click(toggle);

        fireEvent.click(screen.getByText('Save'));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith(
                '/api/admin/tenants/1/settings',
                {
                    settings: [
                        { name: 'auto_approve_users', value: '0' },
                        { name: 'user_rate_limit', value: '5' },
                        { name: 'table_scan_schedule', value: '02:00' },
                    ],
                }
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

        renderView('/panel/tenant-settings');

        await waitFor(() => {
            expect(screen.getByText('Select a tenant to view settings.')).toBeInTheDocument();
        });
    });

    it('shows setting descriptions', async () => {
        renderView();

        await waitFor(() => {
            expect(
                screen.getByText('Automatically approve new Slack users when they first interact with the bot.')
            ).toBeInTheDocument();
            expect(
                screen.getByText('Maximum number of queries a user can make per minute.')
            ).toBeInTheDocument();
        });
    });

    it('renders time_schedule setting as two dropdowns', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Table Scan Schedule')).toBeInTheDocument();
        });

        const hourSelect = screen.getByLabelText('Table Scan Schedule hour');
        const minuteSelect = screen.getByLabelText('Table Scan Schedule minute');

        expect(hourSelect).toBeInTheDocument();
        expect(minuteSelect).toBeInTheDocument();
        expect(hourSelect.value).toBe('02');
        expect(minuteSelect.value).toBe('00');
    });

    it('changes time_schedule hour value', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Table Scan Schedule')).toBeInTheDocument();
        });

        const hourSelect = screen.getByLabelText('Table Scan Schedule hour');
        fireEvent.change(hourSelect, { target: { value: '14' } });

        expect(hourSelect.value).toBe('14');
    });

    it('changes time_schedule minute value', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Table Scan Schedule')).toBeInTheDocument();
        });

        const minuteSelect = screen.getByLabelText('Table Scan Schedule minute');
        fireEvent.change(minuteSelect, { target: { value: '30' } });

        expect(minuteSelect.value).toBe('30');
    });

    it('renders fallback_attempts setting with proper label and description', async () => {
        const settingsWithFallback = [
            ...mockSettings,
            {
                id: 4,
                tenant_id: 1,
                name: 'fallback_attempts',
                value: '3',
                created_at: '2026-01-01T00:00:00+00:00',
            },
        ];

        axios.get.mockImplementation((url) => {
            if (url.includes('/settings')) {
                return Promise.resolve({ data: { data: settingsWithFallback } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Fallback Attempts')).toBeInTheDocument();
            expect(
                screen.getByText('Number of retry attempts when the primary LLM call fails before giving up.')
            ).toBeInTheDocument();
        });

        const input = screen.getByRole('spinbutton', { name: 'Fallback Attempts' });
        expect(input).toBeInTheDocument();
        expect(input.value).toBe('3');
    });

    it('shows empty state when no settings', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/settings')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('No settings found.')).toBeInTheDocument();
        });
    });
});
