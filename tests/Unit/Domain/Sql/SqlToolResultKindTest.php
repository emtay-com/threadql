<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sql;

use App\Domain\Sql\SqlToolResultKind;
use PHPUnit\Framework\TestCase;

class SqlToolResultKindTest extends TestCase
{
    public function test_enum_values(): void
    {
        $this->assertEquals('aggregate', SqlToolResultKind::Aggregate->value);
        $this->assertEquals('pending_table', SqlToolResultKind::PendingTable->value);
        $this->assertEquals('no_results', SqlToolResultKind::NoResults->value);
    }
}
