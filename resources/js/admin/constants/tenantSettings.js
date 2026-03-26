export const SETTING_TYPES = {
    auto_approve_users: {
        type: 'boolean',
        label: 'Auto Approve Users',
        description: 'Automatically approve new Slack users when they first interact with the bot.',
    },
    user_rate_limit: {
        type: 'numeric',
        label: 'User Rate Limit',
        description: 'Maximum number of queries a user can make per minute.',
    },
    table_scan_schedule: {
        type: 'time_schedule',
        label: 'Table Scan Schedule',
        description: 'Daily time to automatically scan datasource tables for schema changes (HH:MM).',
    },
    fallback_attempts: {
        type: 'numeric',
        label: 'Fallback Attempts',
        description: 'Number of retry attempts when the primary LLM call fails before giving up.',
    },
};
