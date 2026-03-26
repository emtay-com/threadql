import React, { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { useMatch, useNavigate } from 'react-router-dom';
import TenantSidebar from '../../components/TenantSidebar';
import { useToast } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';

const rowHeightClass = 'h-20';

export default function DefinitionsView() {
    const { tenants, isLoading: isLoadingTenants, error: tenantsError, fetchTenants } = useTenantsStore();
    const [definitions, setDefinitions] = useState([]);
    const [activeTenantUuid, setActiveTenantUuid] = useState(() => localStorage.getItem('activeTenant') || null);
    const [isLoadingDefinitions, setIsLoadingDefinitions] = useState(false);
    const [definitionsError, setDefinitionsError] = useState(null);
    const [actionError, setActionError] = useState(null);
    const [editingDefinitionId, setEditingDefinitionId] = useState(null);
    const [definitionDraft, setDefinitionDraft] = useState('');
    const [editingOriginal, setEditingOriginal] = useState('');
    const [isAdding, setIsAdding] = useState(false);
    const [newSubject, setNewSubject] = useState('');
    const [newDefinition, setNewDefinition] = useState('');
    const [savingId, setSavingId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const editContainerRef = useRef(null);
    const navigate = useNavigate();
    const viewMatch = useMatch('/panel/definitions/:tenantId');
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
            setDefinitions([]);
            return;
        }

        const tenant = tenants.find((item) => item.uuid === activeTenantUuid);
        if (!tenant) {
            setDefinitions([]);
            return;
        }

        fetchDefinitions(tenant.id);
    }, [activeTenantUuid, tenants]);

    const fetchDefinitions = async (tenantId) => {
        try {
            setIsLoadingDefinitions(true);
            const response = await axios.get(`/api/admin/tenants/${tenantId}/definitions`);
            setDefinitions(response.data.data || []);
            setDefinitionsError(null);
        } catch (err) {
            setDefinitionsError('Failed to load definitions');
        } finally {
            setIsLoadingDefinitions(false);
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

    const startEdit = (definition) => {
        setEditingDefinitionId(definition.id);
        const currentDefinition = definition.definition || '';
        setDefinitionDraft(currentDefinition);
        setEditingOriginal(currentDefinition);
        setActionError(null);
    };

    const stopEditing = () => {
        setEditingDefinitionId(null);
        setDefinitionDraft('');
        setEditingOriginal('');
    };

    useEffect(() => {
        if (!editingDefinitionId) {
            return;
        }

        const handleClickOutside = (event) => {
            if (!editContainerRef.current) {
                return;
            }
            if (editContainerRef.current.contains(event.target)) {
                return;
            }
            stopEditing();
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, [editingDefinitionId]);

    const handleSave = async (definition) => {
        if (!selectedTenant || savingId || definitionDraft.trim() === '') {
            setActionError('Definition cannot be empty.');
            return;
        }

        if (definitionDraft.trim() === editingOriginal.trim()) {
            stopEditing();
            return;
        }

        try {
            setSavingId(definition.id);
            setActionError(null);
            const payload = { definition: definitionDraft.trim() };
            await axios.put(`/api/admin/tenants/${selectedTenant.id}/definitions/${definition.id}`, payload);
            setDefinitions((prev) =>
                prev.map((item) =>
                    item.id === definition.id ? { ...item, definition: payload.definition } : item
                )
            );
            stopEditing();
            showToast({ state: 'success', message: 'Definition updated' });
        } catch (err) {
            setActionError('Failed to save definition');
        } finally {
            setSavingId(null);
        }
    };

    const handleDelete = async (definition) => {
        if (!selectedTenant || deletingId) {
            return;
        }

        if (!window.confirm(`Delete "${definition.subject}"?`)) {
            return;
        }

        try {
            setDeletingId(definition.id);
            setActionError(null);
            await axios.delete(`/api/admin/tenants/${selectedTenant.id}/definitions/${definition.id}`);
            setDefinitions((prev) => prev.filter((item) => item.id !== definition.id));
            if (editingDefinitionId === definition.id) {
                stopEditing();
            }
            showToast({ state: 'info', message: 'Definition deleted' });
        } catch (err) {
            setActionError('Failed to delete definition');
        } finally {
            setDeletingId(null);
        }
    };

    const handleAdd = async () => {
        if (!selectedTenant || savingId) {
            return;
        }

        const subject = newSubject.trim();
        const definition = newDefinition.trim();
        if (!subject || !definition) {
            setActionError('Subject and definition are required.');
            return;
        }

        try {
            setSavingId('new');
            setActionError(null);
            const payload = { subject, definition };
            const response = await axios.post(`/api/admin/tenants/${selectedTenant.id}/definitions`, payload);
            const created = response.data?.data || response.data;
            setDefinitions((prev) => [created, ...prev]);
            setIsAdding(false);
            setNewSubject('');
            setNewDefinition('');
        } catch (err) {
            setActionError('Failed to create definition');
        } finally {
            setSavingId(null);
        }
    };

    const isLoading = isLoadingTenants || isLoadingDefinitions;
    const sidebarError = tenantsError || definitionsError;

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
                    <div className="text-gray-500">Select a tenant to view definitions.</div>
                ) : isLoading ? (
                    <div className="text-gray-500">Loading definitions...</div>
                ) : (
                    <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-[#0A2E4D]">Definitions</h3>
                                <p className="text-xs text-gray-500">{definitions.length} total</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => {
                                    setIsAdding((prev) => !prev);
                                    setActionError(null);
                                }}
                                className="px-4 py-2 bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white text-sm font-medium rounded-lg hover:shadow-lg transition-all cursor-pointer"
                            >
                                {isAdding ? 'Cancel' : '+ Add'}
                            </button>
                        </div>
                        {isAdding && (
                            <div className="px-6 py-4 border-b border-gray-100 space-y-3">
                                <div>
                                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                                        Subject
                                    </label>
                                    <input
                                        type="text"
                                        value={newSubject}
                                        onChange={(event) => setNewSubject(event.target.value)}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                        placeholder="e.g. ARR"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                                        Definition
                                    </label>
                                    <textarea
                                        value={newDefinition}
                                        onChange={(event) => setNewDefinition(event.target.value)}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                        rows={3}
                                        placeholder="Describe the definition..."
                                    />
                                </div>
                                <div className="flex justify-end">
                                    <button
                                        type="button"
                                        onClick={handleAdd}
                                        disabled={savingId === 'new'}
                                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-all ${
                                            savingId === 'new'
                                                ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                                                : 'bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white hover:shadow-lg cursor-pointer'
                                        }`}
                                    >
                                        Save
                                    </button>
                                </div>
                            </div>
                        )}
                        {definitions.length === 0 ? (
                            <div className="px-6 py-6 text-gray-500">No definitions found.</div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {definitions.map((definition) => {
                                    const isEditing = editingDefinitionId === definition.id;
                                    const isSaving = savingId === definition.id;
                                    const isDeleting = deletingId === definition.id;
                                    return (
                                        <li
                                            key={definition.id}
                                            ref={isEditing ? editContainerRef : null}
                                            className={`px-6 ${rowHeightClass} grid grid-cols-1 sm:grid-cols-[1fr_2fr_auto] items-center gap-4`}
                                        >
                                            <div className="min-w-0">
                                                <div className="text-sm font-medium text-gray-900 truncate">
                                                    {definition.subject}
                                                </div>
                                            </div>
                                            <div className="text-xs text-gray-600">
                                                {isEditing ? (
                                                    <textarea
                                                        value={definitionDraft}
                                                        onChange={(event) => {
                                                            setDefinitionDraft(event.target.value);
                                                            setActionError(null);
                                                        }}
                                                        className="w-full border border-gray-300 rounded-md px-2 py-1 text-xs h-14 resize-none focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                    />
                                                ) : (
                                                    <div
                                                        className="h-14 overflow-auto pr-2 cursor-pointer"
                                                        onDoubleClick={() => startEdit(definition)}
                                                    >
                                                        {definition.definition}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-3 justify-self-start sm:justify-self-end">
                                                <button
                                                    type="button"
                                                    onClick={() => (isEditing ? handleSave(definition) : startEdit(definition))}
                                                    disabled={isSaving || isDeleting || (isEditing && definitionDraft.trim() === '')}
                                                    className={`p-2 rounded-full transition-colors ${
                                                        isEditing
                                                            ? 'bg-[#0A2E4D] text-white hover:bg-[#4BBAC4]'
                                                            : 'text-gray-600 hover:bg-gray-100 hover:text-[#0A2E4D]'
                                                    } ${
                                                        isSaving || isDeleting || (isEditing && definitionDraft.trim() === '')
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
                                                    onClick={() => handleDelete(definition)}
                                                    disabled={isDeleting || isSaving || isEditing}
                                                    className={`p-2 rounded-full transition-colors text-gray-600 hover:bg-red-50 hover:text-red-600 ${
                                                        isDeleting || isSaving || isEditing
                                                            ? 'cursor-not-allowed opacity-60'
                                                            : 'cursor-pointer'
                                                    }`}
                                                    aria-label="Delete"
                                                >
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7h6m-8 0h8m-8 0V5a2 2 0 012-2h4a2 2 0 012 2v2" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
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
