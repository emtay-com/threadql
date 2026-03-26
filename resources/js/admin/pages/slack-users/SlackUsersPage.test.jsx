import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import SlackUsersPage from './SlackUsersPage';

vi.mock('./SlackUsersView', () => ({
    default: () => <div data-testid="slack-users-view">SlackUsersView</div>,
}));

describe('SlackUsersPage', () => {
    it('renders without crashing and mounts SlackUsersView', () => {
        render(
            <MemoryRouter>
                <SlackUsersPage />
            </MemoryRouter>
        );

        expect(screen.getByTestId('slack-users-view')).toBeInTheDocument();
    });
});
