import React from 'react';
import { ToastProvider } from '../../components/ToastProvider';
import TenantSettingsView from './TenantSettingsView';

export default function TenantSettingsPage() {
    return (
        <ToastProvider>
            <TenantSettingsView />
        </ToastProvider>
    );
}
