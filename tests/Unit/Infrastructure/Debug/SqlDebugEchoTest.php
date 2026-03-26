<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Debug;

use App\Enums\Settings;
use App\Infrastructure\Debug\SqlDebugEcho;
use App\Infrastructure\Slack\SlackUserSettingService;
use App\Jobs\SendEphemeralSqlDebug;
use App\Models\Query;
use App\Models\SlackUser;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

final class SqlDebugEchoTest extends TestCase
{
    private SqlDebugEcho $sqlDebugEcho;

    private SlackUserSettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = Mockery::mock(SlackUserSettingService::class);
        $this->sqlDebugEcho = new SqlDebugEcho($this->settings);
    }

    public function test_maybe_send_dispatches_job_when_debug_enabled(): void
    {
        Bus::fake();

        // Create test models
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'tenant_id' => $tenant->id,
            'slack_user_id' => $slackUser->id,
        ]);
        $query->load(['slackUser']);

        // Mock settings to return true for DEBUG
        $this->settings
            ->shouldReceive('isEnabled')
            ->once()
            ->with($query->slackUser, Settings::DEBUG->value)
            ->andReturn(true);

        // Test parameters
        $boundParams = [
            'param1' => 'value1',
            'param2' => 42,
        ];
        $sql = 'SELECT * FROM users WHERE id = ? AND status = ?';
        $tookMs = 150;
        $rowCount = 5;
        $connectionName = 'mysql';

        $this->sqlDebugEcho->maybeSend($query, $boundParams, $sql, $tookMs, $rowCount, $connectionName);

        // Assert job was dispatched
        Bus::assertDispatched(SendEphemeralSqlDebug::class, function ($job) use (
            $query,
            $sql,
            $boundParams,
            $tookMs,
            $rowCount,
            $connectionName
        ) {
            return $job->queryId === $query->id
                && $job->channelId === $query->thread->channel_id
                && $job->userId === $query->slackUser->slack_user_id
                && str_contains($job->text, '-- Query ID: '.$query->id)
                && str_contains($job->text, '-- Connection: '.$connectionName)
                && str_contains($job->text, '-- Duration: '.$tookMs.' ms')
                && str_contains($job->text, '-- Row count: '.$rowCount)
                && str_contains($job->text, json_encode($boundParams, JSON_PRETTY_PRINT))
                && str_contains($job->text, $sql);
        });
    }

    public function test_maybe_send_does_not_dispatch_job_when_debug_disabled(): void
    {
        Bus::fake();

        // Create test models
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'tenant_id' => $tenant->id,
            'slack_user_id' => $slackUser->id,
        ]);
        $query->load(['slackUser']);

        // Mock settings to return false for DEBUG
        $this->settings
            ->shouldReceive('isEnabled')
            ->once()
            ->with($query->slackUser, Settings::DEBUG->value)
            ->andReturn(false);

        // Test parameters
        $boundParams = [
            'param1' => 'value1',
        ];
        $sql = 'SELECT * FROM users';
        $tookMs = 100;
        $rowCount = 3;
        $connectionName = 'mysql';

        $this->sqlDebugEcho->maybeSend($query, $boundParams, $sql, $tookMs, $rowCount, $connectionName);

        // Assert no job was dispatched
        Bus::assertNotDispatched(SendEphemeralSqlDebug::class);
    }

    public function test_maybe_send_does_not_dispatch_job_when_no_slack_user(): void
    {
        Bus::fake();

        // Create test models without slack user
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'tenant_id' => $tenant->id,
            'slack_user_id' => null,
        ]);

        // Settings should not be called
        $this->settings->shouldNotReceive('isEnabled');

        // Test parameters
        $boundParams = [
            'param1' => 'value1',
        ];
        $sql = 'SELECT * FROM users';
        $tookMs = 100;
        $rowCount = 3;
        $connectionName = 'mysql';

        $this->sqlDebugEcho->maybeSend($query, $boundParams, $sql, $tookMs, $rowCount, $connectionName);

        // Assert no job was dispatched
        Bus::assertNotDispatched(SendEphemeralSqlDebug::class);
    }
}
