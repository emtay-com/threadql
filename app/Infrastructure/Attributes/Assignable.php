<?php

declare(strict_types=1);

namespace App\Infrastructure\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Assignable
{
}
