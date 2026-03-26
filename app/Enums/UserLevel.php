<?php

declare(strict_types=1);

namespace App\Enums;

enum UserLevel: string
{
    case TENANT = 'tenant';
    case MASTER = 'master';
}
