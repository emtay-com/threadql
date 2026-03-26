import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { useMatch, useNavigate } from 'react-router-dom';
import TenantSidebar from '../../components/TenantSidebar';
import { useToast } from '../../components/ToastProvider';
import { useTenantsStore } from '../../stores/tenantsStore';

export default function SlackUsersView() {
    const { tenants, isLoading: isLoadingTenants, error: tenantsError, fetchTenants } = useTenantsStore();
    const [slackUsers, setSlackUsers] = useState([]);
    const [activeTenantUuid, setActiveTenantUuid] = useState(() => localStorage.getItem('activeTenant') || null);
    const [isLoadingUsers, setIsLoadingUsers] = useState(false);
    const [usersError, setUsersError] = useState(null);
    const [actionError, setActionError] = useState(null);
    const [editingUserId, setEditingUserId] = useState(null);
    const [editDraft, setEditDraft] = useState({});
    const [savingUserId, setSavingUserId] = useState(null);
    const [deletingUserId, setDeletingUserId] = useState(null);
    const [restoringUserId, setRestoringUserId] = useState(null);
    const navigate = useNavigate();
    const viewMatch = useMatch('/panel/slack-users/:tenantId');
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
            setSlackUsers([]);
            return;
        }

        const tenant = tenants.find((item) => item.uuid === activeTenantUuid);
        if (!tenant) {
            setSlackUsers([]);
            return;
        }

        fetchSlackUsers(tenant.id);
    }, [activeTenantUuid, tenants]);

    const fetchSlackUsers = async (tenantId) => {
        try {
            setIsLoadingUsers(true);
            const response = await axios.get(`/api/admin/tenants/${tenantId}/slack-users`);
            setSlackUsers(response.data.data || []);
            setUsersError(null);
        } catch (err) {
            setUsersError('Failed to load Slack users');
        } finally {
            setIsLoadingUsers(false);
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

    const startEdit = (user) => {
        setEditingUserId(user.id);
        setEditDraft({
            real_name: user.real_name || '',
            display_name: user.display_name || '',
        });
        setActionError(null);
    };

    const cancelEdit = () => {
        setEditingUserId(null);
        setEditDraft({});
        setActionError(null);
    };

    const handleSave = async (user) => {
        if (!selectedTenant || savingUserId) {
            return;
        }

        try {
            setSavingUserId(user.id);
            setActionError(null);
            await axios.put(`/api/admin/tenants/${selectedTenant.id}/slack-users/${user.id}`, {
                real_name: editDraft.real_name || null,
                display_name: editDraft.display_name || null,
            });
            setSlackUsers((prev) =>
                prev.map((item) =>
                    item.id === user.id
                        ? { ...item, real_name: editDraft.real_name || null, display_name: editDraft.display_name || null }
                        : item
                )
            );
            setEditingUserId(null);
            setEditDraft({});
            showToast({ state: 'success', message: 'User updated' });
        } catch (err) {
            setActionError('Failed to save user');
        } finally {
            setSavingUserId(null);
        }
    };

    const handleToggleApproved = async (user) => {
        if (!selectedTenant || savingUserId) {
            return;
        }

        try {
            setSavingUserId(user.id);
            setActionError(null);
            const newApproved = !user.approved;
            await axios.put(`/api/admin/tenants/${selectedTenant.id}/slack-users/${user.id}`, {
                approved: newApproved,
            });
            setSlackUsers((prev) =>
                prev.map((item) => (item.id === user.id ? { ...item, approved: newApproved } : item))
            );
            showToast({ state: 'success', message: newApproved ? 'User approved' : 'User approval revoked' });
        } catch (err) {
            setActionError('Failed to update approval status');
        } finally {
            setSavingUserId(null);
        }
    };

    const handleDelete = async (user) => {
        if (!selectedTenant || deletingUserId) {
            return;
        }

        if (!window.confirm(`Delete ${user.real_name || user.slack_user_id}?`)) {
            return;
        }

        try {
            setDeletingUserId(user.id);
            setActionError(null);
            await axios.delete(`/api/admin/tenants/${selectedTenant.id}/slack-users/${user.id}`);
            setSlackUsers((prev) =>
                prev.map((item) =>
                    item.id === user.id ? { ...item, deleted_at: new Date().toISOString() } : item
                )
            );
            if (editingUserId === user.id) {
                setEditingUserId(null);
                setEditDraft({});
            }
            showToast({ state: 'info', message: 'User deleted' });
        } catch (err) {
            setActionError('Failed to delete user');
        } finally {
            setDeletingUserId(null);
        }
    };

    const handleRestore = async (user) => {
        if (!selectedTenant || restoringUserId) {
            return;
        }

        try {
            setRestoringUserId(user.id);
            setActionError(null);
            await axios.patch(`/api/admin/tenants/${selectedTenant.id}/slack-users/${user.id}`);
            setSlackUsers((prev) =>
                prev.map((item) => (item.id === user.id ? { ...item, deleted_at: null } : item))
            );
            showToast({ state: 'success', message: 'User restored' });
        } catch (err) {
            setActionError('Failed to restore user');
        } finally {
            setRestoringUserId(null);
        }
    };

    const isLoading = isLoadingTenants || isLoadingUsers;
    const sidebarError = tenantsError || usersError;

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
                    <div className="text-gray-500">Select a tenant to view Slack users.</div>
                ) : isLoading ? (
                    <div className="text-gray-500">Loading Slack users...</div>
                ) : slackUsers.length === 0 ? (
                    <div className="text-gray-500">No Slack users yet.</div>
                ) : (
                    <div className="bg-white rounded-lg shadow-md border border-gray-200 w-full md:w-3/4">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-[#0A2E4D]">Slack Users</h3>
                                <p className="text-xs text-gray-500">{slackUsers.length} total</p>
                            </div>
                        </div>
                        <ul className="divide-y divide-gray-100">
                            {slackUsers.map((user) => {
                                const isEditing = editingUserId === user.id;
                                const isSaving = savingUserId === user.id;
                                const isDeleting = deletingUserId === user.id;
                                const isRestoring = restoringUserId === user.id;
                                const isDeleted = Boolean(user.deleted_at);
                                return (
                                    <li
                                        key={user.id}
                                        className={`px-6 py-4 grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] items-center gap-4 ${
                                            isDeleted ? 'opacity-50' : ''
                                        }`}
                                    >
                                        <div className="min-w-0">
                                            {isEditing ? (
                                                <div className="space-y-2">
                                                    <input
                                                        type="text"
                                                        value={editDraft.real_name}
                                                        onChange={(e) =>
                                                            setEditDraft((prev) => ({ ...prev, real_name: e.target.value }))
                                                        }
                                                        placeholder="Real name"
                                                        className="w-full border border-gray-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                    />
                                                    <input
                                                        type="text"
                                                        value={editDraft.display_name}
                                                        onChange={(e) =>
                                                            setEditDraft((prev) => ({ ...prev, display_name: e.target.value }))
                                                        }
                                                        placeholder="Display name"
                                                        className="w-full border border-gray-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-[#4BBAC4]"
                                                    />
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="font-medium text-gray-900 truncate">
                                                        {user.real_name || user.slack_user_id}
                                                    </div>
                                                    {user.display_name && (
                                                        <div className="text-xs text-gray-500 truncate">
                                                            @{user.display_name}
                                                        </div>
                                                    )}
                                                    <div className="text-xs text-gray-400 truncate">{user.slack_user_id}</div>
                                                </>
                                            )}
                                        </div>
                                        {!isDeleted && (
                                            <div className="flex items-center">
                                                <button
                                                    type="button"
                                                    role="switch"
                                                    aria-checked={user.approved}
                                                    aria-label={`Approved ${user.real_name || user.slack_user_id}`}
                                                    onClick={() => handleToggleApproved(user)}
                                                    disabled={isSaving || isDeleting || isEditing}
                                                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                                                        isSaving || isDeleting || isEditing
                                                            ? 'cursor-not-allowed opacity-60'
                                                            : 'cursor-pointer'
                                                    } ${user.approved ? 'bg-[#4BBAC4]' : 'bg-gray-300'}`}
                                                >
                                                    <span
                                                        className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                                                            user.approved ? 'translate-x-6' : 'translate-x-1'
                                                        }`}
                                                    />
                                                </button>
                                            </div>
                                        )}
                                        <div className="flex items-center gap-3 justify-self-start sm:justify-self-end">
                                            {!isDeleted && (
                                                <>
                                                    {isEditing ? (
                                                        <>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleSave(user)}
                                                                disabled={isSaving}
                                                                className={`p-2 rounded-full transition-colors bg-[#0A2E4D] text-white hover:bg-[#4BBAC4] ${
                                                                    isSaving
                                                                        ? 'cursor-not-allowed opacity-60'
                                                                        : 'cursor-pointer'
                                                                }`}
                                                                aria-label="Save"
                                                            >
                                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={cancelEdit}
                                                                disabled={isSaving}
                                                                className={`p-2 rounded-full transition-colors text-gray-600 hover:bg-gray-100 ${
                                                                    isSaving
                                                                        ? 'cursor-not-allowed opacity-60'
                                                                        : 'cursor-pointer'
                                                                }`}
                                                                aria-label="Cancel"
                                                            >
                                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                                                </svg>
                                                            </button>
                                                        </>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() => startEdit(user)}
                                                            disabled={isSaving || isDeleting || isRestoring}
                                                            className={`p-2 rounded-full transition-colors text-gray-600 hover:bg-gray-100 hover:text-[#0A2E4D] ${
                                                                isSaving || isDeleting || isRestoring
                                                                    ? 'cursor-not-allowed opacity-60'
                                                                    : 'cursor-pointer'
                                                            }`}
                                                            aria-label="Edit"
                                                        >
                                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.232 5.232l3.536 3.536M16.732 3.732a2.5 2.5 0 113.536 3.536L7.5 20.036H4v-3.572L16.732 3.732z" />
                                                            </svg>
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => handleDelete(user)}
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
                                                    onClick={() => handleRestore(user)}
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
