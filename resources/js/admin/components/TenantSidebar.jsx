import React, { useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { buildTenantPath, tenantNavRoutes } from '../routes';
import { decodeToken } from '../../services/token';

const submenuItems = tenantNavRoutes.map((route) => ({
    id: route.id,
    label: route.label,
    path: (uuid) => buildTenantPath(route.path, uuid),
}));

export default function TenantSidebar({
    tenants,
    activeTenantUuid,
    onTenantSelect,
    onAddTenant,
    isLoading = false,
    error = null,
}) {
    const token = sessionStorage.getItem('admin_token');
    const decoded = decodeToken(token);
    const canCreateTenant = Boolean(decoded?.is_master || decoded?.level === 'master') && Boolean(onAddTenant);

    const navigate = useNavigate();
    const location = useLocation();
    const [dropdownOpen, setDropdownOpen] = useState(false);

    const selectedTenant = useMemo(
        () => tenants.find((t) => t.uuid === activeTenantUuid),
        [tenants, activeTenantUuid]
    );

    const activeSubmenuId = useMemo(() => {
        if (!activeTenantUuid) {
            return null;
        }

        const match = submenuItems.find((item) => item.path(activeTenantUuid) === location.pathname);
        return match?.id || null;
    }, [activeTenantUuid, location.pathname]);

    if (isLoading) {
        return (
            <>
                {/* Mobile */}
                <div className="md:hidden bg-white border-b border-gray-200 px-4 py-3">
                    <div className="text-gray-500 text-sm">Loading tenants...</div>
                </div>
                {/* Desktop */}
                <div className="hidden md:block w-64 bg-white border-r border-gray-200 h-full p-4">
                    <div className="text-gray-500 text-sm">Loading tenants...</div>
                </div>
            </>
        );
    }

    if (error) {
        return (
            <>
                {/* Mobile */}
                <div className="md:hidden bg-white border-b border-gray-200 px-4 py-3">
                    <div className="text-red-500 text-sm">{error}</div>
                </div>
                {/* Desktop */}
                <div className="hidden md:block w-64 bg-white border-r border-gray-200 h-full p-4">
                    <div className="text-red-500 text-sm">{error}</div>
                </div>
            </>
        );
    }

    return (
        <>
            {/* Mobile: Horizontal bar with dropdown */}
            <div className="md:hidden bg-white border-b border-gray-200 px-4 py-3">
                <div className="flex items-center justify-between gap-3">
                    <div className="relative flex-1">
                        <button
                            onClick={() => setDropdownOpen(!dropdownOpen)}
                            className="w-full px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-left text-sm flex items-center justify-between cursor-pointer"
                        >
                            <div className="flex-1 min-w-0">
                                <span className={selectedTenant ? 'text-gray-900' : 'text-gray-500'}>
                                    {selectedTenant ? selectedTenant.name : 'Select Tenant'}
                                </span>
                                {selectedTenant?.llm_provider && (
                                    <div className="text-xs text-gray-400 truncate">
                                        {selectedTenant.llm_provider.name} · {selectedTenant.llm_provider.model}
                                    </div>
                                )}
                            </div>
                            <svg
                                className={`w-4 h-4 text-gray-500 transition-transform flex-shrink-0 ml-2 ${dropdownOpen ? 'rotate-180' : ''}`}
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        {dropdownOpen && (
                            <div className="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-10 max-h-64 overflow-y-auto">
                                {tenants.map((tenant) => (
                                    <button
                                        key={tenant.uuid}
                                        onClick={() => {
                                            onTenantSelect?.(tenant.uuid);
                                            setDropdownOpen(false);
                                        }}
                                        className={`w-full text-left px-4 py-2 text-sm cursor-pointer ${
                                            activeTenantUuid === tenant.uuid
                                                ? 'bg-[#0A2E4D] text-white'
                                                : 'text-gray-700 hover:bg-gray-100'
                                        }`}
                                    >
                                        <div className="font-medium">{tenant.name}</div>
                                        {tenant.llm_provider && (
                                            <div className={`text-xs ${activeTenantUuid === tenant.uuid ? 'text-gray-300' : 'text-gray-400'}`}>
                                                {tenant.llm_provider.name} · {tenant.llm_provider.model}
                                            </div>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                    {canCreateTenant && (
                        <button
                            onClick={() => onAddTenant?.()}
                            className="px-3 py-2 bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white text-sm font-medium rounded-lg hover:shadow-lg transition-all cursor-pointer whitespace-nowrap"
                        >
                            + New
                        </button>
                    )}
                </div>
            </div>

            {/* Desktop: Sidebar */}
            <div className="hidden md:flex w-64 bg-white border-r border-gray-200 h-full flex-col">
                <div className="p-4 border-b border-gray-200">
                    <h2 className="text-sm font-semibold text-gray-600 uppercase tracking-wide">
                        Tenants
                    </h2>
                </div>

                <div className="flex-1 overflow-y-auto">
                    {tenants.length === 0 ? (
                        <div className="p-4 text-gray-500 text-sm">No tenants found</div>
                    ) : (
                        <ul className="py-2">
                            {tenants.map((tenant) => {
                                const isActive = activeTenantUuid === tenant.uuid;
                                const shouldHighlightMain = isActive && !activeSubmenuId;
                                return (
                                    <li key={tenant.uuid}>
                                        <button
                                            onClick={() => onTenantSelect?.(tenant.uuid)}
                                            className={`w-full text-left px-4 py-2 text-sm transition-colors cursor-pointer ${
                                                shouldHighlightMain
                                                    ? 'bg-[#0A2E4D] text-white'
                                                    : 'text-gray-700 hover:bg-gray-100'
                                            }`}
                                        >
                                            <div className="font-medium truncate">{tenant.name}</div>
                                            {tenant.llm_provider && (
                                                <div className={`text-xs truncate ${shouldHighlightMain ? 'text-gray-300' : 'text-gray-400'}`}>
                                                    {tenant.llm_provider.name} · {tenant.llm_provider.model}
                                                </div>
                                            )}
                                        </button>

                                        {isActive && (
                                            <ul className="bg-gray-50 border-l-2 border-[#4BBAC4] ml-4">
                                                {submenuItems.map((item) => (
                                                    <li key={item.id}>
                                                        <button
                                                            onClick={() => {
                                                                if (!activeTenantUuid) {
                                                                    return;
                                                                }
                                                                const target = item.path(activeTenantUuid);
                                                                if (target === '#') {
                                                                    return;
                                                                }
                                                                navigate(target);
                                                            }}
                                                            className={`w-full text-left px-4 py-2 text-sm transition-colors cursor-pointer ${
                                                                activeSubmenuId === item.id
                                                                    ? 'bg-[#0A2E4D] text-white'
                                                                    : 'text-gray-600 hover:bg-gray-100 hover:text-[#0A2E4D]'
                                                            }`}
                                                        >
                                                            {item.label}
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                {canCreateTenant && (
                    <div className="p-4 border-t border-gray-200">
                        <button
                            onClick={() => onAddTenant?.()}
                            className="w-full px-4 py-2 bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white text-sm font-medium rounded-lg hover:shadow-lg transition-all cursor-pointer"
                        >
                            + New Tenant
                        </button>
                    </div>
                )}
            </div>
        </>
    );
}
