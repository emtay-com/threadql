import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import axios from 'axios';
import LoginScreen from './LoginScreen';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        get: vi.fn(),
        defaults: { headers: { common: {} } },
    },
}));

describe('LoginScreen', () => {
    const onLogin = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.clear();
        axios.defaults.headers.common = {};
    });

    it('renders username and password inputs', () => {
        render(<LoginScreen onLogin={onLogin} />);
        expect(screen.getByLabelText('Username')).toBeInTheDocument();
        expect(screen.getByLabelText('Password')).toBeInTheDocument();
    });

    it('renders sign in button', () => {
        render(<LoginScreen onLogin={onLogin} />);
        expect(screen.getByRole('button', { name: 'Sign In' })).toBeInTheDocument();
    });

    it('disables sign in button when fields are empty', () => {
        render(<LoginScreen onLogin={onLogin} />);
        expect(screen.getByRole('button', { name: 'Sign In' })).toBeDisabled();
    });

    it('disables sign in button when only username is filled', () => {
        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        expect(screen.getByRole('button', { name: 'Sign In' })).toBeDisabled();
    });

    it('enables sign in button when both fields are filled', () => {
        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        expect(screen.getByRole('button', { name: 'Sign In' })).toBeEnabled();
    });

    it('calls POST /api/admin/token on submit', async () => {
        axios.post.mockResolvedValue({ data: { token: 'test-token' } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith('/api/admin/token', {
                username: 'admin',
                password: 'secret',
            }, { withCredentials: true });
        });
    });

    it('stores token in sessionStorage and calls onLogin on success', async () => {
        axios.post.mockResolvedValue({ data: { token: 'test-token' } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(sessionStorage.getItem('admin_token')).toBe('test-token');
            expect(onLogin).toHaveBeenCalledWith('test-token');
        });
    });

    it('sets axios Authorization header on success', async () => {
        axios.post.mockResolvedValue({ data: { token: 'test-token' } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(axios.defaults.headers.common['Authorization']).toBe('Bearer test-token');
        });
    });

    it('shows loading state while submitting', async () => {
        axios.post.mockImplementation(() => new Promise(() => {})); // never resolves

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByText('Signing in...')).toBeInTheDocument();
        });
    });

    it('shows "Invalid credentials" error on 401', async () => {
        axios.post.mockRejectedValue({ response: { status: 401 } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrong' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByText('Invalid credentials')).toBeInTheDocument();
        });
    });

    it('shows rate limit error on 429', async () => {
        axios.post.mockRejectedValue({ response: { status: 429 } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByText('Too many login attempts. Please wait a minute and try again.')).toBeInTheDocument();
        });
    });

    it('shows server error message when response includes one', async () => {
        axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'Account locked' } } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByText('Account locked')).toBeInTheDocument();
        });
    });

    it('shows generic error for unknown errors', async () => {
        axios.post.mockRejectedValue(new Error('Network Error'));

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByText('An error occurred. Please try again.')).toBeInTheDocument();
        });
    });

    it('toggles password visibility', () => {
        render(<LoginScreen onLogin={onLogin} />);
        const passwordInput = screen.getByLabelText('Password');
        expect(passwordInput).toHaveAttribute('type', 'password');

        // The toggle button is the only non-submit button in the form
        const buttons = screen.getAllByRole('button');
        const toggleButton = buttons.find((b) => b.getAttribute('type') === 'button');
        fireEvent.click(toggleButton);
        expect(passwordInput).toHaveAttribute('type', 'text');

        fireEvent.click(toggleButton);
        expect(passwordInput).toHaveAttribute('type', 'password');
    });

    it('disables inputs while submitting', async () => {
        axios.post.mockImplementation(() => new Promise(() => {})); // never resolves

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByLabelText('Username')).toBeDisabled();
            expect(screen.getByLabelText('Password')).toBeDisabled();
        });
    });

    it('clears previous error message on new submission attempt', async () => {
        // First request fails
        axios.post.mockRejectedValueOnce({ response: { status: 401 } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrong' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByText('Invalid credentials')).toBeInTheDocument();
        });

        // Second request hangs — error should be cleared immediately on submit
        axios.post.mockImplementation(() => new Promise(() => {}));
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.queryByText('Invalid credentials')).not.toBeInTheDocument();
        });
    });

    it('re-enables submit button after failed login', async () => {
        axios.post.mockRejectedValue({ response: { status: 401 } });

        render(<LoginScreen onLogin={onLogin} />);
        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'admin' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrong' } });
        fireEvent.click(screen.getByRole('button', { name: 'Sign In' }));

        await waitFor(() => {
            expect(screen.getByText('Invalid credentials')).toBeInTheDocument();
        });

        expect(screen.getByRole('button', { name: 'Sign In' })).toBeEnabled();
    });
});
