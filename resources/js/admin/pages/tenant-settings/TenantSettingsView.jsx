import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { useMatch, useNavigate } from 'react-router-dom';
import TenantSidebar from '../../components/TenantSidebar';
import { useToast } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';
import { SETTING_TYPES } from '../../constants/tenantSettings';

export default function TenantSettingsView() {
    const { tenants, isLoading: isLoadingTenants, error: tenantsError, fetchTenants } = useTenantsStore();
    const [settings, setSettings] = useState([]);
    const [activeTenantUuid, setActiveTenantUuid] = useState(() => localStorage.getItem('activeTenant') || null);
    const [isLoadingSettings, setIsLoadingSettings] = useState(false);
    const [settingsError, setSettingsError] = useState(null);
    const [actionError, setActionError] = useState(null);
    const [isSaving, setIsSaving] = useState(false);
    const [dirty, setDirty] = useState({});
    const navigate = useNavigate();
    const viewMatch = useMatch('/panel/tenant-settings/:tenantId');
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
            setSettings([]);
            return;
        }

        const tenant = tenants.find((item) => item.uuid === activeTenantUuid);
        if (!tenant) {
            setSettings([]);
            return;
        }

        fetchSettings(tenant.id);
    }, [activeTenantUuid, tenants]);

    const fetchSettings = async (tenantId) => {
        try {
            setIsLoadingSettings(true);
            const response = await axios.get(`/api/admin/tenants/${tenantId}/settings`);
            setSettings(response.data.data || []);
            setDirty({});
            setSettingsError(null);
        } catch (err) {
            setSettingsError('Failed to load settings');
        } finally {
            setIsLoadingSettings(false);
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

    const handleValueChange = (name, value) => {
        setSettings((prev) => prev.map((s) => (s.name === name ? { ...s, value } : s)));
        setDirty((prev) => ({ ...prev, [name]: true }));
        setActionError(null);
    };

    const handleSave = async () => {
        if (!selectedTenant || isSaving) {
            return;
        }

        try {
            setIsSaving(true);
            setActionError(null);
            const payload = {
                settings: settings.map((s) => ({ name: s.name, value: s.value })),
            };
            await axios.put(`/api/admin/tenants/${selectedTenant.id}/settings`, payload);
            setDirty({});
            showToast({ state: 'success', message: 'Settings saved' });
        } catch (err) {
            setActionError('Failed to save settings');
        } finally {
            setIsSaving(false);
        }
    };

    const hasDirtySettings = Object.values(dirty).some(Boolean);
    const isLoading = isLoadingTenants || isLoadingSettings;
    const sidebarError = tenantsError || settingsError;

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
                    <div className="text-gray-500">Select a tenant to view settings.</div>
                ) : isLoading ? (
                    <div className="text-gray-500">Loading settings...</div>
                ) : (
                    <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-[#0A2E4D]">Settings</h3>
                                <p className="text-xs text-gray-500">{settings.length} total</p>
                            </div>
                        </div>
                        {settings.length === 0 ? (
                            <div className="px-6 py-6 text-gray-500">No settings found.</div>
                        ) : (
                            <div className="divide-y divide-gray-100">
                                {settings.map((setting) => {
                                    const config = SETTING_TYPES[setting.name] || {
                                        type: 'text',
                                        label: setting.name,
                                        description: '',
                                    };

                                    const timeParts = (setting.value || '').split(':');
                                    const scheduleHour = timeParts[0] || '00';
                                    const scheduleMinute = timeParts[1] || '00';

                                    return (
                                        <div
                                            key={setting.name}
                                            className="px-6 py-4 flex items-center justify-between gap-4"
                                        >
                                            <div className="flex-1 min-w-0">
                                                <div className="text-sm font-medium text-gray-900">
                                                    {config.label}
                                                </div>
                                                {config.description && (
                                                    <div className="text-xs text-gray-500 mt-1">
                                                        {config.description}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex-shrink-0">
                                                {config.type === 'boolean' ? (
                                                    <button
                                                        type="button"
                                                        role="switch"
                                                        aria-checked={setting.value === '1'}
                                                        aria-label={config.label}
                                                        onClick={() =>
                                                            handleValueChange(
                                                                setting.name,
                                                                setting.value === '1' ? '0' : '1'
                                                            )
                                                        }
                                                        className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors cursor-pointer ${
                                                            setting.value === '1'
                                                                ? 'bg-[#4BBAC4]'
                                                                : 'bg-gray-300'
                                                        }`}
                                                    >
                                                        <span
                                                            className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                                                                setting.value === '1'
                                                                    ? 'translate-x-6'
                                                                    : 'translate-x-1'
                                                            }`}
                                                        />
                                                    </button>
                                                ) : config.type === 'numeric' ? (
                                                    <input
                                                        type="number"
                                                        value={setting.value}
                                                        onChange={(e) =>
                                                            handleValueChange(setting.name, e.target.value)
                                                        }
                                                        aria-label={config.label}
                                                        className="w-24 border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                        min="0"
                                                    />
                                                ) : config.type === 'time_schedule' ? (
                                                    <div className="flex items-center gap-2">
                                                        <select
                                                            value={scheduleHour}
                                                            onChange={(e) =>
                                                                handleValueChange(setting.name, `${e.target.value}:${scheduleMinute}`)
                                                            }
                                                            aria-label={`${config.label} hour`}
                                                            className="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                        >
                                                            {Array.from({ length: 24 }, (_, i) => (
                                                                <option key={i} value={String(i).padStart(2, '0')}>
                                                                    {String(i).padStart(2, '0')}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        <span className="text-gray-500 font-medium">:</span>
                                                        <select
                                                            value={scheduleMinute}
                                                            onChange={(e) =>
                                                                handleValueChange(setting.name, `${scheduleHour}:${e.target.value}`)
                                                            }
                                                            aria-label={`${config.label} minute`}
                                                            className="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                        >
                                                            <option value="00">00</option>
                                                            <option value="30">30</option>
                                                        </select>
                                                    </div>
                                                ) : (
                                                    <input
                                                        type="text"
                                                        value={setting.value}
                                                        onChange={(e) =>
                                                            handleValueChange(setting.name, e.target.value)
                                                        }
                                                        aria-label={config.label}
                                                        className="w-48 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                            <div>
                                {actionError && (
                                    <span className="text-sm text-red-600">{actionError}</span>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={handleSave}
                                disabled={isSaving || !hasDirtySettings}
                                className={`px-4 py-2 text-sm font-medium rounded-lg transition-all ${
                                    isSaving || !hasDirtySettings
                                        ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                                        : 'bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white hover:shadow-lg cursor-pointer'
                                }`}
                            >
                                {isSaving ? 'Saving...' : 'Save'}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
