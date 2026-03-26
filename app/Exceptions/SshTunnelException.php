<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class SshTunnelException extends DatabaseConnectionException
{
    public function __construct(string $message, ?Exception $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
