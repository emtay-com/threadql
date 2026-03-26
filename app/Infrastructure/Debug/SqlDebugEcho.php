<?php

declare(strict_types=1);

namespace App\Infrastructure\Debug;

use App\Enums\Settings;
use App\Infrastructure\Slack\SlackUserSettingService;
use App\Jobs\SendEphemeralSqlDebug;
use App\Models\Query;

/**
 * Helper class to send SQL debug information as ephemeral Slack messages
 * when DEBUG setting is enabled for a user.
 */
final class SqlDebugEcho
{
    public function __construct(
        private SlackUserSettingService $settings,
    ) {
    }

    /**
     * Send ephemeral SQL debug message if DEBUG is enabled for the user.
     *
     * @param Query $query The query that was executed
     * @param array $boundParams The bound parameters used in the query
     * @param string $sql The executed SQL with placeholders
     * @param int $tookMs Duration of the query execution in milliseconds
     * @param int $rowCount Number of rows returned/affected
     * @param string $connectionName Name of the database connection used
     */
    public function maybeSend(
        Query $query,
        array $boundParams,
        string $sql,
        int $tookMs,
        int $rowCount,
        string $connectionName
    ): void {
        $slackUser = $query->slackUser; // nullable; bail if missing
        if (! $slackUser) {
            return;
        }
        if (! $this->settings->isEnabled($slackUser, Settings::DEBUG->value)) {
            return;
        }
        dispatch(new SendEphemeralSqlDebug(
            queryId: $query->id,
            channelId: $query->thread->channel_id,
            userId: $slackUser->slack_user_id,
            text: $this->renderSqlBlock($query->id, $sql, $boundParams, $tookMs, $rowCount, $connectionName),
        ))->delay(now()->addSeconds(2));
    }

    /**
     * Render the SQL debug information as a formatted code block.
     *
     * @param int $queryId The query ID
     * @param string $sql The executed SQL
     * @param array $params The bound parameters
     * @param int $tookMs Duration in milliseconds
     * @param int $rowCount Number of rows
     * @param string $conn Connection name
     * @return string Formatted SQL debug text
     */
    private function renderSqlBlock(
        int $queryId,
        string $sql,
        array $params,
        int $tookMs,
        int $rowCount,
        string $conn
    ): string {
        return '```sql'."\n".
            '-- Query ID: '.$queryId."\n".
            '-- Connection: '.$conn."\n".
            '-- Duration: '.$tookMs.' ms'."\n".
            '-- Row count: '.$rowCount."\n".
            '-- Parameters: '.json_encode($params, JSON_PRETTY_PRINT)."\n".
            $sql."\n```";
    }
}
