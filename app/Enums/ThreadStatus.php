<?php

declare(strict_types=1);

namespace App\Enums;

enum ThreadStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
    case CLOSED = 'closed';
}
