<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\SendCsvExportLinkJob;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for SendCsvExportLinkJob
 */
class SendCsvExportLinkJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test sends Slack message with download link
     */
    public function test_sends_slack_message_with_download_link(): void
    {
        $tenant = Tenant::factory()->create();
        Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        $downloadUrl = 'https://s3.amazonaws.com/exports/test.csv';
        $expiresAt = '2026-03-03T12:00:00+00:00';

        $mockMessenger = Mockery::mock(SlackMessenger::class);
        $mockMessenger->shouldReceive('replyInThread')
            ->once()
            ->withArgs(function ($receivedTenant, $channelId, $threadTs, $message) use ($tenant, $downloadUrl) {
                return $receivedTenant->id === $tenant->id
                    && $channelId === 'C1234567890'
                    && $threadTs === '1234567890.123456'
                    && str_contains($message, $downloadUrl)
                    && str_contains($message, '50,000 rows')
                    && str_contains($message, 'Download CSV');
            })
            ->andReturn([
                'ts' => '123',
            ]);

        $job = new SendCsvExportLinkJob($query->id, $downloadUrl, 50000, $expiresAt);

        $job->handle($mockMessenger);

        $this->assertTrue(true);
    }
}
