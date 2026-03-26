<?php

declare(strict_types=1);

namespace App\Enums;

enum Queue: string
{
    case DEFAULT = 'default';
    case LONG_QUEUE = 'long_queue';
}
