import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import TablesPage from './TablesPage';

vi.mock('./TablesView', () => ({
    default: () => <div data-testid="tables-view">TablesView</div>,
}));

describe('TablesPage', () => {
    it('renders without crashing and mounts TablesView', () => {
        render(
            <MemoryRouter>
                <TablesPage />
            </MemoryRouter>
        );

        expect(screen.getByTestId('tables-view')).toBeInTheDocument();
    });
});
