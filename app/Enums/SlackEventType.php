<?php

declare(strict_types=1);

namespace App\Enums;

enum SlackEventType: string
{
    case URL_VERIFICATION = 'url_verification';
    case EVENT_CALLBACK = 'event_callback';
    case APP_MENTION = 'app_mention';
    case MESSAGE = 'message';
    case MESSAGE_CHANGED = 'message_changed';
}
