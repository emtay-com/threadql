import React from 'react';
import { ToastProvider } from '../../components/ToastProvider';
import LLMProvidersView from './LLMProvidersView';

export default function LLMProvidersPage() {
    return (
        <ToastProvider>
            <LLMProvidersView />
        </ToastProvider>
    );
}
