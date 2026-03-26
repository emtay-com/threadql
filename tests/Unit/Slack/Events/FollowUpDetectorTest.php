<?php

declare(strict_types=1);

namespace Tests\Unit\Slack\Events;

use App\Enums\QueryStatus;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Slack\Events\FollowUpDetector;
use Tests\TestCase;

class FollowUpDetectorTest extends TestCase
{
    private FollowUpDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new FollowUpDetector();
    }

    public function test_has_in_flight_query_returns_false_for_empty_thread(): void
    {
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertFalse($this->detector->hasInFlightQuery($thread));
    }

    public function test_has_in_flight_query_returns_true_when_query_is_received(): void
    {
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::RECEIVED->value,
        ]);

        $this->assertTrue($this->detector->hasInFlightQuery($thread));
    }

    public function test_has_in_flight_query_returns_true_when_query_is_executing(): void
    {
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::EXECUTING->value,
        ]);

        $this->assertTrue($this->detector->hasInFlightQuery($thread));
    }

    public function test_has_in_flight_query_returns_true_when_query_is_planning(): void
    {
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::PLANNING->value,
        ]);

        $this->assertTrue($this->detector->hasInFlightQuery($thread));
    }

    public function test_has_in_flight_query_returns_false_when_all_queries_are_done(): void
    {
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::DONE->value,
        ]);

        $this->assertFalse($this->detector->hasInFlightQuery($thread));
    }

    public function test_has_in_flight_query_returns_false_when_all_queries_are_errored(): void
    {
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::ERROR->value,
        ]);

        $this->assertFalse($this->detector->hasInFlightQuery($thread));
    }

    public function test_has_in_flight_query_returns_true_when_one_done_and_one_in_progress(): void
    {
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::DONE->value,
        ]);
        Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::EXECUTING->value,
        ]);

        $this->assertTrue($this->detector->hasInFlightQuery($thread));
    }
}
