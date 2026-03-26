import React from 'react';
import { ToastProvider } from '../../components/ToastProvider';
import SlackUsersView from './SlackUsersView';

export default function SlackUsersPage() {
    return (
        <ToastProvider>
            <SlackUsersView />
        </ToastProvider>
    );
}
