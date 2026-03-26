<?php

declare(strict_types=1);

namespace App\Domain\Queries\Anchors;

enum AnchorType: string
{
    case TABLE = 'table';
    case PAGINATION_BLOCKS = 'pagination_blocks';
}
