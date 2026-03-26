import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import axios from 'axios';
import LLMProvidersView from './LLMProvidersView';
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

const mockProviders = [
    {
        id: 10,
        name: 'OpenAI GPT-4',
        adapter: 'openai',
        url: 'https://api.openai.com/v1',
        model: 'gpt-4',
        has_api_key: true,
        options: { organization: 'org-123' },
        enabled: true,
        sort: 0,
    },
    {
        id: 11,
        name: 'Anthropic Claude',
        adapter: 'anthropic',
        url: 'https://api.anthropic.com',
        model: 'claude-3-sonnet',
        has_api_key: true,
        options: { version: '2023-06-01' },
        enabled: false,
        sort: 1,
    },
];

function renderView(initialPath = '/panel/llm-providers/tenant-uuid-1') {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <ToastProvider>
                <Routes>
                    <Route path="/panel/llm-providers/:tenantId" element={<LLMProvidersView />} />
                    <Route path="/panel/llm-providers" element={<LLMProvidersView />} />
                </Routes>
            </ToastProvider>
        </MemoryRouter>
    );
}

describe('LLMProvidersView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.setItem('activeTenant', 'tenant-uuid-1');

        useTenantsStore.setState({
            tenants: mockTenants,
            isLoading: false,
            error: null,
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/llm-providers')) {
                return Promise.resolve({ data: { data: mockProviders } });
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

    it('renders providers list for selected tenant', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
            expect(screen.getByText('Anthropic Claude')).toBeInTheDocument();
        });
    });

    it('shows adapter options from generated providerOptions', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        const adapterSelect = screen.getByRole('combobox');
        const optionValues = Array.from(adapterSelect.options).map((o) => o.value);

        expect(optionValues).toContain('openai');
        expect(optionValues).toContain('anthropic');
        expect(optionValues).toContain('ollama');
        expect(optionValues).toContain('gemini');
        expect(optionValues).toContain('deepseek');
    });

    it('shows provider options section when adapter with options is selected', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        const adapterSelect = screen.getByRole('combobox');
        fireEvent.change(adapterSelect, { target: { value: 'openai' } });

        await waitFor(() => {
            expect(screen.getByText('Provider Options')).toBeInTheDocument();
            expect(screen.getByText(/organization/)).toBeInTheDocument();
            expect(screen.getByText(/project/)).toBeInTheDocument();
        });
    });

    it('shows anthropic-specific options when anthropic is selected', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        const adapterSelect = screen.getByRole('combobox');
        fireEvent.change(adapterSelect, { target: { value: 'anthropic' } });

        await waitFor(() => {
            expect(screen.getByText('Provider Options')).toBeInTheDocument();
            expect(screen.getByText(/version/)).toBeInTheDocument();
            expect(screen.getByText(/default_thinking_budget/)).toBeInTheDocument();
        });
    });

    it('does not show provider options for ollama', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        const adapterSelect = screen.getByRole('combobox');
        fireEvent.change(adapterSelect, { target: { value: 'ollama' } });

        expect(screen.queryByText('Provider Options')).not.toBeInTheDocument();
    });

    it('sends options when creating a provider', async () => {
        axios.post.mockResolvedValue({
            data: {
                data: {
                    id: 99,
                    name: 'New OpenAI',
                    adapter: 'openai',
                    url: 'https://api.openai.com/v1',
                    model: 'gpt-4',
                    has_api_key: true,
                    options: { organization: 'my-org' },
                    enabled: true,
                    sort: 2,
                },
            },
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        fireEvent.change(screen.getByPlaceholderText('e.g. OpenAI GPT-4'), {
            target: { value: 'New OpenAI' },
        });
        fireEvent.change(screen.getByRole('combobox'), {
            target: { value: 'openai' },
        });
        fireEvent.change(screen.getByPlaceholderText('e.g. gpt-4'), {
            target: { value: 'gpt-4' },
        });

        await waitFor(() => {
            expect(screen.getByText('Provider Options')).toBeInTheDocument();
        });

        const orgInput = screen.getByPlaceholderText('OpenAI organization ID');
        fireEvent.change(orgInput, { target: { value: 'my-org' } });

        fireEvent.click(screen.getByText('Save'));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                expect.stringContaining('/llm-providers'),
                expect.objectContaining({
                    name: 'New OpenAI',
                    adapter: 'openai',
                    options: { organization: 'my-org' },
                })
            );
        });
    });

    it('populates options when editing a provider', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        await waitFor(() => {
            expect(screen.getByText('Provider Options')).toBeInTheDocument();
        });

        const orgInput = screen.getByPlaceholderText('OpenAI organization ID');
        expect(orgInput.value).toBe('org-123');
    });

    it('displays the add form toggle', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('+ Add')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));
        expect(screen.getByText('Cancel')).toBeInTheDocument();
    });

    it('shows empty state when no providers', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/llm-providers')) {
                return Promise.resolve({ data: { data: [] } });
            }
            return Promise.resolve({ data: { data: mockTenants } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('No LLM providers configured.')).toBeInTheDocument();
        });
    });

    it('shows test button in edit form but not in overview', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        // No test button on overview
        expect(screen.queryByLabelText('Test connection')).not.toBeInTheDocument();

        // Click edit to open form
        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        await waitFor(() => {
            expect(screen.getByLabelText('Test connection')).toBeInTheDocument();
        });
    });

    it('does not show test button in add form', async () => {
        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        expect(screen.queryByLabelText('Test connection')).not.toBeInTheDocument();
    });

    it('pings provider on test button click in edit form and shows success toast', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/ping')) {
                return Promise.resolve({ data: { data: { connected: true } } });
            }
            if (url.includes('/llm-providers')) {
                return Promise.resolve({ data: { data: mockProviders } });
            }
            return Promise.resolve({ data: { data: [] } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        await waitFor(() => {
            expect(screen.getByLabelText('Test connection')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByLabelText('Test connection'));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                expect.stringContaining('/llm-providers/10/ping')
            );
        });

        await waitFor(() => {
            expect(screen.getByText('Connection successful')).toBeInTheDocument();
        });
    });

    it('shows error toast when ping fails in edit form', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('/ping')) {
                return Promise.reject({
                    response: { data: { data: { connected: false, error: 'Invalid API key' } } },
                });
            }
            if (url.includes('/llm-providers')) {
                return Promise.resolve({ data: { data: mockProviders } });
            }
            return Promise.resolve({ data: { data: [] } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        await waitFor(() => {
            expect(screen.getByLabelText('Test connection')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByLabelText('Test connection'));

        await waitFor(() => {
            expect(screen.getByText('Invalid API key')).toBeInTheDocument();
        });
    });

    it('shows Testing... text while ping is in progress', async () => {
        let resolvePing;
        axios.get.mockImplementation((url) => {
            if (url.includes('/ping')) {
                return new Promise((resolve) => {
                    resolvePing = resolve;
                });
            }
            if (url.includes('/llm-providers')) {
                return Promise.resolve({ data: { data: mockProviders } });
            }
            return Promise.resolve({ data: { data: [] } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        await waitFor(() => {
            expect(screen.getByLabelText('Test connection')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByLabelText('Test connection'));

        await waitFor(() => {
            expect(screen.getByText('Testing...')).toBeInTheDocument();
        });

        resolvePing({ data: { data: { connected: true } } });

        await waitFor(() => {
            expect(screen.queryByText('Testing...')).not.toBeInTheDocument();
        });
    });

    it('pings provider after creating a new one', async () => {
        axios.post.mockResolvedValue({
            data: {
                data: {
                    id: 99,
                    name: 'New Provider',
                    adapter: 'openai',
                    url: 'https://api.openai.com/v1',
                    model: 'gpt-4',
                    has_api_key: true,
                    options: null,
                    enabled: true,
                    sort: 2,
                },
            },
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/ping')) {
                return Promise.resolve({ data: { data: { connected: true } } });
            }
            if (url.includes('/llm-providers')) {
                return Promise.resolve({ data: { data: mockProviders } });
            }
            return Promise.resolve({ data: { data: [] } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('+ Add'));

        fireEvent.change(screen.getByPlaceholderText('e.g. OpenAI GPT-4'), {
            target: { value: 'New Provider' },
        });
        fireEvent.change(screen.getByRole('combobox'), {
            target: { value: 'openai' },
        });
        fireEvent.change(screen.getByPlaceholderText('e.g. gpt-4'), {
            target: { value: 'gpt-4' },
        });

        fireEvent.click(screen.getByText('Save'));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                expect.stringContaining('/llm-providers/99/ping')
            );
        });
    });

    it('pings provider after updating', async () => {
        axios.put.mockResolvedValue({
            data: {
                data: {
                    ...mockProviders[0],
                    name: 'Updated Name',
                },
            },
        });

        axios.get.mockImplementation((url) => {
            if (url.includes('/ping')) {
                return Promise.resolve({ data: { data: { connected: true } } });
            }
            if (url.includes('/llm-providers')) {
                return Promise.resolve({ data: { data: mockProviders } });
            }
            return Promise.resolve({ data: { data: [] } });
        });

        renderView();

        await waitFor(() => {
            expect(screen.getByText('OpenAI GPT-4')).toBeInTheDocument();
        });

        const editButtons = screen.getAllByLabelText('Edit');
        fireEvent.click(editButtons[0]);

        await waitFor(() => {
            expect(screen.getByDisplayValue('OpenAI GPT-4')).toBeInTheDocument();
        });

        fireEvent.change(screen.getByDisplayValue('OpenAI GPT-4'), {
            target: { value: 'Updated Name' },
        });

        fireEvent.click(screen.getByText('Save'));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                expect.stringContaining('/llm-providers/10/ping')
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

        renderView('/panel/llm-providers');

        await waitFor(() => {
            expect(screen.getByText('Select a tenant to view LLM providers.')).toBeInTheDocument();
        });
    });
});
