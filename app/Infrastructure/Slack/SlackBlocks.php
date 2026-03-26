<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Interface for Slack block builders
 */
interface SlackBlocks
{
    /**
     * Convert the block structure to an array format for Slack API
     *
     * @return array The blocks array to send to Slack
     */
    public function toArray(): array;
}
