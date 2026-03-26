<?php

declare(strict_types=1);

namespace App\Enums;

enum QueryStatus: string
{
    case RECEIVED = 'received';
    case PLANNING = 'planning';
    case EXECUTING = 'executing';
    case INPUT_REQUESTED = 'input_requested';
    case ERROR = 'error';
    case DONE = 'done';
}
