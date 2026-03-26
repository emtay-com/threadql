import React, { createContext, useContext, useMemo, useState } from 'react';
import { toastStates } from './toastStates';

const ToastContext = createContext({
    showToast: () => {},
});

let toastId = 0;

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);

    const removeToast = (id) => {
        setToasts((prev) => prev.filter((toast) => toast.id !== id));
    };

    const showToast = ({ state = 'info', message = '' }) => {
        toastId += 1;
        const id = toastId;
        setToasts((prev) => [...prev, { id, state, message }]);
        setTimeout(() => removeToast(id), 2500);
    };

    const value = useMemo(() => ({ showToast }), []);

    return (
        <ToastContext.Provider value={value}>
            {children}
            {toasts.length > 0 && (
                <div className="fixed top-6 left-1/2 transform -translate-x-1/2 z-50 pointer-events-none">
                    <div className="space-y-3">
                        {toasts.map((toast) => {
                            const styles = toastStates[toast.state] ?? toastStates.info;
                            return (
                                <div
                                    key={toast.id}
                                    className={`px-5 py-4 rounded-xl shadow-xl border text-sm ${styles.bg} ${styles.border} ${styles.text}`}
                                    style={{ animation: 'toastFade 2.5s ease-in-out', opacity: 0 }}
                                >
                                    {toast.message}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
            <style>
                {`
                @keyframes toastFade {
                    0% { opacity: 0; transform: translateY(-8px); }
                    10% { opacity: 1; transform: translateY(0); }
                    90% { opacity: 1; transform: translateY(0); }
                    100% { opacity: 0; transform: translateY(-8px); }
                }
                `}
            </style>
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext);
}
