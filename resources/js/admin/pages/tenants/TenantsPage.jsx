import React from 'react';
import { ToastProvider } from '../../components/ToastProvider';
import TenantsView from './TenantsView';

export default function TenantsPage() {
    return (
        <ToastProvider>
            <TenantsView />
        </ToastProvider>
    );
}
