import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { useMatch, useNavigate } from 'react-router-dom';
import TenantSidebar from '../../components/TenantSidebar';
import { useToast } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';
import { adapterOptions, providerOptions, defaultUrls } from '../../generated/providerOptions';

const defaultFormData = {
    name: '',
    adapter: '',
    url: '',
    model_name: '',
    api_key: '',
    options: {},
    enabled: true,
};

/**
 * Build a clean options object for the selected adapter,
 * filtering out empty values and only including known option keys.
 */
function buildOptionsPayload(adapter, options) {
    const schema = providerOptions[adapter] || {};
    const keys = Object.keys(schema);
    if (keys.length === 0) {
        return null;
    }

    const result = {};
    let hasValue = false;
    for (const key of keys) {
        const val = options[key];
        if (val !== undefined && val !== null && val !== '') {
            result[key] = val;
            hasValue = true;
        }
    }

    return hasValue ? result : null;
}

export default function LLMProvidersView() {
    const { tenants, isLoading: isLoadingTenants, error: tenantsError, fetchTenants } = useTenantsStore();
    const [providers, setProviders] = useState([]);
    const [activeTenantUuid, setActiveTenantUuid] = useState(() => localStorage.getItem('activeTenant') || null);
    const [isLoadingProviders, setIsLoadingProviders] = useState(false);
    const [providersError, setProvidersError] = useState(null);
    const [actionError, setActionError] = useState(null);
    const [isAdding, setIsAdding] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [formData, setFormData] = useState({ ...defaultFormData });
    const [savingId, setSavingId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);
    const [pingingId, setPingingId] = useState(null);
    const navigate = useNavigate();
    const viewMatch = useMatch('/panel/llm-providers/:tenantId');
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
            setProviders([]);
            return;
        }

        const tenant = tenants.find((item) => item.uuid === activeTenantUuid);
        if (!tenant) {
            setProviders([]);
            return;
        }

        fetchProviders(tenant.id);
    }, [activeTenantUuid, tenants]);

    const fetchProviders = async (tenantId) => {
        try {
            setIsLoadingProviders(true);
            const response = await axios.get(`/api/admin/tenants/${tenantId}/llm-providers`);
            setProviders(response.data.data || []);
            setProvidersError(null);
        } catch (err) {
            setProvidersError('Failed to load LLM providers');
        } finally {
            setIsLoadingProviders(false);
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

    const startEdit = (provider) => {
        setEditingId(provider.id);
        setFormData({
            name: provider.name || '',
            adapter: provider.adapter || '',
            url: provider.url || '',
            model_name: provider.model || '',
            api_key: '',
            options: provider.options || {},
            enabled: provider.enabled,
        });
        setActionError(null);
    };

    const stopEditing = () => {
        setEditingId(null);
        setFormData({ ...defaultFormData });
    };

    const handleAdd = async () => {
        if (!selectedTenant || savingId) {
            return;
        }

        if (!formData.name.trim() || !formData.adapter || !formData.model_name.trim()) {
            setActionError('Name, adapter, and model are required.');
            return;
        }

        try {
            setSavingId('new');
            setActionError(null);
            const payload = {
                name: formData.name.trim(),
                adapter: formData.adapter,
                url: formData.url.trim() || null,
                model_name: formData.model_name.trim(),
                api_key: formData.api_key || null,
                options: buildOptionsPayload(formData.adapter, formData.options),
                enabled: formData.enabled,
            };
            const response = await axios.post(`/api/admin/tenants/${selectedTenant.id}/llm-providers`, payload);
            const created = response.data?.data || response.data;
            setProviders((prev) => [...prev, created].sort((a, b) => a.sort - b.sort || a.id - b.id));
            setIsAdding(false);
            setFormData({ ...defaultFormData });
            showToast({ state: 'success', message: 'Provider created' });
            if (created.id) {
                handlePing(created.id);
            }
        } catch (err) {
            setActionError('Failed to create provider');
        } finally {
            setSavingId(null);
        }
    };

    const handleUpdate = async (provider) => {
        if (!selectedTenant || savingId) {
            return;
        }

        if (!formData.name.trim() || !formData.adapter || !formData.model_name.trim()) {
            setActionError('Name, adapter, and model are required.');
            return;
        }

        try {
            setSavingId(provider.id);
            setActionError(null);
            const payload = {
                name: formData.name.trim(),
                adapter: formData.adapter,
                url: formData.url.trim() || null,
                model_name: formData.model_name.trim(),
                options: buildOptionsPayload(formData.adapter, formData.options),
                enabled: formData.enabled,
            };
            if (formData.api_key) {
                payload.api_key = formData.api_key;
            }
            const response = await axios.put(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${provider.id}`, payload);
            const updated = response.data?.data || response.data;
            setProviders((prev) =>
                prev.map((item) => (item.id === provider.id ? updated : item)).sort((a, b) => a.sort - b.sort || a.id - b.id)
            );
            stopEditing();
            showToast({ state: 'success', message: 'Provider updated' });
            handlePing(provider.id);
        } catch (err) {
            setActionError('Failed to update provider');
        } finally {
            setSavingId(null);
        }
    };

    const handleDelete = async (provider) => {
        if (!selectedTenant || deletingId) {
            return;
        }

        if (!window.confirm(`Delete "${provider.name}"?`)) {
            return;
        }

        try {
            setDeletingId(provider.id);
            setActionError(null);
            await axios.delete(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${provider.id}`);
            setProviders((prev) => prev.filter((item) => item.id !== provider.id));
            if (editingId === provider.id) {
                stopEditing();
            }
            showToast({ state: 'info', message: 'Provider deleted' });
        } catch (err) {
            setActionError('Failed to delete provider');
        } finally {
            setDeletingId(null);
        }
    };

    const handleToggleEnabled = async (provider) => {
        if (!selectedTenant || savingId) {
            return;
        }

        try {
            setSavingId(provider.id);
            const payload = {
                name: provider.name,
                adapter: provider.adapter,
                url: provider.url,
                model_name: provider.model,
                options: provider.options,
                enabled: !provider.enabled,
                sort: provider.sort,
            };
            const response = await axios.put(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${provider.id}`, payload);
            const updated = response.data?.data || response.data;
            setProviders((prev) => prev.map((item) => (item.id === provider.id ? updated : item)));
            showToast({ state: 'success', message: updated.enabled ? 'Provider enabled' : 'Provider disabled' });
        } catch (err) {
            setActionError('Failed to toggle provider');
        } finally {
            setSavingId(null);
        }
    };

    const handlePing = async (providerId) => {
        if (!selectedTenant || pingingId) {
            return;
        }

        try {
            setPingingId(providerId);
            await axios.get(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${providerId}/ping`);
            showToast({ state: 'success', message: 'Connection successful' });
        } catch (err) {
            const errorMsg = err?.response?.data?.data?.error || 'Connection failed';
            showToast({ state: 'error', message: errorMsg });
        } finally {
            setPingingId(null);
        }
    };

    const handleMoveUp = async (provider, index) => {
        if (!selectedTenant || savingId || index === 0) {
            return;
        }

        const prev = providers[index - 1];
        try {
            setSavingId(provider.id);
            await Promise.all([
                axios.put(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${provider.id}`, {
                    name: provider.name,
                    adapter: provider.adapter,
                    url: provider.url,
                    model_name: provider.model,
                    options: provider.options,
                    sort: prev.sort,
                }),
                axios.put(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${prev.id}`, {
                    name: prev.name,
                    adapter: prev.adapter,
                    url: prev.url,
                    model_name: prev.model,
                    options: prev.options,
                    sort: provider.sort,
                }),
            ]);
            setProviders((current) => {
                const updated = current.map((item) => {
                    if (item.id === provider.id) {
                        return { ...item, sort: prev.sort };
                    }
                    if (item.id === prev.id) {
                        return { ...item, sort: provider.sort };
                    }
                    return item;
                });
                return updated.sort((a, b) => a.sort - b.sort || a.id - b.id);
            });
        } catch (err) {
            setActionError('Failed to reorder');
        } finally {
            setSavingId(null);
        }
    };

    const handleMoveDown = async (provider, index) => {
        if (!selectedTenant || savingId || index === providers.length - 1) {
            return;
        }

        const next = providers[index + 1];
        try {
            setSavingId(provider.id);
            await Promise.all([
                axios.put(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${provider.id}`, {
                    name: provider.name,
                    adapter: provider.adapter,
                    url: provider.url,
                    model_name: provider.model,
                    options: provider.options,
                    sort: next.sort,
                }),
                axios.put(`/api/admin/tenants/${selectedTenant.id}/llm-providers/${next.id}`, {
                    name: next.name,
                    adapter: next.adapter,
                    url: next.url,
                    model_name: next.model,
                    options: next.options,
                    sort: provider.sort,
                }),
            ]);
            setProviders((current) => {
                const updated = current.map((item) => {
                    if (item.id === provider.id) {
                        return { ...item, sort: next.sort };
                    }
                    if (item.id === next.id) {
                        return { ...item, sort: provider.sort };
                    }
                    return item;
                });
                return updated.sort((a, b) => a.sort - b.sort || a.id - b.id);
            });
        } catch (err) {
            setActionError('Failed to reorder');
        } finally {
            setSavingId(null);
        }
    };

    const isLoading = isLoadingTenants || isLoadingProviders;
    const sidebarError = tenantsError || providersError;

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
                    <div className="text-gray-500">Select a tenant to view LLM providers.</div>
                ) : isLoading ? (
                    <div className="text-gray-500">Loading LLM providers...</div>
                ) : (
                    <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-[#0A2E4D]">LLM Providers</h3>
                                <p className="text-xs text-gray-500">{providers.length} total</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => {
                                    setIsAdding((prev) => !prev);
                                    setEditingId(null);
                                    setFormData({ ...defaultFormData });
                                    setActionError(null);
                                }}
                                className="px-4 py-2 bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white text-sm font-medium rounded-lg hover:shadow-lg transition-all cursor-pointer"
                            >
                                {isAdding ? 'Cancel' : '+ Add'}
                            </button>
                        </div>

                        {isAdding && (
                            <ProviderForm
                                formData={formData}
                                onChange={(field, value) => {
                                    setFormData((prev) => ({ ...prev, [field]: value }));
                                    setActionError(null);
                                }}
                                onSave={handleAdd}
                                isSaving={savingId === 'new'}
                                showApiKeyField={true}
                            />
                        )}

                        {providers.length === 0 && !isAdding ? (
                            <div className="px-6 py-6 text-gray-500">No LLM providers configured.</div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {providers.map((provider, index) => {
                                    const isEditing = editingId === provider.id;
                                    const isSaving = savingId === provider.id;
                                    const isDeleting = deletingId === provider.id;

                                    if (isEditing) {
                                        return (
                                            <li key={provider.id} className="px-6 py-4">
                                                <ProviderForm
                                                    formData={formData}
                                                    onChange={(field, value) => {
                                                        setFormData((prev) => ({ ...prev, [field]: value }));
                                                        setActionError(null);
                                                    }}
                                                    onSave={() => handleUpdate(provider)}
                                                    onCancel={stopEditing}
                                                    isSaving={isSaving}
                                                    showApiKeyField={true}
                                                    apiKeyPlaceholder={provider.has_api_key ? 'Leave blank to keep current' : 'Enter API key'}
                                                    onTest={() => handlePing(provider.id)}
                                                    isTesting={pingingId === provider.id}
                                                />
                                            </li>
                                        );
                                    }

                                    return (
                                        <li
                                            key={provider.id}
                                            className={`px-6 py-4 flex items-center gap-4 ${!provider.enabled ? 'opacity-60' : ''}`}
                                        >
                                            {/* Sort arrows */}
                                            <div className="flex flex-col gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => handleMoveUp(provider, index)}
                                                    disabled={index === 0 || savingId}
                                                    className={`p-1 rounded transition-colors ${
                                                        index === 0 || savingId
                                                            ? 'text-gray-300 cursor-not-allowed'
                                                            : 'text-gray-500 hover:bg-gray-100 hover:text-[#0A2E4D] cursor-pointer'
                                                    }`}
                                                    aria-label="Move up"
                                                >
                                                    <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 15l7-7 7 7" />
                                                    </svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleMoveDown(provider, index)}
                                                    disabled={index === providers.length - 1 || savingId}
                                                    className={`p-1 rounded transition-colors ${
                                                        index === providers.length - 1 || savingId
                                                            ? 'text-gray-300 cursor-not-allowed'
                                                            : 'text-gray-500 hover:bg-gray-100 hover:text-[#0A2E4D] cursor-pointer'
                                                    }`}
                                                    aria-label="Move down"
                                                >
                                                    <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                                    </svg>
                                                </button>
                                            </div>

                                            {/* Provider info */}
                                            <div className="flex-1 min-w-0">
                                                <div className="text-sm font-medium text-gray-900 truncate">{provider.name}</div>
                                                <div className="text-xs text-gray-500">
                                                    {provider.adapter} &middot; {provider.model}
                                                    {provider.url && (
                                                        <span className="ml-1 text-gray-400">&middot; {provider.url}</span>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Enabled toggle */}
                                            <button
                                                type="button"
                                                onClick={() => handleToggleEnabled(provider)}
                                                disabled={isSaving || isDeleting}
                                                className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                                                    isSaving || isDeleting ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'
                                                } ${provider.enabled ? 'bg-[#4BBAC4]' : 'bg-gray-300'}`}
                                                aria-label={provider.enabled ? 'Disable' : 'Enable'}
                                            >
                                                <span
                                                    className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                                                        provider.enabled ? 'translate-x-6' : 'translate-x-1'
                                                    }`}
                                                />
                                            </button>

                                            {/* Actions */}
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => startEdit(provider)}
                                                    disabled={isSaving || isDeleting}
                                                    className={`p-2 rounded-full transition-colors text-gray-600 hover:bg-gray-100 hover:text-[#0A2E4D] ${
                                                        isSaving || isDeleting ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'
                                                    }`}
                                                    aria-label="Edit"
                                                >
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536M16.732 3.732a2.5 2.5 0 113.536 3.536L7.5 20.036H4v-3.572L16.732 3.732z" />
                                                    </svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(provider)}
                                                    disabled={isDeleting || isSaving}
                                                    className={`p-2 rounded-full transition-colors text-gray-600 hover:bg-red-50 hover:text-red-600 ${
                                                        isDeleting || isSaving ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'
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

function ProviderForm({ formData, onChange, onSave, onCancel, isSaving, showApiKeyField, apiKeyPlaceholder, onTest, isTesting }) {
    const adapterSchema = providerOptions[formData.adapter] || {};
    const optionKeys = Object.keys(adapterSchema);

    const handleAdapterChange = (newAdapter) => {
        onChange('adapter', newAdapter);
        // Reset options when adapter changes to avoid stale values from a different adapter
        onChange('options', {});
        // Set default URL for the selected adapter
        onChange('url', defaultUrls[newAdapter] || '');
    };

    const handleOptionChange = (key, value, type) => {
        const castValue = type === 'number' && value !== '' ? String(Number(value)) : value;
        onChange('options', { ...formData.options, [key]: castValue });
    };

    return (
        <div className="px-6 py-4 border-b border-gray-100 space-y-3">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Name</label>
                    <input
                        type="text"
                        value={formData.name}
                        onChange={(e) => onChange('name', e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                        placeholder="e.g. OpenAI GPT-4"
                    />
                </div>
                <div>
                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Adapter</label>
                    <select
                        value={formData.adapter}
                        onChange={(e) => handleAdapterChange(e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                    >
                        <option value="">Select adapter</option>
                        {adapterOptions.map((adapter) => (
                            <option key={adapter} value={adapter}>{adapter}</option>
                        ))}
                    </select>
                </div>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">URL</label>
                    <input
                        type="text"
                        value={formData.url}
                        onChange={(e) => onChange('url', e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                        placeholder="e.g. https://api.openai.com/v1"
                    />
                </div>
                <div>
                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Model</label>
                    <input
                        type="text"
                        value={formData.model_name}
                        onChange={(e) => onChange('model_name', e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                        placeholder="e.g. gpt-4"
                    />
                </div>
            </div>
            {showApiKeyField && (
                <div>
                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">API Key</label>
                    <input
                        type="password"
                        value={formData.api_key}
                        onChange={(e) => onChange('api_key', e.target.value)}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                        placeholder={apiKeyPlaceholder || 'Enter API key'}
                    />
                </div>
            )}
            {optionKeys.length > 0 && (
                <div className="border border-gray-200 rounded-lg p-3 space-y-2">
                    <div className="text-xs font-medium text-gray-500 uppercase tracking-wide">Provider Options</div>
                    {optionKeys.map((key) => {
                        const spec = adapterSchema[key];
                        const currentValue = formData.options?.[key] ?? '';
                        return (
                            <div key={key} className="grid grid-cols-1 sm:grid-cols-3 gap-2 items-center">
                                <label className="text-xs text-gray-600" title={spec.description}>
                                    {key}
                                    {spec.default !== null && (
                                        <span className="text-gray-400 ml-1">(default: {String(spec.default)})</span>
                                    )}
                                </label>
                                <input
                                    type={spec.type === 'number' ? 'number' : 'text'}
                                    value={currentValue}
                                    onChange={(e) => handleOptionChange(key, e.target.value, spec.type)}
                                    className="sm:col-span-2 w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                    placeholder={spec.description}
                                />
                            </div>
                        );
                    })}
                </div>
            )}
            <div className="flex items-center gap-2">
                <label className="text-xs font-medium text-gray-500 uppercase tracking-wide">Enabled</label>
                <input
                    type="checkbox"
                    checked={formData.enabled}
                    onChange={(e) => onChange('enabled', e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-[#4BBAC4] focus:ring-[#4BBAC4]"
                />
            </div>
            <div className="flex justify-end gap-2">
                {onTest && (
                    <button
                        type="button"
                        onClick={onTest}
                        disabled={isTesting || isSaving}
                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-all border ${
                            isTesting || isSaving
                                ? 'border-gray-300 text-gray-400 cursor-not-allowed'
                                : 'border-[#4BBAC4] text-[#0A2E4D] hover:bg-[#4BBAC4]/10 cursor-pointer'
                        }`}
                        aria-label="Test connection"
                    >
                        {isTesting ? 'Testing...' : 'Test'}
                    </button>
                )}
                {onCancel && (
                    <button
                        type="button"
                        onClick={onCancel}
                        className="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-all cursor-pointer"
                    >
                        Cancel
                    </button>
                )}
                <button
                    type="button"
                    onClick={onSave}
                    disabled={isSaving}
                    className={`px-4 py-2 text-sm font-medium rounded-lg transition-all ${
                        isSaving
                            ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                            : 'bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white hover:shadow-lg cursor-pointer'
                    }`}
                >
                    {isSaving ? 'Saving...' : 'Save'}
                </button>
            </div>
        </div>
    );
}
