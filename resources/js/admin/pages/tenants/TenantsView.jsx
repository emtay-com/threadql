import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { useMatch, useNavigate } from 'react-router-dom';
import TenantSidebar from '../../components/TenantSidebar';
import { timezones } from '../../../constants/timezones';
import { useToast } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';
import { decodeToken } from '../../../services/token';

const defaultFormData = {
    name: '',
    timezone: '',
    slack_app_id: '',
    slack_client_id: '',
    slack_bot_token: '',
    slack_signing_secret: '',
    slack_verification_token: '',
    has_slack_bot_token: false,
    has_slack_signing_secret: false,
    has_slack_verification_token: false,
};

export default function TenantsView() {
    const token = sessionStorage.getItem('admin_token');
    const decoded = decodeToken(token);
    const isMaster = Boolean(decoded?.is_master || decoded?.level === 'master');

    const { tenants, isLoading: isLoadingTenants, error: tenantsError, fetchTenants, upsertTenant } = useTenantsStore();
    const [saveError, setSaveError] = useState(null);
    const [isSaving, setIsSaving] = useState(false);
    const [activeTenantUuid, setActiveTenantUuid] = useState(() => localStorage.getItem('activeTenant') || null);
    const [mode, setMode] = useState('view'); // view | edit | add
    const [formData, setFormData] = useState(null);
    const navigate = useNavigate();
    const { showToast } = useToast();

    const viewMatch = useMatch('/panel/tenants/:tenantId');
    const editMatch = useMatch('/panel/tenants/edit/:tenantId');
    const addMatch = useMatch('/panel/tenants/edit/add');

    useEffect(() => {
        fetchTenants();
    }, []);

    const handleTenantSelect = (uuid) => {
        if (activeTenantUuid === uuid) {
            return;
        }

        localStorage.setItem('activeTenant', uuid);
        setActiveTenantUuid(uuid);
        navigate(`/panel/tenants/${uuid}`);
    };

    const startAdd = () => {
        if (!isMaster) {
            return;
        }

        navigate('/panel/tenants/edit/add');
    };

    const startEdit = (tenant) => {
        if (!isMaster) {
            return;
        }

        navigate(`/panel/tenants/edit/${tenant.uuid}`);
    };

    const handleFormChange = (field, value) => {
        if (!formData) return;
        setSaveError(null);

        setFormData((prev) => ({
            ...prev,
            [field]: value,
        }));
    };

    const selectedTenant = useMemo(
        () => tenants.find((t) => t.uuid === activeTenantUuid) || null,
        [tenants, activeTenantUuid]
    );

    useEffect(() => {
        if (addMatch) {
            setActiveTenantUuid(null);
            localStorage.removeItem('activeTenant');
            setMode('add');
            setFormData({ ...defaultFormData });
            setSaveError(null);
            return;
        }

        if (editMatch?.params?.tenantId) {
            const uuid = editMatch.params.tenantId;
            const tenant = tenants.find((t) => t.uuid === uuid);
            if (tenant) {
                setActiveTenantUuid(uuid);
                localStorage.setItem('activeTenant', uuid);
                setMode('edit');
                setFormData({
                    ...defaultFormData,
                    name: tenant.name || '',
                    timezone: tenant.timezone || '',
                    slack_app_id: tenant.slack_app_id || '',
                    slack_client_id: tenant.slack_client_id || '',
                    has_slack_bot_token: Boolean(tenant.has_slack_bot_token),
                    has_slack_signing_secret: Boolean(tenant.has_slack_signing_secret),
                    has_slack_verification_token: Boolean(tenant.has_slack_verification_token),
                });
            } else {
                setMode('view');
                setFormData(null);
            }
            setSaveError(null);
            return;
        }

        if (viewMatch?.params?.tenantId) {
            const uuid = viewMatch.params.tenantId;
            setActiveTenantUuid(uuid);
            localStorage.setItem('activeTenant', uuid);
            setMode('view');
            setFormData(null);
            setSaveError(null);
            return;
        }

        setMode('view');
        setFormData(null);
        setSaveError(null);
    }, [addMatch, editMatch, viewMatch, tenants]);

    const handleCancel = () => {
        if (selectedTenant) {
            navigate(`/panel/tenants/${selectedTenant.uuid}`);
            return;
        }
        navigate('/panel/tenants');
    };

    const extractErrorMessage = (err) => {
        if (err?.response?.data?.message) {
            return err.response.data.message;
        }
        if (err?.message) {
            return err.message;
        }
        return 'Failed to save tenant';
    };

    const testSlackConnection = async (tenantId) => {
        try {
            const response = await axios.get(`/api/admin/tenants/${tenantId}/test-slack`);
            return response.data?.data;
        } catch (err) {
            const message = err?.response?.data?.data?.message || err?.message || 'Slack test failed';
            return { success: false, message };
        }
    };

    const hasSlackFields = (payload) => {
        return Boolean(payload.slack_bot_token);
    };

    const handleSave = async (payload) => {
        if (isSaving) return;

        try {
            setIsSaving(true);
            setSaveError(null);
            let response;

            if (mode === 'add') {
                response = await axios.post('/api/admin/tenants', payload);
            } else if (mode === 'edit' && selectedTenant) {
                response = await axios.put(`/api/admin/tenants/${selectedTenant.id}`, payload);
            } else {
                setIsSaving(false);
                return;
            }

            const newTenant = response.data?.data || response.data;

            // Test Slack connection after save if slack bot token was provided
            const shouldTestSlack = hasSlackFields(payload) || (!hasSlackFields(payload) && newTenant.has_slack_bot_token && mode === 'edit');
            if (shouldTestSlack && newTenant.id) {
                const testResult = await testSlackConnection(newTenant.id);
                if (!testResult?.success) {
                    const message = testResult?.message || 'Slack connection test failed';
                    setSaveError(message);
                    showToast({ state: 'error', message: `Saved but Slack test failed: ${message}` });
                    // Still update the store since save succeeded
                    upsertTenant(newTenant);
                    return;
                }
                showToast({ state: 'success', message: 'Tenant saved and Slack connection verified' });
            } else {
                showToast({ state: 'success', message: 'Tenant saved' });
            }

            upsertTenant(newTenant);

            const nextUuid = newTenant.uuid;
            if (nextUuid) {
                setActiveTenantUuid(nextUuid);
                localStorage.setItem('activeTenant', nextUuid);
                navigate(`/panel/tenants/${nextUuid}`);
            } else {
                navigate('/panel/tenants');
            }

            setMode('view');
            setFormData(null);
        } catch (err) {
            const message = extractErrorMessage(err);
            setSaveError(message);
            showToast({ state: 'error', message });
        } finally {
            setIsSaving(false);
        }
    };

    const isLoading = isLoadingTenants;
    const sidebarError = tenantsError;

    return (
        <div className="flex flex-col md:flex-row flex-1 overflow-hidden">
            <TenantSidebar
                tenants={tenants}
                activeTenantUuid={activeTenantUuid}
                onTenantSelect={handleTenantSelect}
                onAddTenant={isMaster ? startAdd : null}
                isLoading={isLoading}
                error={sidebarError}
            />
            <div className="flex-1 p-5 md:p-8 overflow-y-auto flex justify-center">
                {mode === 'add' && formData ? (
                    <TenantForm
                        mode="add"
                        formData={formData}
                        timezones={timezones}
                        onChange={handleFormChange}
                        onCancel={handleCancel}
                        onSave={handleSave}
                        isSaving={isSaving}
                        saveError={saveError}
                    />
                ) : mode === 'edit' && formData && selectedTenant ? (
                    <TenantForm
                        mode="edit"
                        formData={formData}
                        timezones={timezones}
                        onChange={handleFormChange}
                        onCancel={handleCancel}
                        onSave={handleSave}
                        isSaving={isSaving}
                        saveError={saveError}
                        tenantId={selectedTenant.id}
                    />
                ) : selectedTenant ? (
                    <TenantCard
                        tenant={selectedTenant}
                        canEdit={isMaster}
                        onEdit={() => startEdit(selectedTenant)}
                    />
                ) : (
                    <div className="text-gray-500">
                        Select a tenant to view details.
                    </div>
                )}
            </div>
        </div>
    );
}

function TenantCard({ tenant, onEdit, canEdit }) {
    const slackIncomplete = !tenant.has_slack_bot_token || !tenant.has_slack_signing_secret || !tenant.has_slack_verification_token;
    const { showToast } = useToast();
    const [isTesting, setIsTesting] = useState(false);

    const handleTestSlack = async () => {
        if (isTesting) return;
        setIsTesting(true);
        try {
            const response = await axios.get(`/api/admin/tenants/${tenant.id}/test-slack`);
            const result = response.data?.data;
            const details = [result?.team, result?.user].filter(Boolean).join(' / ');
            const message = details
                ? `${result?.message || 'Slack connection verified'} (${details})`
                : (result?.message || 'Slack connection verified');
            showToast({ state: 'success', message });
        } catch (err) {
            const message = err?.response?.data?.data?.message || err?.message || 'Slack test failed';
            showToast({ state: 'error', message });
        } finally {
            setIsTesting(false);
        }
    };

    return (
        <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
            <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 className="text-lg font-semibold text-[#0A2E4D]">{tenant.name}</h3>
                <div className="flex items-center gap-2">
                    {tenant.has_slack_bot_token && (
                        <button
                            onClick={handleTestSlack}
                            disabled={isTesting}
                            className={`px-4 py-2 text-sm font-medium rounded-lg transition-all cursor-pointer ${
                                isTesting
                                    ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                                    : 'border border-[#4BBAC4] text-[#0A2E4D] hover:bg-[#4BBAC4]/10'
                            }`}
                        >
                            {isTesting ? 'Testing...' : 'Test Slack'}
                        </button>
                    )}
                    {canEdit && (
                        <button
                            onClick={onEdit}
                            className="px-4 py-2 bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white text-sm font-medium rounded-lg hover:shadow-lg transition-all cursor-pointer"
                        >
                            Edit
                        </button>
                    )}
                </div>
            </div>
            <div className="px-6 py-4 space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            UUID
                        </label>
                        <p className="text-sm text-gray-900 font-mono">{tenant.uuid}</p>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Timezone
                        </label>
                        <p className="text-sm text-gray-900">{tenant.timezone || <span className="text-gray-400 italic">Not set</span>}</p>
                    </div>
                </div>

                <div className="pt-4 border-t border-gray-100">
                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">
                        Slack Configuration
                    </label>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                        <div>
                            <div className="text-xs text-gray-500 uppercase mb-1">App ID</div>
                            <div className="text-sm text-gray-900 font-mono">{tenant.slack_app_id || <span className="text-gray-400 italic">Not set</span>}</div>
                        </div>
                        <div>
                            <div className="text-xs text-gray-500 uppercase mb-1">Client ID</div>
                            <div className="text-sm text-gray-900 font-mono">{tenant.slack_client_id || <span className="text-gray-400 italic">Not set</span>}</div>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div className="flex items-center gap-2">
                            {tenant.has_slack_bot_token ? (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Bot Token
                                </span>
                            ) : (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                    Bot Token
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {tenant.has_slack_signing_secret ? (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Signing Secret
                                </span>
                            ) : (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                    Signing Secret
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {tenant.has_slack_verification_token ? (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Verification
                                </span>
                            ) : (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                    Verification
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-gray-100">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Created
                        </label>
                        <p className="text-sm text-gray-600">
                            {new Date(tenant.created_at).toLocaleDateString('sv-SE')}
                        </p>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Updated
                        </label>
                        <p className="text-sm text-gray-600">
                            {new Date(tenant.updated_at).toLocaleDateString('sv-SE')}
                        </p>
                    </div>
                </div>

                {slackIncomplete && (
                    <div className="pt-4 border-t border-gray-100">
                        <InstallationInstructions tenantId={tenant.id} />
                    </div>
                )}
            </div>
        </div>
    );
}

function InstallationInstructions({ tenantId }) {
    const [manifest, setManifest] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);
    const [copied, setCopied] = useState(false);

    const handleGenerate = async () => {
        setIsLoading(true);
        setError(null);
        setManifest(null);
        try {
            const response = await axios.get(`/api/admin/tenants/${tenantId}/manifest`);
            setManifest(response.data?.data?.manifest || JSON.stringify(response.data, null, 2));
        } catch (err) {
            setError(err?.response?.data?.message || err?.message || 'Failed to generate manifest');
        } finally {
            setIsLoading(false);
        }
    };

    const handleCopy = async () => {
        if (!manifest) return;
        try {
            await navigator.clipboard.writeText(manifest);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // fallback
            const textarea = document.createElement('textarea');
            textarea.value = manifest;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <div className="space-y-3">
            <h4 className="text-sm font-semibold text-[#0A2E4D]">Installation Instructions</h4>
            <p className="text-xs text-gray-500">
                Generate a Slack App Manifest to install or update this tenant's Slack application.
                Copy the manifest JSON and paste it into Slack's app configuration.
            </p>
            <button
                type="button"
                onClick={handleGenerate}
                disabled={isLoading}
                className={`px-4 py-2 text-sm font-medium rounded-lg transition-all cursor-pointer ${
                    isLoading
                        ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                        : 'bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white hover:shadow-lg'
                }`}
            >
                {isLoading ? 'Generating...' : 'Generate Manifest'}
            </button>
            {error && <p className="text-xs text-red-600">{error}</p>}
            {manifest && (
                <div className="space-y-2">
                    <div className="flex items-center justify-end">
                        <button
                            type="button"
                            onClick={handleCopy}
                            className="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition-all cursor-pointer flex items-center gap-1"
                        >
                            {copied ? (
                                <>
                                    <svg className="w-3.5 h-3.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Copied
                                </>
                            ) : (
                                <>
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    Copy to Clipboard
                                </>
                            )}
                        </button>
                    </div>
                    <div className="bg-gray-900 text-gray-100 rounded-lg p-4 text-xs font-mono overflow-auto max-h-80 whitespace-pre">
                        {manifest}
                    </div>
                </div>
            )}
        </div>
    );
}

function TenantForm({ mode, formData, timezones, onChange, onCancel, onSave, isSaving, saveError, tenantId }) {
    const isFormValid = useMemo(() => {
        return Boolean(formData.name.trim() && formData.timezone);
    }, [formData]);
    const [showBotTokenInput, setShowBotTokenInput] = useState(mode === 'add');
    const [showSigningSecretInput, setShowSigningSecretInput] = useState(mode === 'add');
    const [showVerificationTokenInput, setShowVerificationTokenInput] = useState(mode === 'add');

    useEffect(() => {
        const shouldShow = mode === 'add';
        setShowBotTokenInput(shouldShow);
        setShowSigningSecretInput(shouldShow);
        setShowVerificationTokenInput(shouldShow);
    }, [mode]);

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!isFormValid || isSaving) {
            return;
        }

        const payload = {
            name: formData.name.trim(),
            timezone: formData.timezone,
        };

        if (formData.slack_app_id) {
            payload.slack_app_id = formData.slack_app_id;
        }
        if (formData.slack_client_id) {
            payload.slack_client_id = formData.slack_client_id;
        }
        if ((mode === 'add' || showBotTokenInput) && formData.slack_bot_token) {
            payload.slack_bot_token = formData.slack_bot_token;
        }
        if ((mode === 'add' || showSigningSecretInput) && formData.slack_signing_secret) {
            payload.slack_signing_secret = formData.slack_signing_secret;
        }
        if ((mode === 'add' || showVerificationTokenInput) && formData.slack_verification_token) {
            payload.slack_verification_token = formData.slack_verification_token;
        }

        onSave?.(payload);
    };

    const timezoneOptions = useMemo(() => {
        if (!formData?.timezone || timezones.find((t) => t.value === formData.timezone)) {
            return timezones;
        }
        return [
            { value: formData.timezone, label: `Custom: ${formData.timezone}`, offset: null },
            ...timezones,
        ];
    }, [formData?.timezone, timezones]);

    return (
        <form onSubmit={handleSubmit} className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
            <div className="px-6 py-4 border-b border-gray-200">
                <p className="text-xs uppercase tracking-wide text-gray-500">{mode === 'add' ? 'Add Tenant' : 'Edit Tenant'}</p>
                <h3 className="text-lg font-semibold text-[#0A2E4D]">{formData.name || 'New Tenant'}</h3>
            </div>

            <div className="px-6 py-4 space-y-5">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Tenant Name
                        </label>
                        <input
                            type="text"
                            value={formData.name}
                            onChange={(e) => onChange('name', e.target.value)}
                            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                            placeholder="e.g., Acme Corp"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Timezone
                        </label>
                        <select
                            value={formData.timezone}
                            onChange={(e) => onChange('timezone', e.target.value)}
                            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                        >
                            <option value="">Select a timezone</option>
                            {timezoneOptions.map((tz) => (
                                <option key={tz.value} value={tz.value}>
                                    {tz.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Slack App ID
                        </label>
                        <input
                            type="text"
                            value={formData.slack_app_id || ''}
                            onChange={(e) => onChange('slack_app_id', e.target.value)}
                            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                            placeholder="A123456789"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Slack Client ID
                        </label>
                        <input
                            type="text"
                            value={formData.slack_client_id || ''}
                            onChange={(e) => onChange('slack_client_id', e.target.value)}
                            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                            placeholder="1234567890.1234567890"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Slack Bot Token
                        </label>
                        {mode === 'edit' && !showBotTokenInput ? (
                            <div className="flex items-center justify-between border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                                <span className="text-gray-500 font-mono">{formData.has_slack_bot_token ? '********' : 'Not set'}</span>
                                <button
                                    type="button"
                                    onClick={() => setShowBotTokenInput(true)}
                                    className="text-[#0A2E4D] hover:text-[#4BBAC4] flex items-center gap-1 text-xs font-medium"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536M16.732 3.732a2.5 2.5 0 113.536 3.536L7.5 20.036H4v-3.572L16.732 3.732z" />
                                    </svg>
                                    Edit
                                </button>
                            </div>
                        ) : (
                            <input
                                type="password"
                                value={formData.slack_bot_token}
                                onChange={(e) => onChange('slack_bot_token', e.target.value)}
                                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                placeholder="xoxb-***"
                            />
                        )}
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Slack Signing Secret
                        </label>
                        {mode === 'edit' && !showSigningSecretInput ? (
                            <div className="flex items-center justify-between border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                                <span className="text-gray-500 font-mono">{formData.has_slack_signing_secret ? '********' : 'Not set'}</span>
                                <button
                                    type="button"
                                    onClick={() => setShowSigningSecretInput(true)}
                                    className="text-[#0A2E4D] hover:text-[#4BBAC4] flex items-center gap-1 text-xs font-medium"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536M16.732 3.732a2.5 2.5 0 113.536 3.536L7.5 20.036H4v-3.572L16.732 3.732z" />
                                    </svg>
                                    Edit
                                </button>
                            </div>
                        ) : (
                            <input
                                type="password"
                                value={formData.slack_signing_secret}
                                onChange={(e) => onChange('slack_signing_secret', e.target.value)}
                                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                placeholder="****"
                            />
                        )}
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Slack Verification Token
                        </label>
                        {mode === 'edit' && !showVerificationTokenInput ? (
                            <div className="flex items-center justify-between border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50">
                                <span className="text-gray-500 font-mono">{formData.has_slack_verification_token ? '********' : 'Not set'}</span>
                                <button
                                    type="button"
                                    onClick={() => setShowVerificationTokenInput(true)}
                                    className="text-[#0A2E4D] hover:text-[#4BBAC4] flex items-center gap-1 text-xs font-medium"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536M16.732 3.732a2.5 2.5 0 113.536 3.536L7.5 20.036H4v-3.572L16.732 3.732z" />
                                    </svg>
                                    Edit
                                </button>
                            </div>
                        ) : (
                            <input
                                type="password"
                                value={formData.slack_verification_token}
                                onChange={(e) => onChange('slack_verification_token', e.target.value)}
                                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                placeholder="****"
                            />
                        )}
                    </div>
                </div>
                <div>
                    <p className="text-xs text-gray-500 mt-1">SLACK credentials are stored encrypted in the database.</p>
                    {saveError && (
                        <p className="text-xs text-red-600 mt-2">{saveError}</p>
                    )}
                </div>
            </div>
            {mode === 'edit' && tenantId && (
                <div className="px-6 py-4 border-t border-gray-200">
                    <InstallationInstructions tenantId={tenantId} />
                </div>
            )}
            <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-end gap-2">
                <button
                    type="button"
                    onClick={onCancel}
                    className="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-all cursor-pointer"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={!isFormValid || isSaving}
                    className={`px-4 py-2 text-sm font-medium rounded-lg transition-all cursor-pointer ${
                        isFormValid && !isSaving
                            ? 'bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white hover:shadow-lg'
                            : 'bg-gray-200 text-gray-500 cursor-not-allowed'
                    }`}
                >
                    {isSaving ? 'Saving...' : 'Save'}
                </button>
            </div>
        </form>
    );
}
