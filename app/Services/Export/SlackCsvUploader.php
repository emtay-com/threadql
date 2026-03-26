<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Tenant;
use App\Models\Thread;
use Exception;

/**
 * Service for uploading CSV files to Slack
 */
class SlackCsvUploader
{
    public function __construct(
        private readonly SlackMessenger $slackMessenger
    ) {
    }

    /**
     * Upload CSV file to Slack thread
     */
    public function uploadFile(Tenant $tenant, Thread $thread, string $filePath): void
    {
        $files = $this->buildFileConfiguration($filePath);
        $comment = $this->buildComment($filePath);

        $success = $this->slackMessenger->uploadFile(
            $tenant,
            $files,
            $thread->channel_id,
            $comment,
            $thread->thread_ts
        );

        if (! $success) {
            throw new Exception('Failed to upload CSV file to Slack');
        }
    }

    /**
     * Send empty results message to Slack
     */
    public function sendEmptyResultsMessage(Tenant $tenant, Thread $thread): void
    {
        $this->slackMessenger->replyInThread(
            $tenant,
            $thread->channel_id,
            $thread->thread_ts,
            'The query returned no results to export.'
        );
    }

    /**
     * Build file configuration for Slack upload
     */
    private function buildFileConfiguration(string $filePath): array
    {
        return [
            [
                'path' => $filePath,
                'content' => file_get_contents($filePath),
                'snippet_type' => 'csv',
                'title' => 'Query Results Export',
            ],
        ];
    }

    /**
     * Build upload comment
     */
    private function buildComment(string $filePath): string
    {
        return sprintf('CSV export for your query results (%d bytes)', filesize($filePath));
    }
}
