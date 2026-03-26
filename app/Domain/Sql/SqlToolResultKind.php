<?php

declare(strict_types=1);

namespace App\Domain\Sql;

enum SqlToolResultKind: string
{
    case Aggregate = 'aggregate';       // existing
    case PendingTable = 'pending_table';   // existing
    case NoResults = 'no_results';      // NEW
}
