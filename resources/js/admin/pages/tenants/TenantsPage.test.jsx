import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import TenantsPage from './TenantsPage';

vi.mock('./TenantsView', () => ({
    default: () => <div data-testid="tenants-view">TenantsView</div>,
}));

describe('TenantsPage', () => {
    it('renders without crashing and mounts TenantsView', () => {
        render(
            <MemoryRouter>
                <TenantsPage />
            </MemoryRouter>
        );

        expect(screen.getByTestId('tenants-view')).toBeInTheDocument();
    });
});
