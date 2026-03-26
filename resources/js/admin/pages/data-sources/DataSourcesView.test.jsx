import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import axios from 'axios';
import DataSourcesView from './DataSourcesView';
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

// TenantSidebar uses decodeToken
vi.mock('../../services/token', () => ({
    decodeToken: vi.fn(() => null),
    isValidToken: vi.fn(() => false),
}));

// Stub out the large timezone list
vi.mock('../../../constants/timezones', () => ({
    timezones: [
        { value: 'UTC', label: 'UTC+00:00 UTC', offset: 0 },
        { value: 'America/New_York', label: 'UTC-05:00 America/New_York', offset: -5 },
    ],
}));

const mockTenants = [
    { id: 1, uuid: 'tenant-uuid-1', name: 'Acme Corp' },
];

const mockDatasource = {
    id: 10,
    tenant_id: 1,
    label: 'Primary DB',
    has_dsn: true,
    default_limit: 100,
    query_timeout_seconds: 30,
    timezone: 'UTC',
    use_ssh: false,
    ssh_host: null,
    ssh_port: null,
    ssh_username: null,
    has_ssh_password: false,
    has_ssh_private_key: false,
    created_at: '2026-01-01T00:00:00+00:00',
    updated_at: '2026-01-15T00:00:00+00:00',
};

function renderView(initialPath = '/panel/data-sources/tenant-uuid-1') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <ToastProvider>
                <Routes>
                    <Route path="/panel/data-sources/:tenantId" element={<DataSourcesView />} />
                    <Route path="/panel/data-sources" element={<DataSourcesView />} />
                </Routes>
            </ToastProvider>
        </MemoryRouter>
    );
}

describe('DataSourcesView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
            fetchTenants: vi.fn(),
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [mockDatasource] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('shows "Select a tenant" when no tenant is selected', async () => {
        localStorage.removeItem('activeTenant');
        useTenantsStore.setState({ tenants: mockTenants, isLoading: false, error: null });

        renderView('/panel/data-sources');

        await waitFor(() => {
            expect(screen.getByText('Select a tenant to manage data sources.')).toBeInTheDocument();
        });
    });

    it('renders datasource label when datasource exists', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Primary DB')).toBeInTheDocument();
        });
    });

    it('shows "Configured" badge when DSN is set', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Configured')).toBeInTheDocument();
        });
    });

    it('shows "Not set" badge when DSN is not configured', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [{ ...mockDatasource, has_dsn: false }] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Not set')).toBeInTheDocument();
        });
    });

    it('shows datasource metadata (limit, timeout, timezone)', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('100')).toBeInTheDocument();
            expect(screen.getByText('30')).toBeInTheDocument();
            expect(screen.getByText('UTC')).toBeInTheDocument();
        });
    });

    it('shows SSH tunnel as "Disabled" when not configured', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Disabled')).toBeInTheDocument();
        });
    });

    it('shows SSH tunnel as "Enabled" when configured', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({
                    data: {
                        data: [{
                            ...mockDatasource,
                            use_ssh: true,
                            ssh_host: 'bastion.example.com',
                            ssh_port: 22,
                            ssh_username: 'ec2-user',
                        }],
                    },
                });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Enabled')).toBeInTheDocument();
            expect(screen.getByText('ec2-user@bastion.example.com:22')).toBeInTheDocument();
        });
    });

    it('shows Test button when DSN is configured', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Test' })).toBeInTheDocument();
        });
    });

    it('calls ping endpoint when Test button is clicked', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/ping')) {
                return Promise.resolve({ data: {} });
            }
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [mockDatasource] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Test' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Test' }));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                '/api/admin/tenants/1/datasources/10/ping'
            );
        });
    });

    it('switches to edit mode when Edit button is clicked', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

        await waitFor(() => {
            expect(screen.getByText('Edit Data Source')).toBeInTheDocument();
        });
    });

    it('shows add form automatically when there are no datasources', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Add Data Source')).toBeInTheDocument();
        });
    });

    it('disables save button when label is empty in add form', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Add Data Source')).toBeInTheDocument();
        });

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('tests connection before saving a new datasource', async () => {
        axios.post.mockImplementation((url) => {
            if (url.includes('test-connection')) {
                return Promise.resolve({ data: {} });
            }
            return Promise.resolve({ data: { data: { ...mockDatasource, id: 11 } } });
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Add Data Source')).toBeInTheDocument();
        });

        // Fill out required fields (protocol defaults to 'mysql', skip it)
        fireEvent.change(screen.getByPlaceholderText('e.g., Primary Warehouse'), { target: { value: 'New DB' } });

        // Protocol is now a select (defaults to 'mysql'), host has placeholder "mysql"
        fireEvent.change(screen.getByPlaceholderText('db.example.com'), { target: { value: 'db.example.com' } });

        fireEvent.change(screen.getByPlaceholderText('root'), { target: { value: 'root' } });
        fireEvent.change(screen.getByPlaceholderText('••••••'), { target: { value: 'password' } });
        fireEvent.change(screen.getByPlaceholderText('3306'), { target: { value: '3306' } });
        fireEvent.change(screen.getByPlaceholderText('demo_db'), { target: { value: 'mydb' } });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/api/admin/tenants/1/datasources/test-connection',
                expect.objectContaining({ driver: 'mysql', host: 'db.example.com', database: 'mydb' })
            );
        });
    });

    it('shows connection failure error when test-connection fails', async () => {
        axios.post.mockImplementation((url) => {
            if (url.includes('test-connection')) {
                return Promise.reject({
                    response: { data: { data: { error: 'Connection refused' } } },
                });
            }
            return Promise.resolve({ data: {} });
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Add Data Source')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Primary Warehouse'), { target: { value: 'New DB' } });
        // Protocol is now a select (defaults to 'mysql'), host has placeholder "mysql"
        fireEvent.change(screen.getByPlaceholderText('db.example.com'), { target: { value: 'db.example.com' } });
        fireEvent.change(screen.getByPlaceholderText('root'), { target: { value: 'root' } });
        fireEvent.change(screen.getByPlaceholderText('••••••'), { target: { value: 'password' } });
        fireEvent.change(screen.getByPlaceholderText('3306'), { target: { value: '3306' } });
        fireEvent.change(screen.getByPlaceholderText('demo_db'), { target: { value: 'mydb' } });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('Connection failed: Connection refused')).toBeInTheDocument();
        });
    });

    it('saves an edit (no DSN) via PUT', async () => {
        axios.put.mockResolvedValue({ data: { data: { ...mockDatasource, label: 'Updated DB', default_limit: 200 } } });

        renderView();

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

        await waitFor(() => {
            expect(screen.getByText('Edit Data Source')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Primary Warehouse'), { target: { value: 'Updated DB' } });
        fireEvent.change(screen.getByPlaceholderText('100'), { target: { value: '200' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith(
                '/api/admin/tenants/1/datasources/10',
                expect.objectContaining({ label: 'Updated DB', default_limit: 200 })
            );
        });
    });

    it('cancel in edit mode returns to card view', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

        await waitFor(() => {
            expect(screen.getByText('Edit Data Source')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        await waitFor(() => {
            expect(screen.getByText('Primary DB')).toBeInTheDocument();
        });
    });

    it('shows error when datasources fail to load', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.reject(new Error('Network error'));
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            // Error appears in both mobile and desktop sidebar renderings
            const errors = screen.getAllByText('Failed to load data sources');
            expect(errors.length).toBeGreaterThan(0);
        });
    });

    it('shows "Testing connection..." button text while testing', async () => {
        // Test-connection hangs so we can inspect intermediate state
        axios.post.mockImplementation((url) => {
            if (url.includes('test-connection')) {
                return new Promise(() => {});
            }
            return Promise.resolve({ data: {} });
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Add Data Source')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Primary Warehouse'), { target: { value: 'New DB' } });
        fireEvent.change(screen.getByPlaceholderText('db.example.com'), { target: { value: 'db.example.com' } });
        fireEvent.change(screen.getByPlaceholderText('root'), { target: { value: 'root' } });
        fireEvent.change(screen.getByPlaceholderText('••••••'), { target: { value: 'password' } });
        fireEvent.change(screen.getByPlaceholderText('3306'), { target: { value: '3306' } });
        fireEvent.change(screen.getByPlaceholderText('demo_db'), { target: { value: 'mydb' } });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('Testing connection...')).toBeInTheDocument();
        });
    });

    it('shows error message when edit save fails', async () => {
        axios.put.mockRejectedValue({ message: 'Internal server error' });

        renderView();

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));

        await waitFor(() => {
            expect(screen.getByText('Edit Data Source')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Primary Warehouse'), { target: { value: 'Updated DB' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('Internal server error')).toBeInTheDocument();
        });
    });

    it('shows loading state while fetching datasources', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return new Promise(() => {});
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Loading data sources...')).toBeInTheDocument();
        });
    });

    it('creates a new datasource via POST after successful test-connection', async () => {
        const createdDatasource = { ...mockDatasource, id: 11, label: 'New DB' };

        axios.post.mockImplementation((url) => {
            if (url.includes('test-connection')) {
                return Promise.resolve({ data: {} });
            }
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: createdDatasource } });
            }
            return Promise.resolve({ data: {} });
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/datasources')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Add Data Source')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByPlaceholderText('e.g., Primary Warehouse'), { target: { value: 'New DB' } });
        fireEvent.change(screen.getByPlaceholderText('db.example.com'), { target: { value: 'db.example.com' } });
        fireEvent.change(screen.getByPlaceholderText('root'), { target: { value: 'root' } });
        fireEvent.change(screen.getByPlaceholderText('••••••'), { target: { value: 'password' } });
        fireEvent.change(screen.getByPlaceholderText('3306'), { target: { value: '3306' } });
        fireEvent.change(screen.getByPlaceholderText('demo_db'), { target: { value: 'mydb' } });

        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/api/admin/tenants/1/datasources',
                expect.objectContaining({ label: 'New DB', host: 'db.example.com', database: 'mydb' })
            );
            expect(screen.getByText('New DB')).toBeInTheDocument();
        });
    });
});
