<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\SendNoDatasourceNotificationJob;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Mockery;
use Tests\TestCase;

class SendNoDatasourceNotificationJobTest extends TestCase
{
    public function test_it_sends_notification_to_slack(): void
    {
        $tenant = Tenant::factory()->withSlackCredentials()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C123',
            'thread_ts' => '1234567890.123456',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        $messenger = Mockery::mock(SlackMessenger::class);
        $messenger->shouldReceive('replyInThreadWithBlocks')
            ->once()
            ->withArgs(function ($t, $channelId, $threadTs, $message, $blocks) use ($tenant) {
                return $t->id === $tenant->id
                    && $channelId === 'C123'
                    && $threadTs === '1234567890.123456'
                    && str_contains($message, 'No datasource')
                    && is_array($blocks);
            })
            ->andReturn([
                'ts' => '1234567890.999999',
            ]);

        $job = new SendNoDatasourceNotificationJob($query->id);
        $job->handle($messenger);
    }

    public function test_it_handles_missing_thread_fields_gracefully(): void
    {
        $tenant = Tenant::factory()->withSlackCredentials()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C123',
            'thread_ts' => '1234567890.123456',
        ]);

        // Set thread_ts to empty after creation to simulate missing field
        $thread->update([
            'thread_ts' => '',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        $messenger = Mockery::mock(SlackMessenger::class);
        $messenger->shouldNotReceive('replyInThreadWithBlocks');

        $job = new SendNoDatasourceNotificationJob($query->id);
        $job->handle($messenger);
    }

    public function test_it_throws_entity_not_found_for_missing_query(): void
    {
        $messenger = Mockery::mock(SlackMessenger::class);

        $this->expectException(EntityNotFoundException::class);

        $job = new SendNoDatasourceNotificationJob(99999);
        $job->handle($messenger);
    }
}
