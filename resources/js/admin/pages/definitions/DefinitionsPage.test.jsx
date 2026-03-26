import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import DefinitionsPage from './DefinitionsPage';

vi.mock('./DefinitionsView', () => ({
    default: () => <div data-testid="definitions-view">DefinitionsView</div>,
}));

describe('DefinitionsPage', () => {
    it('renders without crashing and mounts DefinitionsView', () => {
        render(
            <MemoryRouter>
                <DefinitionsPage />
            </MemoryRouter>
        );

        expect(screen.getByTestId('definitions-view')).toBeInTheDocument();
    });
});
