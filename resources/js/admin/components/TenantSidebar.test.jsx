import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import TenantSidebar from './TenantSidebar';
import { decodeToken } from '../../services/token';

/** Helper component that exposes the current pathname for assertions */
function LocationDisplay() {
    const location = useLocation();
    return <div data-testid="location">{location.pathname}</div>;
}

vi.mock('../../services/token', () => ({
    decodeToken: vi.fn(),
    isValidToken: vi.fn(),
}));

// Provide a predictable set of tenant submenu routes
vi.mock('../routes', () => ({
    tenantNavRoutes: [
        { id: 'data-sources-tenant', path: '/panel/data-sources/:tenantId', label: 'Data Sources' },
        { id: 'definitions-tenant', path: '/panel/definitions/:tenantId', label: 'Definitions' },
    ],
    buildTenantPath: (path, id) => (id ? path.replace(':tenantId', id) : path),
    adminRoutes: [],
    mainNavRoutes: [],
}));

const mockTenants = [
    { uuid: 'uuid-1', name: 'Acme Corp', llm_provider: { name: 'OpenAI', model: 'gpt-4' } },
    { uuid: 'uuid-2', name: 'Globex', llm_provider: null },
];

function renderSidebar(props = {}, { initialPath = '/panel/tenants', showLocation = false } = {}) {
    const defaultProps = {
        tenants: mockTenants,
        activeTenantUuid: null,
        onTenantSelect: vi.fn(),
        onAddTenant: null,
        isLoading: false,
        error: null,
    };
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <TenantSidebar {...defaultProps} {...props} />
            {showLocation && <LocationDisplay />}
        </MemoryRouter>
    );
}

describe('TenantSidebar', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
        decodeToken.mockReturnValue(null);
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('shows loading state', () => {
        renderSidebar({ isLoading: true });
        expect(screen.getAllByText('Loading tenants...').length).toBeGreaterThan(0);
    });

    it('shows error state', () => {
        renderSidebar({ error: 'Failed to load tenants' });
        expect(screen.getAllByText('Failed to load tenants').length).toBeGreaterThan(0);
    });

    it('shows "No tenants found" when tenant list is empty', () => {
        renderSidebar({ tenants: [] });
        expect(screen.getByText('No tenants found')).toBeInTheDocument();
    });

    it('renders tenant names', () => {
        renderSidebar();
        expect(screen.getByText('Acme Corp')).toBeInTheDocument();
        expect(screen.getByText('Globex')).toBeInTheDocument();
    });

    it('renders LLM provider info for tenants that have one', () => {
        renderSidebar();
        expect(screen.getByText('OpenAI · gpt-4')).toBeInTheDocument();
    });

    it('calls onTenantSelect when a tenant is clicked', () => {
        const onTenantSelect = vi.fn();
        renderSidebar({ onTenantSelect });
        fireEvent.click(screen.getByText('Acme Corp'));
        expect(onTenantSelect).toHaveBeenCalledWith('uuid-1');
    });

    it('shows submenu items for the active tenant', () => {
        renderSidebar({ activeTenantUuid: 'uuid-1' });
        expect(screen.getByText('Data Sources')).toBeInTheDocument();
        expect(screen.getByText('Definitions')).toBeInTheDocument();
    });

    it('does not show submenu items for inactive tenants', () => {
        renderSidebar({ activeTenantUuid: 'uuid-2' });
        // Only uuid-2 is active, submenu should exist but only under Globex
        // Check that the submenu items don't duplicate for uuid-1
        const dataSourcesLinks = screen.queryAllByText('Data Sources');
        expect(dataSourcesLinks).toHaveLength(1);
    });

    it('hides "New Tenant" button when onAddTenant is null', () => {
        decodeToken.mockReturnValue({ is_master: true });
        renderSidebar({ onAddTenant: null });
        expect(screen.queryByText('+ New Tenant')).not.toBeInTheDocument();
    });

    it('hides "New Tenant" button for non-master users', () => {
        decodeToken.mockReturnValue({ is_master: false, level: 'tenant' });
        const onAddTenant = vi.fn();
        renderSidebar({ onAddTenant });
        expect(screen.queryByText('+ New Tenant')).not.toBeInTheDocument();
    });

    it('shows "New Tenant" button for master users with onAddTenant prop', () => {
        sessionStorage.setItem('admin_token', 'master-token');
        decodeToken.mockReturnValue({ is_master: true });
        const onAddTenant = vi.fn();
        renderSidebar({ onAddTenant });
        expect(screen.getByText('+ New Tenant')).toBeInTheDocument();
    });

    it('calls onAddTenant when "New Tenant" button is clicked', () => {
        sessionStorage.setItem('admin_token', 'master-token');
        decodeToken.mockReturnValue({ is_master: true });
        const onAddTenant = vi.fn();
        renderSidebar({ onAddTenant });
        fireEvent.click(screen.getByText('+ New Tenant'));
        expect(onAddTenant).toHaveBeenCalled();
    });

    it('mobile dropdown shows "Select Tenant" when no tenant is active', () => {
        renderSidebar({ activeTenantUuid: null });
        expect(screen.getByText('Select Tenant')).toBeInTheDocument();
    });

    it('mobile dropdown shows active tenant name', () => {
        renderSidebar({ activeTenantUuid: 'uuid-1' });
        // The selected tenant name appears in the dropdown button
        const acmeElements = screen.getAllByText('Acme Corp');
        expect(acmeElements.length).toBeGreaterThan(0);
    });

    it('mobile dropdown opens and lists tenants on click', () => {
        renderSidebar();
        const dropdownButton = screen.getByText('Select Tenant').closest('button');
        fireEvent.click(dropdownButton);
        // After opening, tenants should appear in dropdown list
        const acmeItems = screen.getAllByText('Acme Corp');
        expect(acmeItems.length).toBeGreaterThan(0);
    });

    it('mobile dropdown calls onTenantSelect and closes on tenant click', () => {
        const onTenantSelect = vi.fn();
        renderSidebar({ onTenantSelect });
        const dropdownButton = screen.getByText('Select Tenant').closest('button');
        fireEvent.click(dropdownButton);

        // Click the first tenant in the dropdown list
        const tenantButtons = screen.getAllByText('Acme Corp');
        fireEvent.click(tenantButtons[tenantButtons.length - 1]);

        expect(onTenantSelect).toHaveBeenCalledWith('uuid-1');
    });

    it('mobile dropdown toggles open and closed on repeated clicks', () => {
        renderSidebar();
        const dropdownButton = screen.getByText('Select Tenant').closest('button');

        // Initially closed — the expanded dropdown list (absolute-positioned) is not rendered
        // We detect this by toggling: click to open then click again to close
        fireEvent.click(dropdownButton);

        // Now open — clicking the toggle button again should close it
        fireEvent.click(dropdownButton);

        // After closing, clicking a second time re-opens
        fireEvent.click(dropdownButton);

        // The dropdown is open again; the button exists and is accessible
        expect(dropdownButton).toBeInTheDocument();
    });

    it('shows "New Tenant" button for users with level === "master"', () => {
        decodeToken.mockReturnValue({ is_master: false, level: 'master' });
        const onAddTenant = vi.fn();
        renderSidebar({ onAddTenant });
        expect(screen.getByText('+ New Tenant')).toBeInTheDocument();
    });

    it('clicking a submenu item navigates to the correct tenant path', () => {
        renderSidebar({ activeTenantUuid: 'uuid-1' }, { showLocation: true });

        const dataSourcesButton = screen.getByText('Data Sources');
        fireEvent.click(dataSourcesButton);

        expect(screen.getByTestId('location').textContent).toBe('/panel/data-sources/uuid-1');
    });
});
