<?php

declare(strict_types=1);

namespace App\Enums;

enum Settings: string
{
    case SURVEYS = 'surveys';
    case DEBUG = 'debug';
}
