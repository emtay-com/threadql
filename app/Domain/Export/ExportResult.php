<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * Result of a CSV export operation
 */
class ExportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $bytes = null,
        public readonly ?int $rowCount = null,
        public readonly ?string $filePath = null,
        public readonly ?string $error = null
    ) {
    }
}
