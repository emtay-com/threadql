import React from 'react';
import { ToastProvider } from '../../components/ToastProvider';
import DataSourcesView from './DataSourcesView';

export default function DataSourcesPage() {
    return (
        <ToastProvider>
            <DataSourcesView />
        </ToastProvider>
    );
}
