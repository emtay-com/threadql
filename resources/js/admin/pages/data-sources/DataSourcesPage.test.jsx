import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import DataSourcesPage from './DataSourcesPage';

vi.mock('./DataSourcesView', () => ({
    default: () => <div data-testid="data-sources-view">DataSourcesView</div>,
}));

describe('DataSourcesPage', () => {
    it('renders without crashing and mounts DataSourcesView', () => {
        render(
            <MemoryRouter>
                <DataSourcesPage />
            </MemoryRouter>
        );

        expect(screen.getByTestId('data-sources-view')).toBeInTheDocument();
    });
});
