import React from 'react';
import { NavLink } from 'react-router-dom';
import { mainNavRoutes } from '../routes';
import { decodeToken } from '../../services/token';

export default function MainMenu() {
    const token = sessionStorage.getItem('admin_token');
    const decoded = decodeToken(token);
    const isMaster = Boolean(decoded?.is_master || decoded?.level === 'master');
    const visibleNavRoutes = mainNavRoutes.filter((item) => !item.requiresMaster || isMaster);

    return (
        <nav className="bg-gray-100 border-b border-gray-200">
            <div className="px-8">
                <ul className="flex space-x-1">
                    {visibleNavRoutes.map((item) => (
                        <li key={item.id}>
                            <NavLink
                                to={item.path}
                                className={({ isActive }) =>
                                    `inline-block px-4 py-3 text-sm font-medium transition-colors ${
                                        isActive
                                            ? 'text-[#0A2E4D] border-b-2 border-[#4BBAC4] bg-white'
                                            : 'text-gray-600 hover:text-[#0A2E4D] hover:bg-gray-50'
                                    }`
                                }
                            >
                                {item.label}
                            </NavLink>
                        </li>
                    ))}
                </ul>
            </div>
        </nav>
    );
}
