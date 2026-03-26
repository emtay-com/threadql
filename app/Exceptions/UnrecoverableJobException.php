<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

interface UnrecoverableJobException extends Throwable
{
    // Marker interface to indicate that a job should fail immediately
    // without retrying, as the error condition cannot be resolved by retries
}
