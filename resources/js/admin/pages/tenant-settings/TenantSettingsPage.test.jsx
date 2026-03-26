import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import TenantSettingsPage from './TenantSettingsPage';

vi.mock('./TenantSettingsView', () => ({
    default: () => <div data-testid="tenant-settings-view">TenantSettingsView</div>,
}));

describe('TenantSettingsPage', () => {
    it('renders without crashing and mounts TenantSettingsView', () => {
        render(
            <MemoryRouter>
                <TenantSettingsPage />
            </MemoryRouter>
        );

        expect(screen.getByTestId('tenant-settings-view')).toBeInTheDocument();
    });
});
