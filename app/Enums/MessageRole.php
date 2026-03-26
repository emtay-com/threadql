<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageRole: string
{
    case SYSTEM = 'system';
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case TOOL = 'tool';
}
