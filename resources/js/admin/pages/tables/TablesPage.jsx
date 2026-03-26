import React from 'react';
import { ToastProvider } from '../../components/ToastProvider';
import TablesView from './TablesView';

export default function TablesPage() {
    return (
        <ToastProvider>
            <TablesView />
        </ToastProvider>
    );
}
