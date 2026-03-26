import React from 'react';
import { ToastProvider } from '../../components/ToastProvider';
import DefinitionsView from './DefinitionsView';

export default function DefinitionsPage() {
    return (
        <ToastProvider>
            <DefinitionsView />
        </ToastProvider>
    );
}
