import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import axios from 'axios';
import DefinitionsView from './DefinitionsView';
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

// TenantSidebar uses decodeToken; provide a no-op mock
vi.mock('../../services/token', () => ({
    decodeToken: vi.fn(() => null),
    isValidToken: vi.fn(() => false),
}));

const mockTenants = [
    { id: 1, uuid: 'tenant-uuid-1', name: 'Acme Corp' },
    { id: 2, uuid: 'tenant-uuid-2', name: 'Globex' },
];

const mockDefinitions = [
    { id: 1, tenant_id: 1, subject: 'ARR', definition: 'Annual Recurring Revenue' },
    { id: 2, tenant_id: 1, subject: 'MRR', definition: 'Monthly Recurring Revenue' },
];

function renderView(initialPath = '/panel/definitions/tenant-uuid-1') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <ToastProvider>
                <Routes>
                    <Route path="/panel/definitions/:tenantId" element={<DefinitionsView />} />
                    <Route path="/panel/definitions" element={<DefinitionsView />} />
                </Routes>
            </ToastProvider>
        </MemoryRouter>
    );
}

describe('DefinitionsView', () => {
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
            if (url.includes('/definitions')) {
                return Promise.resolve({ data: { data: mockDefinitions } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('shows "Select a tenant" message when no tenant is selected', async () => {
        localStorage.removeItem('activeTenant');
        useTenantsStore.setState({ tenants: mockTenants, isLoading: false, error: null });

        renderView('/panel/definitions');

        await waitFor(() => {
            expect(screen.getByText('Select a tenant to view definitions.')).toBeInTheDocument();
        });
    });

    it('renders definitions for the selected tenant', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
            expect(screen.getByText('Annual Recurring Revenue')).toBeInTheDocument();
            expect(screen.getByText('MRR')).toBeInTheDocument();
        });
    });

    it('shows total definitions count', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('2 total')).toBeInTheDocument();
        });
    });

    it('shows "No definitions found" when list is empty', async () => {
        axios.get.mockImplementation(() => Promise.resolve({ data: { data: [] } }));

        renderView();

        await waitFor(() => {
            expect(screen.getByText('No definitions found.')).toBeInTheDocument();
        });
    });

    it('shows add form when "+ Add" button is clicked', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        expect(screen.getByPlaceholderText('e.g. ARR')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Describe the definition...')).toBeInTheDocument();
    });

    it('toggles add form closed when "Cancel" is clicked', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));
        expect(screen.getByPlaceholderText('e.g. ARR')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Cancel'));
        expect(screen.queryByPlaceholderText('e.g. ARR')).not.toBeInTheDocument();
    });

    it('creates a definition via POST and adds it to the list', async () => {
        const newDefinition = { id: 3, tenant_id: 1, subject: 'CAC', definition: 'Customer Acquisition Cost' };
        axios.post.mockResolvedValue({ data: { data: newDefinition } });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));
        fireEvent.change(screen.getByPlaceholderText('e.g. ARR'), { target: { value: 'CAC' } });
        fireEvent.change(screen.getByPlaceholderText('Describe the definition...'), {
            target: { value: 'Customer Acquisition Cost' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/api/admin/tenants/1/definitions',
                { subject: 'CAC', definition: 'Customer Acquisition Cost' }
            );
            expect(screen.getByText('CAC')).toBeInTheDocument();
        });
    });

    it('shows validation error when trying to add with empty fields', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('Subject and definition are required.')).toBeInTheDocument();
        });
    });

    it('enters edit mode when Edit button is clicked', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        // Should render a textarea for editing
        const textarea = screen.getByDisplayValue('Annual Recurring Revenue');
        expect(textarea.tagName.toLowerCase()).toBe('textarea');
    });

    it('saves an edited definition via PUT', async () => {
        axios.put.mockResolvedValue({ status: 200 });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        const textarea = screen.getByDisplayValue('Annual Recurring Revenue');
        fireEvent.change(textarea, { target: { value: 'Annual Recurring Revenue (ARR)' } });

        fireEvent.click(screen.getByLabelText('Save'));

        await waitFor(() => {
            expect(axios.put).toHaveBeenCalledWith(
                '/api/admin/tenants/1/definitions/1',
                { definition: 'Annual Recurring Revenue (ARR)' }
            );
        });
    });

    it('skips PUT request when definition is unchanged', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        // Click save without changing anything
        fireEvent.click(screen.getByLabelText('Save'));

        await waitFor(() => {
            expect(axios.put).not.toHaveBeenCalled();
        });
    });

    it('prevents saving an empty definition', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        const textarea = screen.getByDisplayValue('Annual Recurring Revenue');
        fireEvent.change(textarea, { target: { value: '' } });

        // Save button should be disabled when textarea is empty
        expect(screen.getByLabelText('Save')).toBeDisabled();
    });

    it('deletes a definition via DELETE request', async () => {
        axios.delete.mockResolvedValue({ status: 204 });
        window.confirm = vi.fn(() => true);

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const deleteButtons = screen.getAllByLabelText('Delete');
        fireEvent.click(deleteButtons[0]);

        await waitFor(() => {
            expect(axios.delete).toHaveBeenCalledWith('/api/admin/tenants/1/definitions/1');
        });
    });

    it('does not delete when user cancels confirm dialog', async () => {
        window.confirm = vi.fn(() => false);

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const deleteButtons = screen.getAllByLabelText('Delete');
        fireEvent.click(deleteButtons[0]);

        expect(axios.delete).not.toHaveBeenCalled();
    });

    it('removes deleted definition from the list', async () => {
        axios.delete.mockResolvedValue({ status: 204 });
        window.confirm = vi.fn(() => true);

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const deleteButtons = screen.getAllByLabelText('Delete');
        fireEvent.click(deleteButtons[0]);

        await waitFor(() => {
            expect(screen.queryByText('ARR')).not.toBeInTheDocument();
        });
    });

    it('shows error message when delete fails', async () => {
        axios.delete.mockRejectedValue(new Error('Server error'));
        window.confirm = vi.fn(() => true);

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const deleteButtons = screen.getAllByLabelText('Delete');
        fireEvent.click(deleteButtons[0]);

        await waitFor(() => {
            expect(screen.getByText('Failed to delete definition')).toBeInTheDocument();
        });
    });

    it('shows loading state while fetching definitions', async () => {
        // Never resolve so we can catch the loading state
        axios.get.mockImplementation((url) => {
            if (url.includes('/definitions')) {
                return new Promise(() => {});
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('Loading definitions...')).toBeInTheDocument();
        });
    });

    it('shows error when definitions fail to load', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/definitions')) {
                return Promise.reject(new Error('Network error'));
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            // Error appears in both mobile and desktop sidebar renderings
            const errors = screen.getAllByText('Failed to load definitions');
            expect(errors.length).toBeGreaterThan(0);
        });
    });

    it('shows error message when create fails', async () => {
        axios.post.mockRejectedValue(new Error('Server error'));

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));
        fireEvent.change(screen.getByPlaceholderText('e.g. ARR'), { target: { value: 'CAC' } });
        fireEvent.change(screen.getByPlaceholderText('Describe the definition...'), {
            target: { value: 'Customer Acquisition Cost' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            expect(screen.getByText('Failed to create definition')).toBeInTheDocument();
        });
    });

    it('shows error message when edit save fails', async () => {
        axios.put.mockRejectedValue(new Error('Server error'));

        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        const textarea = screen.getByDisplayValue('Annual Recurring Revenue');
        fireEvent.change(textarea, { target: { value: 'Annual Recurring Revenue (updated)' } });

        fireEvent.click(screen.getByLabelText('Save'));

        await waitFor(() => {
            expect(screen.getByText('Failed to save definition')).toBeInTheDocument();
        });
    });

    it('enters edit mode on double-click of the definition text', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('Annual Recurring Revenue')).toBeInTheDocument();
        });

        fireEvent.dblClick(screen.getByText('Annual Recurring Revenue'));

        expect(screen.getByDisplayValue('Annual Recurring Revenue').tagName.toLowerCase()).toBe('textarea');
    });

    it('delete button is disabled while editing a definition', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('ARR')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        // The delete button for the row being edited should be disabled
        const deleteButtons = screen.getAllByLabelText('Delete');
        expect(deleteButtons[0]).toBeDisabled();
    });
});
