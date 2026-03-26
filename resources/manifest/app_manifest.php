<?php

declare(strict_types=1);

return [
    'display_information' => [
        'name' => '{{app_name}}',
        'description' => 'Thread-aware SQL assistant for Slack',
    ],
    'features' => [
        'app_home' => [
            'home_tab_enabled' => true,
            'messages_tab_enabled' => true,
            'messages_tab_read_only_enabled' => false,
        ],
        'bot_user' => [
            'display_name' => '{{bot_display_name}}',
            'always_online' => true,
        ],
        'slash_commands' => [[
            'command' => '/{{bot_display_name}}',
            'url' => '{{base_url}}/api/{{tenant_uuid}}/slack/commands',
            'description' => 'Interact with {{bot_display_name}}',
            'usage_hint' => 'help | define | list | survey on|off | debug on|off',
            'should_escape' => false,
        ]],
    ],
    'oauth_config' => [
        'redirect_urls' => ['{{base_url}}/api/{{tenant_uuid}}/slack/oauth/callback'],
        'scopes' => [
            'bot' => [
                'channels:history', 'channels:read',
                'chat:write', 'chat:write.customize',
                'files:write', 'im:history', 'im:read', 'im:write',
                'commands', 'app_mentions:read', 'users:read',
            ],
        ],
    ],
    'settings' => [
        'event_subscriptions' => [
            'request_url' => '{{base_url}}/api/{{tenant_uuid}}/slack/events',
            'bot_events' => ['app_mention', 'message.im'],
        ],
        'interactivity' => [
            'is_enabled' => true,
            'request_url' => '{{base_url}}/api/{{tenant_uuid}}/slack/interactive',
        ],
        'org_deploy_enabled' => false,
        'socket_mode_enabled' => false,
        'token_rotation_enabled' => false,
    ],
];
