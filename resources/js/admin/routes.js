import React from 'react';

const TenantsPage = React.lazy(() => import('./pages/tenants/TenantsPage'));
const DataSourcesPage = React.lazy(() => import('./pages/data-sources/DataSourcesPage'));
const TablesPage = React.lazy(() => import('./pages/tables/TablesPage'));
const DefinitionsPage = React.lazy(() => import('./pages/definitions/DefinitionsPage'));
const LLMProvidersPage = React.lazy(() => import('./pages/llm-providers/LLMProvidersPage'));
const SlackUsersPage = React.lazy(() => import('./pages/slack-users/SlackUsersPage'));
const TenantSettingsPage = React.lazy(() => import('./pages/tenant-settings/TenantSettingsPage'));
const SettingsPage = React.lazy(() => import('./pages/SettingsPage'));

export const adminRoutes = [
    {
        id: 'tenants',
        path: '/panel/tenants',
        component: TenantsPage,
        nav: 'main',
        label: 'Tenants',
    },
    {
        id: 'tenants-view',
        path: '/panel/tenants/:tenantId',
        component: TenantsPage,
    },
    {
        id: 'tenants-add',
        path: '/panel/tenants/edit/add',
        component: TenantsPage,
    },
    {
        id: 'tenants-edit',
        path: '/panel/tenants/edit/:tenantId',
        component: TenantsPage,
    },
    {
        id: 'data-sources',
        path: '/panel/data-sources',
        component: DataSourcesPage,
    },
    {
        id: 'data-sources-tenant',
        path: '/panel/data-sources/:tenantId',
        component: DataSourcesPage,
        nav: 'tenant',
        label: 'Data Sources',
        requiresTenant: true,
    },
    {
        id: 'tables',
        path: '/panel/tables',
        component: TablesPage,
    },
    {
        id: 'tables-tenant',
        path: '/panel/tables/:tenantId',
        component: TablesPage,
        nav: 'tenant',
        label: 'Tables',
        requiresTenant: true,
    },
    {
        id: 'definitions',
        path: '/panel/definitions',
        component: DefinitionsPage,
    },
    {
        id: 'definitions-tenant',
        path: '/panel/definitions/:tenantId',
        component: DefinitionsPage,
        nav: 'tenant',
        label: 'Definitions',
        requiresTenant: true,
    },
    {
        id: 'llm-providers',
        path: '/panel/llm-providers',
        component: LLMProvidersPage,
    },
    {
        id: 'llm-providers-tenant',
        path: '/panel/llm-providers/:tenantId',
        component: LLMProvidersPage,
        nav: 'tenant',
        label: 'LLM Providers',
        requiresTenant: true,
    },
    {
        id: 'slack-users',
        path: '/panel/slack-users',
        component: SlackUsersPage,
    },
    {
        id: 'slack-users-tenant',
        path: '/panel/slack-users/:tenantId',
        component: SlackUsersPage,
        nav: 'tenant',
        label: 'Slack Users',
        requiresTenant: true,
    },
    {
        id: 'tenant-settings',
        path: '/panel/tenant-settings',
        component: TenantSettingsPage,
    },
    {
        id: 'tenant-settings-tenant',
        path: '/panel/tenant-settings/:tenantId',
        component: TenantSettingsPage,
        nav: 'tenant',
        label: 'Settings',
        requiresTenant: true,
    },
    {
        id: 'settings',
        path: '/panel/settings',
        component: SettingsPage,
        nav: 'main',
        label: 'Settings',
        requiresMaster: true,
    },
    {
        id: 'settings-users',
        path: '/panel/settings/users',
        component: SettingsPage,
    },
];

export const mainNavRoutes = adminRoutes.filter((route) => route.nav === 'main');
export const tenantNavRoutes = ['llm-providers-tenant', 'data-sources-tenant', 'tables-tenant', 'definitions-tenant', 'slack-users-tenant', 'tenant-settings-tenant']
    .map((id) => adminRoutes.find((route) => route.id === id))
    .filter(Boolean);

export const buildTenantPath = (path, tenantId) => {
    if (!tenantId) {
        return path;
    }
    return path.replace(':tenantId', tenantId);
};
