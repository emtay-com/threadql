<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class LlmProviderNotSetException extends Exception implements UnrecoverableJobException
{
    public function __construct(int $tenantId)
    {
        parent::__construct("No enabled LLM provider found for tenant {$tenantId}");
    }
}
