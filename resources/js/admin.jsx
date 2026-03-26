import React from 'react';
import { createRoot } from 'react-dom/client';
import AdminApp from './admin/AdminApp';

const rootElement = document.getElementById('admin-root');
if (rootElement) {
    const root = createRoot(rootElement);
    root.render(<AdminApp />);
}