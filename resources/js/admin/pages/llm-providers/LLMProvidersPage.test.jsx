import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import LLMProvidersPage from './LLMProvidersPage';

vi.mock('./LLMProvidersView', () => ({
    default: () => <div data-testid="llm-providers-view">LLMProvidersView</div>,
}));

describe('LLMProvidersPage', () => {
    it('renders without crashing and mounts LLMProvidersView', () => {
        render(
            <MemoryRouter>
                <LLMProvidersPage />
            </MemoryRouter>
        );

        expect(screen.getByTestId('llm-providers-view')).toBeInTheDocument();
    });
});
