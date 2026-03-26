<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantSettingEnum: string
{
    case AUTO_APPROVE_USERS = 'auto_approve_users';
    case USER_RATE_LIMIT = 'user_rate_limit';
    case TABLE_SCAN_SCHEDULE = 'table_scan_schedule';
    case FALLBACK_ATTEMPTS = 'fallback_attempts';
}
