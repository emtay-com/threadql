import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { useMatch, useNavigate } from 'react-router-dom';
import TenantSidebar from '../../components/TenantSidebar';
import { useToast } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';

const priorityBounds = { min: 0, max: 100 };

function Spinner() {
    return (
        <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
    );
}

export default function TablesView() {
    const { tenants, isLoading: isLoadingTenants, error: tenantsError, fetchTenants } = useTenantsStore();
    const [tables, setTables] = useState([]);
    const [datasource, setDatasource] = useState(null);
    const [activeTenantUuid, setActiveTenantUuid] = useState(() => localStorage.getItem('activeTenant') || null);
    const [isLoadingTables, setIsLoadingTables] = useState(false);
    const [isScanning, setIsScanning] = useState(false);
    const [tablesError, setTablesError] = useState(null);
    const [actionError, setActionError] = useState(null);
    const [editingTableId, setEditingTableId] = useState(null);
    const [priorityDraft, setPriorityDraft] = useState('');
    const [savingTableId, setSavingTableId] = useState(null);
    const [deletingTableId, setDeletingTableId] = useState(null);
    const [restoringTableId, setRestoringTableId] = useState(null);
    const navigate = useNavigate();
    const viewMatch = useMatch('/panel/tables/:tenantId');
    const { showToast } = useToast();

    useEffect(() => {
        fetchTenants();
    }, []);

    useEffect(() => {
        if (!viewMatch?.params?.tenantId) {
            return;
        }

        const uuid = viewMatch.params.tenantId;
        setActiveTenantUuid(uuid);
        localStorage.setItem('activeTenant', uuid);
    }, [viewMatch]);

    useEffect(() => {
        if (!activeTenantUuid || tenants.length === 0) {
            setTables([]);
            return;
        }

        const tenant = tenants.find((item) => item.uuid === activeTenantUuid);
        if (!tenant) {
            setTables([]);
            return;
        }

        fetchTables(tenant.id);
    }, [activeTenantUuid, tenants]);

    const fetchTables = async (tenantId) => {
        try {
            setIsLoadingTables(true);
            const [tablesRes, dsRes] = await Promise.all([
                axios.get(`/api/admin/tenants/${tenantId}/tables`),
                axios.get(`/api/admin/tenants/${tenantId}/datasources`),
            ]);
            setTables(tablesRes.data.data || []);
            const datasources = dsRes.data.data || [];
            setDatasource(datasources[0] || null);
            setTablesError(null);
        } catch (err) {
            setTablesError('Failed to load tables');
        } finally {
            setIsLoadingTables(false);
        }
    };

    const handleScan = async () => {
        if (!selectedTenant || !datasource || isScanning) {
            return;
        }

        try {
            setIsScanning(true);
            const response = await axios.post(
                `/api/admin/tenants/${selectedTenant.id}/datasources/${datasource.id}/scan`
            );
            setTables(response.data.data || []);
            showToast({ state: 'success', message: 'Scan completed' });
        } catch {
            showToast({ state: 'error', message: 'Scan failed' });
        } finally {
            setIsScanning(false);
        }
    };

    const handleTenantSelect = (uuid) => {
        if (!uuid) {
            return;
        }

        localStorage.setItem('activeTenant', uuid);
        setActiveTenantUuid(uuid);
        navigate(`/panel/tenants/${uuid}`);
    };

    const selectedTenant = useMemo(
        () => tenants.find((tenant) => tenant.uuid === activeTenantUuid) || null,
        [tenants, activeTenantUuid]
    );

    const isPriorityValid = (value) => {
        const parsed = Number(value);
        return Number.isInteger(parsed) && parsed >= priorityBounds.min && parsed <= priorityBounds.max;
    };

    const startEdit = (table) => {
        setEditingTableId(table.id);
        setPriorityDraft(String(table.priority ?? 0));
        setActionError(null);
    };

    const handleSave = async (table) => {
        if (!selectedTenant || savingTableId || !isPriorityValid(priorityDraft)) {
            setActionError(`Priority must be ${priorityBounds.min}-${priorityBounds.max}.`);
            return;
        }

        try {
            setSavingTableId(table.id);
            setActionError(null);
            const priority = Number(priorityDraft);
            await axios.put(`/api/admin/tenants/${selectedTenant.id}/tables/${table.id}`, { priority });
            setTables((prev) =>
                prev.map((item) => (item.id === table.id ? { ...item, priority } : item))
            );
            setEditingTableId(null);
            setPriorityDraft('');
            showToast({ state: 'success', message: 'Table updated' });
        } catch (err) {
            setActionError('Failed to save table priority');
        } finally {
            setSavingTableId(null);
        }
    };

    const handleDelete = async (table) => {
        if (!selectedTenant || deletingTableId) {
            return;
        }

        if (!window.confirm(`Delete ${table.name}?`)) {
            return;
        }

        try {
            setDeletingTableId(table.id);
            setActionError(null);
            await axios.delete(`/api/admin/tenants/${selectedTenant.id}/tables/${table.id}`);
            setTables((prev) =>
                prev.map((item) =>
                    item.id === table.id ? { ...item, deleted_at: new Date().toISOString() } : item
                )
            );
            if (editingTableId === table.id) {
                setEditingTableId(null);
                setPriorityDraft('');
            }
            showToast({ state: 'info', message: 'Table deleted' });
        } catch (err) {
            setActionError('Failed to delete table');
        } finally {
            setDeletingTableId(null);
        }
    };

    const handleRestore = async (table) => {
        if (!selectedTenant || restoringTableId) {
            return;
        }

        try {
            setRestoringTableId(table.id);
            setActionError(null);
            await axios.patch(`/api/admin/tenants/${selectedTenant.id}/tables/${table.id}`);
            setTables((prev) =>
                prev.map((item) => (item.id === table.id ? { ...item, deleted_at: null } : item))
            );
        } catch (err) {
            setActionError('Failed to restore table');
        } finally {
            setRestoringTableId(null);
        }
    };

    const isLoading = isLoadingTenants || isLoadingTables;
    const sidebarError = tenantsError || tablesError;

    return (
        <div className="flex flex-col md:flex-row flex-1 overflow-hidden">
            <TenantSidebar
                tenants={tenants}
                activeTenantUuid={activeTenantUuid}
                onTenantSelect={handleTenantSelect}
                onAddTenant={() => navigate('/panel/tenants/edit/add')}
                isLoading={isLoadingTenants}
                error={sidebarError}
            />
            <div className="flex-1 p-5 md:p-8 overflow-y-auto flex justify-center">
                {!selectedTenant ? (
                    <div className="text-gray-500">Select a tenant to view tables.</div>
                ) : isLoading ? (
                    <div className="text-gray-500">Loading tables...</div>
                ) : tables.length === 0 ? (
                    <div className="text-gray-500">
                        No tables yet.{' '}
                        {datasource ? (
                            <button
                                type="button"
                                onClick={handleScan}
                                disabled={isScanning}
                                className="text-[#4BBAC4] hover:text-[#0A2E4D] underline cursor-pointer disabled:cursor-not-allowed disabled:opacity-60 inline-flex items-center gap-1"
                            >
                                {isScanning && <Spinner />}
                                {isScanning ? 'Scanning...' : 'Scan the datasource to discover tables.'}
                            </button>
                        ) : (
                            <span>Configure a datasource first.</span>
                        )}
                    </div>
                ) : (
                    <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-[#0A2E4D]">Tables</h3>
                                <p className="text-xs text-gray-500">{tables.length} total</p>
                            </div>
                            {datasource && (
                                <button
                                    type="button"
                                    onClick={handleScan}
                                    disabled={isScanning}
                                    className={`px-4 py-2 text-sm font-medium rounded-lg transition-all cursor-pointer border ${
                                        isScanning
                                            ? 'border-gray-300 text-gray-400 cursor-not-allowed'
                                            : 'border-[#4BBAC4] text-[#0A2E4D] hover:bg-[#4BBAC4]/10'
                                    }`}
                                >
                                    {isScanning ? (
                                        <span className="inline-flex items-center gap-2">
                                            <Spinner />
                                            Scanning...
                                        </span>
                                    ) : (
                                        'Rescan'
                                    )}
                                </button>
                            )}
                        </div>
                        <ul className="divide-y divide-gray-100">
                            {tables.map((table) => {
                                const isEditing = editingTableId === table.id;
                                const isSaving = savingTableId === table.id;
                                const isDeleting = deletingTableId === table.id;
                                const isRestoring = restoringTableId === table.id;
                                const isDeleted = Boolean(table.deleted_at);
                                return (
                                    <li
                                        key={table.id}
                                        className={`px-6 py-4 grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] items-center gap-4 ${
                                            isDeleted ? 'opacity-50' : ''
                                        }`}
                                    >
                                        <div className="min-w-0">
                                            <div className="font-medium text-gray-900 truncate">{table.name}</div>
                                        </div>
                                        <div className="text-sm text-gray-600 sm:text-center">
                                            <span className="text-xs uppercase tracking-wide text-gray-500">Priority</span>
                                            <div className="mt-1">
                                                {isEditing ? (
                                                    <input
                                                        type="number"
                                                        min={priorityBounds.min}
                                                        max={priorityBounds.max}
                                                        value={priorityDraft}
                                                        onChange={(event) => {
                                                            setPriorityDraft(event.target.value);
                                                            setActionError(null);
                                                        }}
                                                        className="w-20 border border-gray-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                    />
                                                ) : (
                                                    <span className="text-sm text-gray-900">{table.priority ?? 0}</span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 justify-self-start sm:justify-self-end">
                                            {!isDeleted && (
                                                <>
                                                    <button
                                                        type="button"
                                                        onClick={() => (isEditing ? handleSave(table) : startEdit(table))}
                                                        disabled={
                                                            isSaving ||
                                                            isDeleting ||
                                                            isRestoring ||
                                                            (isEditing && !isPriorityValid(priorityDraft))
                                                        }
                                                        className={`p-2 rounded-full transition-colors ${
                                                            isEditing
                                                                ? 'bg-[#0A2E4D] text-white hover:bg-[#4BBAC4]'
                                                                : 'text-gray-600 hover:bg-gray-100 hover:text-[#0A2E4D]'
                                                        } ${
                                                            isSaving || isDeleting || isRestoring || (isEditing && !isPriorityValid(priorityDraft))
                                                                ? 'cursor-not-allowed opacity-60'
                                                                : 'cursor-pointer'
                                                        }`}
                                                        aria-label={isEditing ? 'Save' : 'Edit'}
                                                    >
                                                        {isEditing ? (
                                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        ) : (
                                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536M16.732 3.732a2.5 2.5 0 113.536 3.536L7.5 20.036H4v-3.572L16.732 3.732z" />
                                                            </svg>
                                                        )}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleDelete(table)}
                                                        disabled={isDeleting || isSaving || isEditing || isRestoring}
                                                        className={`p-2 rounded-full transition-colors text-gray-600 hover:bg-red-50 hover:text-red-600 ${
                                                            isDeleting || isSaving || isEditing || isRestoring
                                                                ? 'cursor-not-allowed opacity-60'
                                                                : 'cursor-pointer'
                                                        }`}
                                                        aria-label="Delete"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-8 0h8m-8 0V5a2 2 0 012-2h4a2 2 0 012 2v2" />
                                                        </svg>
                                                    </button>
                                                </>
                                            )}
                                            {isDeleted && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleRestore(table)}
                                                    disabled={isRestoring || isSaving || isDeleting}
                                                    className={`p-2 rounded-full transition-colors ${
                                                        isRestoring || isSaving || isDeleting
                                                            ? 'text-gray-400 cursor-not-allowed'
                                                            : 'text-[#0A2E4D] hover:bg-[#E6F7F8] cursor-pointer'
                                                    }`}
                                                    aria-label="Restore"
                                                >
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 12a8 8 0 0114.314-4.314M20 12a8 8 0 01-14.314 4.314M4 8v4h4m8 4v-4h4" />
                                                    </svg>
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                        {actionError && (
                            <div className="px-6 py-3 text-sm text-red-600 border-t border-gray-100">
                                {actionError}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
