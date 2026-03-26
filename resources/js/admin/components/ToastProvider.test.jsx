import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { ToastProvider, useToast } from './ToastProvider.jsx';

function ToastTrigger({ state, message }) {
    const { showToast } = useToast();
    return (
        <button onClick={() => showToast({ state, message })}>show</button>
    );
}

function renderWithProvider(state, message) {
    render(
        <ToastProvider>
            <ToastTrigger state={state} message={message} />
        </ToastProvider>
    );
}

describe('ToastProvider', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        act(() => vi.runOnlyPendingTimers());
        vi.useRealTimers();
    });

    it('renders children', () => {
        render(<ToastProvider><span>child</span></ToastProvider>);
        expect(screen.getByText('child')).toBeInTheDocument();
    });

    it('shows a toast with the given message', () => {
        renderWithProvider('info', 'Hello world');
        fireEvent.click(screen.getByRole('button'));
        expect(screen.getByText('Hello world')).toBeInTheDocument();
    });

    it('applies info styles by default', () => {
        renderWithProvider(undefined, 'Info toast');
        fireEvent.click(screen.getByRole('button'));
        const toast = screen.getByText('Info toast');
        expect(toast).toHaveClass('bg-blue-50', 'border-blue-200', 'text-blue-700');
    });

    it('applies success styles', () => {
        renderWithProvider('success', 'Done!');
        fireEvent.click(screen.getByRole('button'));
        const toast = screen.getByText('Done!');
        expect(toast).toHaveClass('bg-green-50', 'border-green-200', 'text-green-700');
    });

    it('applies warning styles', () => {
        renderWithProvider('warning', 'Careful!');
        fireEvent.click(screen.getByRole('button'));
        const toast = screen.getByText('Careful!');
        expect(toast).toHaveClass('bg-yellow-50', 'border-yellow-200', 'text-yellow-700');
    });

    it('applies error styles', () => {
        renderWithProvider('error', 'Something went wrong');
        fireEvent.click(screen.getByRole('button'));
        const toast = screen.getByText('Something went wrong');
        expect(toast).toHaveClass('bg-red-50', 'border-red-200', 'text-red-700');
    });

    it('falls back to info styles for an unknown state', () => {
        renderWithProvider('unknown', 'Fallback');
        fireEvent.click(screen.getByRole('button'));
        const toast = screen.getByText('Fallback');
        expect(toast).toHaveClass('bg-blue-50', 'border-blue-200', 'text-blue-700');
    });

    it('removes the toast after 2500ms', () => {
        renderWithProvider('info', 'Disappearing');
        fireEvent.click(screen.getByRole('button'));
        expect(screen.getByText('Disappearing')).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(2500));

        expect(screen.queryByText('Disappearing')).not.toBeInTheDocument();
    });
});
