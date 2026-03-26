<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\ToolResults;

use App\Mcp\ToolResults\RunSqlQueryPayload;
use PHPUnit\Framework\TestCase;

class RunSqlQueryPayloadTest extends TestCase
{
    public function test_no_results_factory_returns_expected_json_shape(): void
    {
        $tookMs = 150;
        $payload = RunSqlQueryPayload::noResults($tookMs);

        $expected = [
            'ok' => true,
            'result_kind' => 'no_results',
            'took_ms' => $tookMs,
        ];

        $this->assertEquals($expected, $payload->jsonSerialize());
    }
}
