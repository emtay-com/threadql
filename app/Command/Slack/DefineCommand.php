<?php

declare(strict_types=1);

namespace App\Command\Slack;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to define a business term via Slack
 */
class DefineCommand implements DomainCommand
{
    public function __construct(
        public readonly string $input,
        public readonly string $userId,
        public readonly ?string $threadTs,
        public readonly string $channelId,
    ) {
    }
}
