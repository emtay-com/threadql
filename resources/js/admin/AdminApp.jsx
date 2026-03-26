import React, { Suspense, useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import axios from 'axios';
import LoginScreen from './components/LoginScreen';
import MainMenu from './components/MainMenu';
import { isValidToken } from '../services/token';
import { adminRoutes } from './routes';
import { useTenantsStore } from './stores/tenantsStore';

function AdminLayout({ onLogout }) {
    return (
        <div className="min-h-screen bg-gray-50 flex flex-col">
            <div className="bg-white border-b border-gray-200">
                <div className="px-8 py-4 flex items-center justify-between">
                    <div className="flex items-center space-x-3">
                        <img src="/images/threadql_logo.png" alt="ThreadQL" className="h-10" />
                        <h1 className="text-2xl font-bold text-[#0A2E4D]">Admin Dashboard</h1>
                    </div>
                    <button
                        onClick={onLogout}
                        className="px-4 py-2 bg-gradient-to-r from-[#4BBAC4] to-[#0A2E4D] text-white rounded-lg hover:shadow-lg transition-all cursor-pointer"
                    >
                        Logout
                    </button>
                </div>
            </div>
            <MainMenu />
            <div className="flex flex-1 overflow-hidden">
                <Suspense fallback={<div className="p-6 text-gray-500">Loading...</div>}>
                    <Routes>
                        {adminRoutes.map((route) => {
                            const Component = route.component;
                            return <Route key={route.id} path={route.path} element={<Component />} />;
                        })}
                        <Route path="*" element={<Navigate to="/panel/tenants" replace />} />
                    </Routes>
                </Suspense>
            </div>
        </div>
    );
}

function AuthWrapper() {
    const [isAuthenticated, setIsAuthenticated] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const navigate = useNavigate();
    const resetTenantsStore = useTenantsStore((state) => state.reset);
    const interceptorRef = React.useRef(null);

    const resetClientState = () => {
        localStorage.removeItem('activeTenant');
        resetTenantsStore();
    };

    const performLogout = async () => {
        // Clear the refresh token cookie via the backend before local cleanup
        await axios.post('/api/admin/token/logout', {}, { withCredentials: true }).catch(() => {});
        sessionStorage.removeItem('admin_token');
        resetClientState();
        delete axios.defaults.headers.common['Authorization'];
        setIsAuthenticated(false);
    };

    const setupInterceptor = () => {
        // Remove previous interceptor if any
        if (interceptorRef.current !== null) {
            axios.interceptors.response.eject(interceptorRef.current);
        }

        let isRefreshing = false;
        let failedQueue = [];

        const processQueue = (error, token = null) => {
            failedQueue.forEach(({ resolve, reject }) => {
                if (error) {
                    reject(error);
                } else {
                    resolve(token);
                }
            });
            failedQueue = [];
        };

        interceptorRef.current = axios.interceptors.response.use(
            (response) => response,
            async (error) => {
                const originalRequest = error.config;

                // Don't intercept login or refresh requests
                if (
                    !error.response ||
                    error.response.status !== 401 ||
                    originalRequest._retry ||
                    originalRequest.url === '/api/admin/token' ||
                    originalRequest.url === '/api/admin/token/refresh'
                ) {
                    return Promise.reject(error);
                }

                if (isRefreshing) {
                    return new Promise((resolve, reject) => {
                        failedQueue.push({ resolve, reject });
                    }).then((token) => {
                        originalRequest.headers['Authorization'] = `Bearer ${token}`;
                        return axios(originalRequest);
                    });
                }

                originalRequest._retry = true;
                isRefreshing = true;

                try {
                    // Refresh token is sent automatically via HTTP-only cookie
                    const response = await axios.post('/api/admin/token/refresh', {}, {
                        withCredentials: true,
                    });

                    const { token } = response.data;
                    sessionStorage.setItem('admin_token', token);
                    axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;

                    processQueue(null, token);

                    originalRequest.headers['Authorization'] = `Bearer ${token}`;
                    return axios(originalRequest);
                } catch (refreshError) {
                    processQueue(refreshError, null);
                    performLogout();
                    navigate('/panel');
                    return Promise.reject(refreshError);
                } finally {
                    isRefreshing = false;
                }
            }
        );
    };

    useEffect(() => {
        const token = sessionStorage.getItem('admin_token');

        if (token && isValidToken(token)) {
            axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
            setIsAuthenticated(true);
        } else {
            if (token) {
                sessionStorage.removeItem('admin_token');
            }
            resetClientState();
        }
        setIsLoading(false);

        setupInterceptor();

        return () => {
            if (interceptorRef.current !== null) {
                axios.interceptors.response.eject(interceptorRef.current);
            }
        };
    }, []);

    const handleLogin = () => {
        setIsAuthenticated(true);
        navigate('/panel/tenants');
    };

    const handleLogout = () => {
        performLogout();
        navigate('/panel');
    };

    if (isLoading) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-[#0A2E4D] to-[#4BBAC4]">
                <div className="text-white text-xl">Loading...</div>
            </div>
        );
    }

    if (!isAuthenticated) {
        return <LoginScreen onLogin={handleLogin} />;
    }

    return <AdminLayout onLogout={handleLogout} />;
}

export default function AdminApp() {
    return (
        <BrowserRouter>
            <AuthWrapper />
        </BrowserRouter>
    );
}
