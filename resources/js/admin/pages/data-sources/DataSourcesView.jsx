import React, { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { useMatch, useNavigate } from 'react-router-dom';
import TenantSidebar from '../../components/TenantSidebar';
import { useToast } from '../../components/ToastProvider';
import { timezones } from '../../../constants/timezones';
import { useTenantsStore } from '../../stores/tenantsStore';

const defaultFormData = {
    label: '',
    default_limit: '',
    query_timeout_seconds: '',
    timezone: '',
    dsn_protocol: 'mysql',
    dsn_username: '',
    dsn_password: '',
    dsn_host: '',
    dsn_port: '',
    dsn_database: '',
    // SSH tunnel fields
    use_ssh: false,
    ssh_host: '',
    ssh_port: '',
    ssh_username: '',
    ssh_password: '',
    ssh_private_key: '',
    ssh_private_key_filename: '',
    ssh_public_key: '',
    // Meta — tracks what's already saved on the server (not sent to API)
    ssh_has_existing_password: false,
    ssh_has_existing_key: false,
};

export default function DataSourcesView() {
    const { tenants, isLoading: isLoadingTenants, error: tenantsError, fetchTenants } = useTenantsStore();
    const [datasources, setDatasources] = useState([]);
    const [activeTenantUuid, setActiveTenantUuid] = useState(() => localStorage.getItem('activeTenant') || null);
    const [isLoadingDatasources, setIsLoadingDatasources] = useState(false);
    const [dataError, setDataError] = useState(null);
    const [saveError, setSaveError] = useState(null);
    const [isSaving, setIsSaving] = useState(false);
    const [mode, setMode] = useState('view'); // view | edit | add
    const [formData, setFormData] = useState(null);
    const [showDsnFields, setShowDsnFields] = useState(false);
    const navigate = useNavigate();
    const viewMatch = useMatch('/panel/data-sources/:tenantId');

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
            setDatasources([]);
            setMode('view');
            setFormData(null);
            return;
        }

        const tenant = tenants.find((item) => item.uuid === activeTenantUuid);
        if (!tenant) {
            setDatasources([]);
            setMode('view');
            setFormData(null);
            return;
        }

        fetchDatasources(tenant.id);
    }, [activeTenantUuid, tenants]);

    const fetchDatasources = async (tenantId) => {
        try {
            setIsLoadingDatasources(true);
            const response = await axios.get(`/api/admin/tenants/${tenantId}/datasources`);
            const nextDatasources = response.data.data || [];
            setDatasources(nextDatasources);

            if (nextDatasources.length === 0) {
                setMode('add');
                setFormData({ ...defaultFormData });
                setShowDsnFields(true);
            } else {
                setMode('view');
                setFormData(null);
                setShowDsnFields(false);
            }
            setDataError(null);
            setSaveError(null);
        } catch (err) {
            setDataError('Failed to load data sources');
        } finally {
            setIsLoadingDatasources(false);
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

    const selectedDatasource = datasources[0] || null;

    const startEdit = () => {
        if (!selectedDatasource) {
            return;
        }

        setMode('edit');
        setFormData({
            ...defaultFormData,
            label: selectedDatasource.label || '',
            default_limit: selectedDatasource.default_limit ?? '',
            query_timeout_seconds: selectedDatasource.query_timeout_seconds ?? '',
            timezone: selectedDatasource.timezone || '',
            // SSH — load display fields; never pre-fill secrets
            use_ssh: selectedDatasource.use_ssh || false,
            ssh_host: selectedDatasource.ssh_host || '',
            ssh_port: selectedDatasource.ssh_port ?? '',
            ssh_username: selectedDatasource.ssh_username || '',
            ssh_public_key: selectedDatasource.ssh_public_key || '',
            ssh_has_existing_password: selectedDatasource.has_ssh_password || false,
            ssh_has_existing_key: selectedDatasource.has_ssh_private_key || false,
        });
        setSaveError(null);
        setShowDsnFields(false);
    };

    const handleFormChange = (field, value) => {
        if (!formData) {
            return;
        }

        setSaveError(null);
        setFormData((prev) => ({
            ...prev,
            [field]: value,
        }));
    };

    const handleCancel = () => {
        if (selectedDatasource) {
            setMode('view');
            setFormData(null);
            setShowDsnFields(false);
            return;
        }

        setMode('add');
        setFormData({ ...defaultFormData });
        setShowDsnFields(true);
    };

    const extractErrorMessage = (err) => {
        if (err?.response?.data?.message) {
            return err.response.data.message;
        }
        if (err?.message) {
            return err.message;
        }
        return 'Failed to save data source';
    };

    const parseOptionalInt = (value) => {
        if (value === '' || value === null || value === undefined) {
            return undefined;
        }
        const parsed = Number(value);
        return Number.isNaN(parsed) ? undefined : parsed;
    };

    const buildPayload = (data, includeDsn) => {
        const payload = {
            label: data.label.trim(),
        };

        const defaultLimit = parseOptionalInt(data.default_limit);
        if (defaultLimit !== undefined) {
            payload.default_limit = defaultLimit;
        }

        const queryTimeout = parseOptionalInt(data.query_timeout_seconds);
        if (queryTimeout !== undefined) {
            payload.query_timeout_seconds = queryTimeout;
        }

        if (data.timezone) {
            payload.timezone = data.timezone;
        }

        if (includeDsn) {
            payload.driver = data.dsn_protocol;
            payload.host = data.dsn_host.trim();
            payload.port = Number(data.dsn_port);
            payload.database = data.dsn_database.trim();
            payload.username = data.dsn_username.trim();
            payload.password = data.dsn_password;
        }

        // SSH tunnel fields
        payload.use_ssh = data.use_ssh;
        if (data.use_ssh) {
            payload.ssh_host = data.ssh_host.trim();
            const sshPort = parseOptionalInt(data.ssh_port);
            if (sshPort !== undefined) {
                payload.ssh_port = sshPort;
            }
            payload.ssh_username = data.ssh_username.trim();
            if (data.ssh_password) {
                payload.ssh_password = data.ssh_password;
            }
            if (data.ssh_private_key) {
                payload.ssh_private_key = data.ssh_private_key;
            }
            if (data.ssh_public_key.trim()) {
                payload.ssh_public_key = data.ssh_public_key.trim();
            }
        }

        return payload;
    };

    const [saveStatus, setSaveStatus] = useState(null); // null | 'testing' | 'saving'

    const handleSave = async ({ includeDsn }) => {
        if (!selectedTenant || !formData || isSaving) {
            return;
        }

        try {
            setIsSaving(true);
            setSaveError(null);

            const payload = buildPayload(formData, includeDsn);

            // Test connection before saving when DSN fields are included
            if (includeDsn) {
                setSaveStatus('testing');
                try {
                    await axios.post(
                        `/api/admin/tenants/${selectedTenant.id}/datasources/test-connection`,
                        payload
                    );
                } catch (testErr) {
                    const errorDetail = testErr?.response?.data?.data?.error;
                    setSaveError(
                        errorDetail
                            ? `Connection failed: ${errorDetail}`
                            : 'Connection test failed. Please check your credentials.'
                    );
                    setIsSaving(false);
                    setSaveStatus(null);
                    return;
                }
            }

            setSaveStatus('saving');
            let response;

            if (mode === 'add') {
                response = await axios.post(`/api/admin/tenants/${selectedTenant.id}/datasources`, payload);
            } else if (mode === 'edit' && selectedDatasource) {
                response = await axios.put(
                    `/api/admin/tenants/${selectedTenant.id}/datasources/${selectedDatasource.id}`,
                    payload
                );
            } else {
                setIsSaving(false);
                setSaveStatus(null);
                return;
            }

            const savedDatasource = response.data?.data || response.data;
            setDatasources(savedDatasource ? [savedDatasource] : []);
            setMode('view');
            setFormData(null);
            setShowDsnFields(false);
        } catch (err) {
            setSaveError(extractErrorMessage(err));
        } finally {
            setIsSaving(false);
            setSaveStatus(null);
        }
    };

    const isLoading = isLoadingTenants || isLoadingDatasources;
    const sidebarError = tenantsError || dataError;

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
                    <div className="text-gray-500">Select a tenant to manage data sources.</div>
                ) : isLoading ? (
                    <div className="text-gray-500">Loading data sources...</div>
                ) : mode === 'add' && formData ? (
                    <DataSourceForm
                        mode="add"
                        formData={formData}
                        showDsnFields
                        onChange={handleFormChange}
                        onCancel={handleCancel}
                        onSave={handleSave}
                        isSaving={isSaving}
                        saveStatus={saveStatus}
                        saveError={saveError}
                    />
                ) : mode === 'edit' && formData && selectedDatasource ? (
                    <DataSourceForm
                        mode="edit"
                        formData={formData}
                        showDsnFields={showDsnFields}
                        hasDsn={Boolean(selectedDatasource.has_dsn)}
                        onChange={handleFormChange}
                        onCancel={handleCancel}
                        onToggleDsn={() => setShowDsnFields(true)}
                        onSave={handleSave}
                        isSaving={isSaving}
                        saveStatus={saveStatus}
                        saveError={saveError}
                    />
                ) : selectedDatasource ? (
                    <DataSourceCard datasource={selectedDatasource} tenant={selectedTenant} onEdit={startEdit} />
                ) : (
                    <div className="text-gray-500">No data sources found.</div>
                )}
            </div>
        </div>
    );
}

function DataSourceCard({ datasource, tenant, onEdit }) {
    const { showToast } = useToast();
    const [isTesting, setIsTesting] = useState(false);

    const handleTest = async () => {
        if (!tenant || isTesting) {
            return;
        }

        try {
            setIsTesting(true);
            await axios.get(`/api/admin/tenants/${tenant.id}/datasources/${datasource.id}/ping`);
            showToast({ state: 'success', message: 'Connection successful' });
        } catch {
            showToast({ state: 'error', message: 'Connection failed' });
        } finally {
            setIsTesting(false);
        }
    };

    return (
        <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
            <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 className="text-lg font-semibold text-[#0A2E4D]">{datasource.label}</h3>
                <div className="flex items-center gap-2">
                    {datasource.has_dsn && (
                        <button
                            onClick={handleTest}
                            disabled={isTesting}
                            className={`px-4 py-2 text-sm font-medium rounded-lg transition-all cursor-pointer border ${
                                isTesting
                                    ? 'border-gray-300 text-gray-400 cursor-not-allowed'
                                    : 'border-[#4BBAC4] text-[#0A2E4D] hover:bg-[#4BBAC4]/10'
                            }`}
                        >
                            {isTesting ? 'Testing...' : 'Test'}
                        </button>
                    )}
                    <button
                        onClick={onEdit}
                        className="px-4 py-2 bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white text-sm font-medium rounded-lg hover:shadow-lg transition-all cursor-pointer"
                    >
                        Edit
                    </button>
                </div>
            </div>
            <div className="px-6 py-4 space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Tenant ID
                        </label>
                        <p className="text-sm text-gray-900">{datasource.tenant_id}</p>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            DSN
                        </label>
                        <p className="text-sm text-gray-900">
                            {datasource.has_dsn ? (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Configured
                                </span>
                            ) : (
                                <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                    Not set
                                </span>
                            )}
                        </p>
                    </div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Default Limit
                        </label>
                        <p className="text-sm text-gray-900">{datasource.default_limit ?? <span className="text-gray-400 italic">Not set</span>}</p>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Query Timeout
                        </label>
                        <p className="text-sm text-gray-900">
                            {datasource.query_timeout_seconds ?? <span className="text-gray-400 italic">Not set</span>}
                        </p>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Timezone
                        </label>
                        <p className="text-sm text-gray-900">{datasource.timezone || <span className="text-gray-400 italic">Not set</span>}</p>
                    </div>
                </div>
                <div>
                    <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                        SSH Tunnel
                    </label>
                    {datasource.use_ssh ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                Enabled
                            </span>
                            {datasource.ssh_host && (
                                <span className="text-xs text-gray-600 font-mono">
                                    {datasource.ssh_username ? `${datasource.ssh_username}@` : ''}{datasource.ssh_host}:{datasource.ssh_port || 22}
                                </span>
                            )}
                        </div>
                    ) : (
                        <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                            Disabled
                        </span>
                    )}
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-gray-100">
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Created
                        </label>
                        <p className="text-sm text-gray-600">
                            {datasource.created_at ? new Date(datasource.created_at).toLocaleDateString('sv-SE') : '—'}
                        </p>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">
                            Updated
                        </label>
                        <p className="text-sm text-gray-600">
                            {datasource.updated_at ? new Date(datasource.updated_at).toLocaleDateString('sv-SE') : '—'}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

function DataSourceForm({
    mode,
    formData,
    showDsnFields,
    hasDsn = false,
    onChange,
    onCancel,
    onToggleDsn,
    onSave,
    isSaving = false,
    saveStatus = null,
    saveError = null,
}) {
    const sshKeyInputRef = useRef(null);

    const isDsnRequired = mode === 'add' || showDsnFields;
    const isDsnComplete = Boolean(
        formData.dsn_protocol.trim() &&
        formData.dsn_username.trim() &&
        (!isDsnRequired || formData.dsn_password.trim()) &&
        formData.dsn_host.trim() &&
        formData.dsn_port.toString().trim() &&
        formData.dsn_database.trim()
    );

    // SSH is valid when disabled, or when enabled with all required fields + at least one auth method
    const sshHasAuth =
        formData.ssh_password.trim() ||
        formData.ssh_private_key ||
        formData.ssh_has_existing_password ||
        formData.ssh_has_existing_key;
    const isSshValid = !formData.use_ssh || Boolean(
        formData.ssh_host.trim() &&
        formData.ssh_username.trim() &&
        sshHasAuth
    );

    const isFormValid = Boolean(formData.label.trim() && (!isDsnRequired || isDsnComplete) && isSshValid);

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!isFormValid || isSaving) {
            return;
        }
        onSave?.({ includeDsn: isDsnRequired });
    };

    const handleSshKeyUpload = (e) => {
        const file = e.target.files?.[0];
        if (!file) {
            return;
        }
        const reader = new FileReader();
        reader.onload = (ev) => {
            onChange('ssh_private_key', ev.target.result);
            onChange('ssh_private_key_filename', file.name);
        };
        reader.readAsText(file);
        // Reset so the same file can be re-selected if needed
        e.target.value = '';
    };

    const timezoneOptions = useMemo(() => {
        if (!formData?.timezone || timezones.find((tz) => tz.value === formData.timezone)) {
            return timezones;
        }
        return [
            { value: formData.timezone, label: `Custom: ${formData.timezone}`, offset: null },
            ...timezones,
        ];
    }, [formData?.timezone]);

    const dsnPreview = isDsnComplete
        ? `${formData.dsn_protocol}://${formData.dsn_username}${formData.dsn_password ? `:${formData.dsn_password}` : ''}@${formData.dsn_host}:${formData.dsn_port}/${formData.dsn_database}`
        : '';

    const inputClass = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]';
    const labelClass = 'block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1';

    return (
        <form onSubmit={handleSubmit} className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
            <div className="px-6 py-4 border-b border-gray-200">
                <p className="text-xs uppercase tracking-wide text-gray-500">{mode === 'add' ? 'Add Data Source' : 'Edit Data Source'}</p>
                <h3 className="text-lg font-semibold text-[#0A2E4D]">{formData.label || 'New Data Source'}</h3>
            </div>

            <div className="px-6 py-4 space-y-5">
                {/* Label */}
                <div>
                    <label className={labelClass}>Label</label>
                    <input
                        type="text"
                        value={formData.label}
                        onChange={(e) => onChange('label', e.target.value)}
                        className={inputClass}
                        placeholder="e.g., Primary Warehouse"
                        required
                    />
                </div>

                {/* DSN Section */}
                {mode === 'edit' && !showDsnFields ? (
                    <div className="border border-gray-200 rounded-lg px-3 py-3 bg-gray-50 flex items-center justify-between">
                        <div className="text-sm text-gray-600">
                            {hasDsn ? 'DSN configured.' : 'No DSN configured yet.'}
                        </div>
                        <button
                            type="button"
                            onClick={onToggleDsn}
                            className="text-[#0A2E4D] hover:text-[#4BBAC4] flex items-center gap-1 text-xs font-medium"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536M16.732 3.732a2.5 2.5 0 113.536 3.536L7.5 20.036H4v-3.572L16.732 3.732z" />
                            </svg>
                            Set new DSN
                        </button>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className={labelClass}>Protocol</label>
                                <select
                                    value={formData.dsn_protocol}
                                    onChange={(e) => onChange('dsn_protocol', e.target.value)}
                                    className={`${inputClass} bg-white`}
                                >
                                    <option value="mysql">mysql</option>
                                    <option value="pgsql">postgres</option>
                                </select>
                            </div>
                            <div>
                                <label className={labelClass}>Host</label>
                                <input
                                    type="text"
                                    value={formData.dsn_host}
                                    onChange={(e) => onChange('dsn_host', e.target.value)}
                                    className={inputClass}
                                    placeholder="db.example.com"
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label className={labelClass}>Username</label>
                                <input
                                    type="text"
                                    value={formData.dsn_username}
                                    onChange={(e) => onChange('dsn_username', e.target.value)}
                                    className={inputClass}
                                    placeholder="root"
                                />
                            </div>
                            <div>
                                <label className={labelClass}>Password</label>
                                <input
                                    type="password"
                                    value={formData.dsn_password}
                                    onChange={(e) => onChange('dsn_password', e.target.value)}
                                    className={inputClass}
                                    placeholder="••••••"
                                />
                            </div>
                            <div>
                                <label className={labelClass}>Port</label>
                                <input
                                    type="number"
                                    value={formData.dsn_port}
                                    onChange={(e) => onChange('dsn_port', e.target.value)}
                                    className={inputClass}
                                    placeholder="3306"
                                />
                            </div>
                        </div>
                        <div>
                            <label className={labelClass}>Database Name</label>
                            <input
                                type="text"
                                value={formData.dsn_database}
                                onChange={(e) => onChange('dsn_database', e.target.value)}
                                className={inputClass}
                                placeholder="demo_db"
                            />
                            {dsnPreview ? (
                                <p className="text-xs text-gray-500 mt-2 font-mono break-all">{dsnPreview}</p>
                            ) : null}
                        </div>
                    </div>
                )}

                {/* Configuration */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label className={labelClass}>Default Limit</label>
                        <input
                            type="number"
                            value={formData.default_limit}
                            onChange={(e) => onChange('default_limit', e.target.value)}
                            className={inputClass}
                            placeholder="100"
                        />
                    </div>
                    <div>
                        <label className={labelClass}>Query Timeout (sec)</label>
                        <input
                            type="number"
                            value={formData.query_timeout_seconds}
                            onChange={(e) => onChange('query_timeout_seconds', e.target.value)}
                            className={inputClass}
                            placeholder="30"
                        />
                    </div>
                    <div>
                        <label className={labelClass}>Timezone</label>
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

                {/* SSH Tunnel Section */}
                <div className="border border-gray-200 rounded-lg overflow-hidden">
                    {/* Header / Toggle */}
                    <div className="px-4 py-3 bg-gray-50 flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-gray-800">SSH Tunnel</p>
                            <p className="text-xs text-gray-500 mt-0.5">Connect to the database through an SSH bastion host</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => onChange('use_ssh', !formData.use_ssh)}
                            className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                                formData.use_ssh ? 'bg-[#4BBAC4]' : 'bg-gray-300'
                            }`}
                            role="switch"
                            aria-checked={formData.use_ssh}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                                    formData.use_ssh ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>

                    {formData.use_ssh && (
                        <div className="px-4 pb-5 pt-4 space-y-4 border-t border-gray-200">
                            {/* Bastion host */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div className="sm:col-span-2">
                                    <label className={labelClass}>SSH Host *</label>
                                    <input
                                        type="text"
                                        value={formData.ssh_host}
                                        onChange={(e) => onChange('ssh_host', e.target.value)}
                                        className={inputClass}
                                        placeholder="bastion.example.com"
                                    />
                                </div>
                                <div>
                                    <label className={labelClass}>SSH Port</label>
                                    <input
                                        type="number"
                                        value={formData.ssh_port}
                                        onChange={(e) => onChange('ssh_port', e.target.value)}
                                        className={inputClass}
                                        placeholder="22"
                                    />
                                </div>
                            </div>

                            {/* Username */}
                            <div>
                                <label className={labelClass}>SSH Username *</label>
                                <input
                                    type="text"
                                    value={formData.ssh_username}
                                    onChange={(e) => onChange('ssh_username', e.target.value)}
                                    className={inputClass}
                                    placeholder="ec2-user"
                                />
                            </div>

                            {/* Authentication */}
                            <div className="border-t border-gray-100 pt-4 space-y-4">
                                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                    Authentication — provide a password or private key
                                </p>

                                {/* SSH Password */}
                                <div>
                                    <label className={labelClass}>
                                        SSH Password
                                        {formData.ssh_has_existing_password && (
                                            <span className="ml-2 normal-case font-normal text-gray-400">
                                                (leave blank to keep existing)
                                            </span>
                                        )}
                                    </label>
                                    <input
                                        type="password"
                                        value={formData.ssh_password}
                                        onChange={(e) => onChange('ssh_password', e.target.value)}
                                        className={inputClass}
                                        placeholder={formData.ssh_has_existing_password ? '••••••' : 'SSH password'}
                                        autoComplete="new-password"
                                    />
                                </div>

                                {/* SSH Private Key Upload */}
                                <div>
                                    <label className={labelClass}>SSH Private Key</label>
                                    {formData.ssh_private_key ? (
                                        // New key loaded from file
                                        <div className="flex items-center gap-2 px-3 py-2 bg-green-50 border border-green-200 rounded-lg">
                                            <svg className="w-4 h-4 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span className="text-sm text-green-800 flex-1 truncate font-mono">
                                                {formData.ssh_private_key_filename || 'Key loaded'}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    onChange('ssh_private_key', '');
                                                    onChange('ssh_private_key_filename', '');
                                                }}
                                                className="text-xs text-red-500 hover:text-red-700 flex-shrink-0"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    ) : formData.ssh_has_existing_key ? (
                                        // Key already on server, no new file selected
                                        <div className="flex items-center gap-3">
                                            <div className="flex items-center gap-2 px-3 py-2 bg-blue-50 border border-blue-200 rounded-lg flex-1">
                                                <svg className="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                                </svg>
                                                <span className="text-sm text-blue-800">Key already configured</span>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => sshKeyInputRef.current?.click()}
                                                className="text-xs text-[#0A2E4D] hover:text-[#4BBAC4] font-medium flex-shrink-0"
                                            >
                                                Replace
                                            </button>
                                        </div>
                                    ) : (
                                        // No key at all
                                        <button
                                            type="button"
                                            onClick={() => sshKeyInputRef.current?.click()}
                                            className="flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-[#4BBAC4] hover:text-[#4BBAC4] transition-colors w-full"
                                        >
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                            </svg>
                                            Upload private key file
                                        </button>
                                    )}
                                    <input
                                        ref={sshKeyInputRef}
                                        type="file"
                                        accept=".pem,.key,.txt,*"
                                        onChange={handleSshKeyUpload}
                                        className="hidden"
                                    />
                                </div>

                                {/* Validation hint */}
                                {formData.use_ssh && !sshHasAuth && (
                                    <p className="text-xs text-amber-600">
                                        Provide a password or upload a private key file to authenticate.
                                    </p>
                                )}
                            </div>

                            {/* Public Key — informational */}
                            <div className="border-t border-gray-100 pt-4">
                                <label className={labelClass}>
                                    Public Key
                                    <span className="ml-2 normal-case font-normal text-gray-400">(optional)</span>
                                </label>
                                <textarea
                                    value={formData.ssh_public_key}
                                    onChange={(e) => onChange('ssh_public_key', e.target.value)}
                                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[#4BBAC4] resize-none"
                                    rows={3}
                                    placeholder="ssh-rsa AAAA..."
                                />
                                <p className="text-xs text-gray-400 italic mt-1">
                                    For reference only — not used for authentication.
                                </p>
                            </div>
                        </div>
                    )}
                </div>

                {saveError ? (
                    <p className="text-xs text-red-600">{saveError}</p>
                ) : null}
            </div>

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
                    {saveStatus === 'testing' ? 'Testing connection...' : isSaving ? 'Saving...' : 'Save'}
                </button>
            </div>
        </form>
    );
}
