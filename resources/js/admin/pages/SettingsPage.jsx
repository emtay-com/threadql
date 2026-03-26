import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { useLocation, useNavigate } from 'react-router-dom';
import { ToastProvider, useToast } from '../components/ToastProvider';
import { SETTING_TYPES as GENERAL_SETTING_TYPES } from '../constants/generalSettings';
import { decodeToken } from '../../services/token';

const defaultUserForm = {
    tenant_id: '',
    username: '',
    email: '',
    password: '',
};

function isMasterAdmin() {
    const token = sessionStorage.getItem('admin_token');
    const decoded = decodeToken(token);

    return Boolean(decoded?.is_master || decoded?.level === 'master');
}

function SettingsPageContent() {
    const navigate = useNavigate();
    const location = useLocation();
    const { showToast } = useToast();
    const [isMaster] = useState(() => isMasterAdmin());

    const [generalSettings, setGeneralSettings] = useState([]);
    const [isLoadingGeneralSettings, setIsLoadingGeneralSettings] = useState(false);
    const [generalSettingsError, setGeneralSettingsError] = useState(null);
    const [generalActionError, setGeneralActionError] = useState(null);
    const [isSavingGeneralSettings, setIsSavingGeneralSettings] = useState(false);
    const [generalDirty, setGeneralDirty] = useState({});

    const [users, setUsers] = useState([]);
    const [tenants, setTenants] = useState([]);
    const [isLoadingUsers, setIsLoadingUsers] = useState(false);
    const [error, setError] = useState(null);
    const [isSaving, setIsSaving] = useState(false);
    const [editingUserId, setEditingUserId] = useState(null);
    const [formData, setFormData] = useState({ ...defaultUserForm });

    const activeSection = useMemo(() => {
        if (location.pathname === '/panel/settings/users') {
            return 'users';
        }

        return 'general';
    }, [location.pathname]);

    const sections = useMemo(() => {
        if (!isMaster) {
            return [];
        }

        const items = [
            { id: 'general', label: 'General', path: '/panel/settings' },
        ];

        items.push({ id: 'users', label: 'Users', path: '/panel/settings/users' });

        return items;
    }, [isMaster]);

    useEffect(() => {
        if (!isMaster) {
            navigate('/panel/tenants', { replace: true });
        }
    }, [isMaster, navigate]);

    useEffect(() => {
        if (activeSection !== 'general' || !isMaster) {
            return;
        }

        void loadGeneralSettings();
    }, [activeSection, isMaster]);

    useEffect(() => {
        if (activeSection !== 'users' || !isMaster) {
            return;
        }

        void loadUsersAndTenants();
    }, [activeSection, isMaster]);

    const loadGeneralSettings = async () => {
        try {
            setIsLoadingGeneralSettings(true);
            setGeneralSettingsError(null);

            const response = await axios.get('/api/admin/settings');
            const items = (response.data?.data || []).map((setting) => ({
                ...setting,
                value: setting.value ?? '',
            }));

            setGeneralSettings(items);
            setGeneralDirty({});
            setGeneralActionError(null);
        } catch (err) {
            setGeneralSettingsError('Failed to load settings');
        } finally {
            setIsLoadingGeneralSettings(false);
        }
    };

    const loadUsersAndTenants = async () => {
        try {
            setIsLoadingUsers(true);
            setError(null);

            const [usersResponse, tenantsResponse] = await Promise.all([
                axios.get('/api/admin/users'),
                axios.get('/api/admin/tenants'),
            ]);

            setUsers(usersResponse.data?.data || []);
            setTenants(tenantsResponse.data?.data || []);
        } catch (err) {
            setError('Failed to load users');
        } finally {
            setIsLoadingUsers(false);
        }
    };

    const resetForm = () => {
        setEditingUserId(null);
        setFormData({ ...defaultUserForm });
    };

    const handleGeneralValueChange = (settingName, value) => {
        setGeneralSettings((prev) => prev.map((setting) => (
            setting.setting === settingName ? { ...setting, value } : setting
        )));
        setGeneralDirty((prev) => ({ ...prev, [settingName]: true }));
        setGeneralActionError(null);
    };

    const handleGeneralSave = async () => {
        if (isSavingGeneralSettings) {
            return;
        }

        try {
            setIsSavingGeneralSettings(true);
            setGeneralActionError(null);

            await axios.put('/api/admin/settings', {
                settings: generalSettings.map((setting) => ({
                    setting: setting.setting,
                    value: setting.value ?? '',
                })),
            });

            setGeneralDirty({});
            showToast({ state: 'success', message: 'Settings saved' });
        } catch (err) {
            setGeneralActionError('Failed to save settings');
        } finally {
            setIsSavingGeneralSettings(false);
        }
    };

    const handleSave = async (e) => {
        e.preventDefault();

        if (!formData.username || !formData.email || !formData.tenant_id) {
            setError('Username, email and tenant are required.');
            return;
        }

        if (!editingUserId && !formData.password) {
            setError('Password is required for new users.');
            return;
        }

        try {
            setIsSaving(true);
            setError(null);

            const payload = {
                username: formData.username,
                email: formData.email,
                tenant_id: Number(formData.tenant_id),
            };

            if (formData.password) {
                payload.password = formData.password;
            }

            let response;
            if (editingUserId) {
                response = await axios.put(`/api/admin/users/${editingUserId}`, payload);
            } else {
                response = await axios.post('/api/admin/users', payload);
            }

            const user = response.data?.data || response.data;
            setUsers((prev) => {
                const exists = prev.some((item) => item.id === user.id);
                if (!exists) {
                    return [user, ...prev];
                }

                return prev.map((item) => (item.id === user.id ? user : item));
            });

            resetForm();
        } catch (err) {
            setError(err?.response?.data?.error || 'Failed to save user');
        } finally {
            setIsSaving(false);
        }
    };

    const handleEdit = (user) => {
        setEditingUserId(user.id);
        setFormData({
            tenant_id: user.tenant_id || '',
            username: user.username || '',
            email: user.email || '',
            password: '',
        });
        setError(null);
    };

    const handleDelete = async (userId) => {
        if (!window.confirm('Delete this user?')) {
            return;
        }

        try {
            setError(null);
            await axios.delete(`/api/admin/users/${userId}`);
            setUsers((prev) => prev.filter((item) => item.id !== userId));
            if (editingUserId === userId) {
                resetForm();
            }
        } catch (err) {
            setError('Failed to delete user');
        }
    };

    const renderGeneralSettingInput = (setting) => {
        const config = GENERAL_SETTING_TYPES[setting.setting] || {
            type: 'text',
            label: setting.setting,
            description: '',
        };

        const timeParts = (setting.value || '').split(':');
        const scheduleHour = timeParts[0] || '00';
        const scheduleMinute = timeParts[1] || '00';

        if (config.type === 'boolean') {
            return (
                <button
                    type="button"
                    role="switch"
                    aria-checked={setting.value === '1'}
                    aria-label={config.label}
                    onClick={() =>
                        handleGeneralValueChange(setting.setting, setting.value === '1' ? '0' : '1')
                    }
                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors cursor-pointer ${
                        setting.value === '1' ? 'bg-[#4BBAC4]' : 'bg-gray-300'
                    }`}
                >
                    <span
                        className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                            setting.value === '1' ? 'translate-x-6' : 'translate-x-1'
                        }`}
                    />
                </button>
            );
        }

        if (config.type === 'numeric') {
            return (
                <input
                    type="number"
                    value={setting.value}
                    onChange={(e) => handleGeneralValueChange(setting.setting, e.target.value)}
                    aria-label={config.label}
                    className="w-24 border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                    min="0"
                />
            );
        }

        if (config.type === 'select') {
            return (
                <select
                    value={setting.value}
                    onChange={(e) => handleGeneralValueChange(setting.setting, e.target.value)}
                    aria-label={config.label}
                    className="min-w-48 border border-gray-300 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                >
                    {(config.options || []).map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            );
        }

        if (config.type === 'time_schedule') {
            return (
                <div className="flex items-center gap-2">
                    <select
                        value={scheduleHour}
                        onChange={(e) =>
                            handleGeneralValueChange(setting.setting, `${e.target.value}:${scheduleMinute}`)
                        }
                        aria-label={`${config.label} hour`}
                        className="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                    >
                        {Array.from({ length: 24 }, (_, index) => (
                            <option key={index} value={String(index).padStart(2, '0')}>
                                {String(index).padStart(2, '0')}
                            </option>
                        ))}
                    </select>
                    <span className="text-gray-500 font-medium">:</span>
                    <select
                        value={scheduleMinute}
                        onChange={(e) =>
                            handleGeneralValueChange(setting.setting, `${scheduleHour}:${e.target.value}`)
                        }
                        aria-label={`${config.label} minute`}
                        className="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                    >
                        <option value="00">00</option>
                        <option value="30">30</option>
                    </select>
                </div>
            );
        }

        return (
            <input
                type="text"
                value={setting.value}
                onChange={(e) => handleGeneralValueChange(setting.setting, e.target.value)}
                aria-label={config.label}
                className="w-48 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
            />
        );
    };

    const hasDirtyGeneralSettings = Object.values(generalDirty).some(Boolean);

    if (!isMaster) {
        return null;
    }

    return (
        <div className="flex flex-col md:flex-row flex-1 overflow-hidden">
            <div className="md:hidden bg-white border-b border-gray-200 px-4 py-3">
                <div className="flex items-center gap-2 overflow-x-auto">
                    {sections.map((section) => (
                        <button
                            key={section.id}
                            type="button"
                            onClick={() => navigate(section.path)}
                            className={`px-3 py-2 text-sm rounded-lg whitespace-nowrap cursor-pointer ${
                                activeSection === section.id
                                    ? 'bg-[#0A2E4D] text-white'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                            }`}
                        >
                            {section.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="hidden md:flex w-64 bg-white border-r border-gray-200 h-full flex-col">
                <div className="p-4 border-b border-gray-200">
                    <h2 className="text-sm font-semibold text-gray-600 uppercase tracking-wide">Settings</h2>
                </div>

                <div className="flex-1 overflow-y-auto">
                    <ul className="py-2">
                        {sections.map((section) => (
                            <li key={section.id}>
                                <button
                                    type="button"
                                    onClick={() => navigate(section.path)}
                                    className={`w-full text-left px-4 py-2 text-sm transition-colors cursor-pointer ${
                                        activeSection === section.id
                                            ? 'bg-[#0A2E4D] text-white'
                                            : 'text-gray-700 hover:bg-gray-100'
                                    }`}
                                >
                                    {section.label}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="flex-1 p-5 md:p-8 overflow-y-auto">
                <h2 className="text-xl font-semibold text-gray-800 mb-4">Settings</h2>

                {activeSection === 'general' && (
                    <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-[#0A2E4D]">General Settings</h3>
                                <p className="text-xs text-gray-500">{generalSettings.length} total</p>
                            </div>
                        </div>
                        {isLoadingGeneralSettings ? (
                            <div className="px-6 py-6 text-gray-500">Loading settings...</div>
                        ) : generalSettingsError ? (
                            <div className="px-6 py-6 text-red-600">{generalSettingsError}</div>
                        ) : generalSettings.length === 0 ? (
                            <div className="px-6 py-6 text-gray-500">No settings found.</div>
                        ) : (
                            <div className="divide-y divide-gray-100">
                                {generalSettings.map((setting) => {
                                    const config = GENERAL_SETTING_TYPES[setting.setting] || {
                                        type: 'text',
                                        label: setting.setting,
                                        description: '',
                                    };

                                    return (
                                        <div
                                            key={setting.setting}
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
                                                {renderGeneralSettingInput(setting)}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                            <div>
                                {generalActionError && (
                                    <span className="text-sm text-red-600">{generalActionError}</span>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={handleGeneralSave}
                                disabled={isSavingGeneralSettings || !hasDirtyGeneralSettings}
                                className={`px-4 py-2 text-sm font-medium rounded-lg transition-all ${
                                    isSavingGeneralSettings || !hasDirtyGeneralSettings
                                        ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                                        : 'bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white hover:shadow-md cursor-pointer'
                                }`}
                            >
                                {isSavingGeneralSettings ? 'Saving...' : 'Save'}
                            </button>
                        </div>
                    </div>
                )}

                {activeSection === 'users' && isMaster && (
                    <div className="space-y-6">
                        <form onSubmit={handleSave} className="bg-white border border-gray-200 rounded-lg p-4 space-y-4">
                            <h3 className="text-sm font-semibold text-gray-800">
                                {editingUserId ? 'Edit User' : 'Create User'}
                            </h3>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Tenant</label>
                                    <select
                                        value={formData.tenant_id}
                                        onChange={(e) => setFormData((prev) => ({ ...prev, tenant_id: e.target.value }))}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white"
                                    >
                                        <option value="">Select tenant</option>
                                        {tenants.map((tenant) => (
                                            <option key={tenant.id} value={tenant.id}>
                                                {tenant.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Username</label>
                                    <input
                                        type="text"
                                        value={formData.username}
                                        onChange={(e) => setFormData((prev) => ({ ...prev, username: e.target.value }))}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-gray-500 uppercase mb-1">Email</label>
                                    <input
                                        type="email"
                                        value={formData.email}
                                        onChange={(e) => setFormData((prev) => ({ ...prev, email: e.target.value }))}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                                        {editingUserId ? 'Password (optional)' : 'Password'}
                                    </label>
                                    <input
                                        type="password"
                                        value={formData.password}
                                        onChange={(e) => setFormData((prev) => ({ ...prev, password: e.target.value }))}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                </div>
                            </div>

                            <div className="flex justify-end gap-2">
                                {editingUserId && (
                                    <button
                                        type="button"
                                        onClick={resetForm}
                                        className="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 cursor-pointer"
                                    >
                                        Cancel
                                    </button>
                                )}
                                <button
                                    type="submit"
                                    disabled={isSaving}
                                    className={`px-4 py-2 text-sm rounded-lg ${
                                        isSaving
                                            ? 'bg-gray-200 text-gray-500 cursor-not-allowed'
                                            : 'bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white cursor-pointer'
                                    }`}
                                >
                                    {isSaving ? 'Saving...' : editingUserId ? 'Update User' : 'Create User'}
                                </button>
                            </div>
                        </form>

                        <div className="bg-white border border-gray-200 rounded-lg">
                            <div className="px-4 py-3 border-b border-gray-200">
                                <h3 className="text-sm font-semibold text-gray-800">Users</h3>
                            </div>

                            {isLoadingUsers ? (
                                <div className="px-4 py-4 text-sm text-gray-500">Loading users...</div>
                            ) : users.length === 0 ? (
                                <div className="px-4 py-4 text-sm text-gray-500">No users found.</div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead>
                                            <tr className="bg-gray-50 text-left text-gray-500 uppercase text-xs">
                                                <th className="px-4 py-3">Username</th>
                                                <th className="px-4 py-3">Email</th>
                                                <th className="px-4 py-3">Tenant</th>
                                                <th className="px-4 py-3">Level</th>
                                                <th className="px-4 py-3">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {users.map((user) => (
                                                <tr key={user.id} className="border-t border-gray-100">
                                                    <td className="px-4 py-3 text-gray-800">{user.username}</td>
                                                    <td className="px-4 py-3 text-gray-700">{user.email}</td>
                                                    <td className="px-4 py-3 text-gray-700">{user.tenant_name || '-'}</td>
                                                    <td className="px-4 py-3 text-gray-700">{user.level}</td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-2">
                                                            <button
                                                                type="button"
                                                                aria-label={`Edit user ${user.username}`}
                                                                onClick={() => handleEdit(user)}
                                                                className="p-2 border border-gray-300 rounded text-gray-700 hover:bg-gray-50 cursor-pointer"
                                                            >
                                                                <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.5-9.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 8.5-8.5z" />
                                                                </svg>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                aria-label={`Delete user ${user.username}`}
                                                                onClick={() => handleDelete(user.id)}
                                                                className="p-2 border border-red-200 rounded text-red-600 hover:bg-red-50 cursor-pointer"
                                                            >
                                                                <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3M4 7h16" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>

                        {error && <p className="text-sm text-red-600">{error}</p>}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function SettingsPage() {
    return (
        <ToastProvider>
            <SettingsPageContent />
        </ToastProvider>
    );
}
