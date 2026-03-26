<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class FileReadException extends Exception implements UnrecoverableJobException
{
    public function __construct(string $message, ?Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
