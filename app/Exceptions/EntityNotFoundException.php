<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class EntityNotFoundException extends Exception implements UnrecoverableJobException
{
    public function __construct(string $entityType, string $identifier, ?Exception $previous = null)
    {
        $message = "Entity of type '{$entityType}' with identifier '{$identifier}' not found";
        parent::__construct($message, 0, $previous);
    }
}
