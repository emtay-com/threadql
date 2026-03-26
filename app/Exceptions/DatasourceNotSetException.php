<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class DatasourceNotSetException extends Exception implements UnrecoverableJobException
{
    public function __construct(int $tenantId)
    {
        parent::__construct("No active datasource found for tenant {$tenantId}");
    }
}
