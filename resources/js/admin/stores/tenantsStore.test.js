import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { useTenantsStore } from './tenantsStore';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

function resetStore() {
    useTenantsStore.setState({ tenants: [], isLoading: false, error: null });
}

describe('tenantsStore', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        resetStore();
    });

    // ─── reset ───────────────────────────────────────────────────────────────

    it('reset clears tenants, isLoading, and error', () => {
        useTenantsStore.setState({ tenants: [{ id: 1 }], isLoading: true, error: 'oops' });

        useTenantsStore.getState().reset();

        const { tenants, isLoading, error } = useTenantsStore.getState();
        expect(tenants).toEqual([]);
        expect(isLoading).toBe(false);
        expect(error).toBeNull();
    });

    // ─── fetchTenants ─────────────────────────────────────────────────────────

    it('fetchTenants sets tenants on success', async () => {
        axios.get.mockResolvedValue({ data: { data: [{ id: 1, name: 'Acme' }] } });

        await useTenantsStore.getState().fetchTenants();

        expect(useTenantsStore.getState().tenants).toEqual([{ id: 1, name: 'Acme' }]);
        expect(useTenantsStore.getState().isLoading).toBe(false);
        expect(useTenantsStore.getState().error).toBeNull();
    });

    it('fetchTenants sets error on failure', async () => {
        axios.get.mockRejectedValue(new Error('Network error'));

        await useTenantsStore.getState().fetchTenants();

        expect(useTenantsStore.getState().error).toBe('Failed to load tenants');
        expect(useTenantsStore.getState().isLoading).toBe(false);
    });

    it('fetchTenants skips re-fetch when tenants already loaded (memoization)', async () => {
        useTenantsStore.setState({ tenants: [{ id: 1 }] });

        await useTenantsStore.getState().fetchTenants();

        expect(axios.get).not.toHaveBeenCalled();
    });

    it('fetchTenants skips re-fetch when already loading', async () => {
        useTenantsStore.setState({ isLoading: true });

        await useTenantsStore.getState().fetchTenants();

        expect(axios.get).not.toHaveBeenCalled();
    });

    it('fetchTenants with force:true re-fetches even when tenants already loaded', async () => {
        useTenantsStore.setState({ tenants: [{ id: 1 }] });
        axios.get.mockResolvedValue({ data: { data: [{ id: 2, name: 'New' }] } });

        await useTenantsStore.getState().fetchTenants({ force: true });

        expect(axios.get).toHaveBeenCalledWith('/api/admin/tenants');
        expect(useTenantsStore.getState().tenants).toEqual([{ id: 2, name: 'New' }]);
    });

    it('fetchTenants handles response.data.data = undefined by defaulting to []', async () => {
        axios.get.mockResolvedValue({ data: {} });

        await useTenantsStore.getState().fetchTenants();

        expect(useTenantsStore.getState().tenants).toEqual([]);
    });

    // ─── upsertTenant ─────────────────────────────────────────────────────────

    it('upsertTenant inserts new tenant at the front of the list', () => {
        useTenantsStore.setState({ tenants: [{ id: 1, name: 'Acme' }] });

        useTenantsStore.getState().upsertTenant({ id: 2, name: 'Beta' });

        const { tenants } = useTenantsStore.getState();
        expect(tenants[0]).toEqual({ id: 2, name: 'Beta' });
        expect(tenants[1]).toEqual({ id: 1, name: 'Acme' });
    });

    it('upsertTenant updates existing tenant in place', () => {
        useTenantsStore.setState({
            tenants: [
                { id: 1, name: 'Acme' },
                { id: 2, name: 'Beta' },
            ],
        });

        useTenantsStore.getState().upsertTenant({ id: 1, name: 'Acme Updated' });

        const { tenants } = useTenantsStore.getState();
        expect(tenants).toHaveLength(2);
        expect(tenants[0]).toEqual({ id: 1, name: 'Acme Updated' });
        expect(tenants[1]).toEqual({ id: 2, name: 'Beta' });
    });

    it('upsertTenant does not duplicate when tenant already exists', () => {
        useTenantsStore.setState({ tenants: [{ id: 1, name: 'Acme' }] });

        useTenantsStore.getState().upsertTenant({ id: 1, name: 'Acme v2' });

        expect(useTenantsStore.getState().tenants).toHaveLength(1);
    });

    it('fetchTenants with force:true re-fetches even when isLoading is true', async () => {
        useTenantsStore.setState({ isLoading: true });
        axios.get.mockResolvedValue({ data: { data: [{ id: 3, name: 'Forced' }] } });

        await useTenantsStore.getState().fetchTenants({ force: true });

        expect(axios.get).toHaveBeenCalledWith('/api/admin/tenants');
        expect(useTenantsStore.getState().tenants).toEqual([{ id: 3, name: 'Forced' }]);
    });

    it('fetchTenants sets isLoading to true while fetching', async () => {
        let resolveGet;
        axios.get.mockImplementation(() => new Promise((resolve) => { resolveGet = resolve; }));

        const fetchPromise = useTenantsStore.getState().fetchTenants();

        expect(useTenantsStore.getState().isLoading).toBe(true);

        resolveGet({ data: { data: [] } });
        await fetchPromise;

        expect(useTenantsStore.getState().isLoading).toBe(false);
    });
});
