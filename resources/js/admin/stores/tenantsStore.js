import { create } from 'zustand';
import axios from 'axios';

export const useTenantsStore = create((set, get) => ({
    tenants: [],
    isLoading: false,
    error: null,
    reset: () => set({ tenants: [], isLoading: false, error: null }),
    fetchTenants: async ({ force = false } = {}) => {
        const { tenants, isLoading } = get();
        if (!force && (tenants.length > 0 || isLoading)) {
            return;
        }

        set({ isLoading: true, error: null });
        try {
            const response = await axios.get('/api/admin/tenants');
            set({ tenants: response.data.data || [], isLoading: false });
        } catch (err) {
            set({ error: 'Failed to load tenants', isLoading: false });
        }
    },
    upsertTenant: (tenant) =>
        set((state) => {
            const exists = state.tenants.some((item) => item.id === tenant.id);
            if (!exists) {
                return { tenants: [tenant, ...state.tenants] };
            }

            return {
                tenants: state.tenants.map((item) => (item.id === tenant.id ? tenant : item)),
            };
        }),
}));
