<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Slack\Actions;

use App\Exceptions\EntityNotFoundException;
use App\Http\Controllers\Slack\Actions\FeedbackActionHandler;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Models\Tenant;
use Exception;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class FeedbackActionHandlerTest extends TestCase
{
    private SlackMessenger $slackMessenger;

    private FeedbackActionHandler $handler;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slackMessenger = $this->createMock(SlackMessenger::class);
        $this->handler = new FeedbackActionHandler($this->slackMessenger);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_handle_updates_query_score_for_yes_vote(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'score' => 0,
        ]);

        $payload = $this->createPayload($query->id);

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessage');

        $response = $this->handler->handle($query->id, 'yes', $payload, $this->tenant);

        $this->assertEquals(204, $response->getStatusCode());

        $query->refresh();
        $this->assertEquals(1, $query->score);
    }

    public function test_handle_updates_query_score_for_no_vote(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'score' => 0,
        ]);

        $payload = $this->createPayload($query->id);

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessage');

        $response = $this->handler->handle($query->id, 'no', $payload, $this->tenant);

        $this->assertEquals(204, $response->getStatusCode());

        $query->refresh();
        $this->assertEquals(-1, $query->score);
    }

    public function test_handle_ignores_invalid_vote(): void
    {
        Log::shouldReceive('warning')->once()->with('Invalid vote value', [
            'vote' => 'invalid',
            'query_id' => 999,
        ]);

        $payload = $this->createPayload(999);

        $this->slackMessenger
            ->expects($this->never())
            ->method('updateMessage');

        $response = $this->handler->handle(999, 'invalid', $payload, $this->tenant);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function test_handle_throws_exception_for_nonexistent_query(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage('Query');

        $payload = $this->createPayload(999);

        $this->slackMessenger
            ->expects($this->never())
            ->method('updateMessage');

        $this->handler->handle(999, 'yes', $payload, $this->tenant);
    }

    public function test_handle_updates_feedback_message_in_slack(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $payload = $this->createPayload($query->id);

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessage')
            ->with(
                $this->callback(fn ($t) => $t->id === $this->tenant->id),
                'C123456',
                '1234567890.123456',
                '_Thanks for the feedback!_',
                $this->callback(function ($blocks) {
                    // Should have removed actions and added thank you
                    $this->assertCount(2, $blocks);
                    $this->assertEquals('section', $blocks[0]['type']);
                    $this->assertEquals('context', $blocks[1]['type']);
                    $this->assertStringContainsString('Thanks for the feedback!', $blocks[1]['elements'][0]['text']);

                    return true;
                })
            );

        $response = $this->handler->handle($query->id, 'yes', $payload, $this->tenant);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function test_handle_logs_successful_feedback(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $payload = $this->createPayload($query->id);

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessage');

        Log::shouldReceive('info')->once()->with('Successfully processed feedback vote', [
            'query_id' => $query->id,
            'vote' => 'yes',
            'score' => 1,
        ]);

        $this->handler->handle($query->id, 'yes', $payload, $this->tenant);
    }

    public function test_handle_catches_and_logs_slack_update_failure(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $payload = $this->createPayload($query->id);

        $exception = new Exception('Slack API error');

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessage')
            ->willThrowException($exception);

        Log::shouldReceive('warning')->once()->with('Failed to update feedback message', [
            'query_id' => (string) $query->id,
            'error' => 'Slack API error',
        ]);

        Log::shouldReceive('info')->once();

        $response = $this->handler->handle($query->id, 'yes', $payload, $this->tenant);

        // Should still return success even if Slack update fails
        $this->assertEquals(204, $response->getStatusCode());

        // But query score should still be updated
        $query->refresh();
        $this->assertEquals(1, $query->score);
    }

    public function test_handle_skips_message_update_when_channel_missing(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $payload = [
            'message' => [
                'ts' => '1234567890.123456',
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => 'Result',
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [],
                    ],
                ],
            ],
            'actions' => [
                [
                    'value' => (string) $query->id,
                ],
            ],
        ];

        $this->slackMessenger
            ->expects($this->never())
            ->method('updateMessage');

        Log::shouldReceive('info')->once();

        $response = $this->handler->handle($query->id, 'yes', $payload, $this->tenant);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function test_handle_skips_message_update_when_ts_missing(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $payload = [
            'channel' => [
                'id' => 'C123456',
            ],
            'message' => [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => 'Result',
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [],
                    ],
                ],
            ],
            'actions' => [
                [
                    'value' => (string) $query->id,
                ],
            ],
        ];

        $this->slackMessenger
            ->expects($this->never())
            ->method('updateMessage');

        Log::shouldReceive('info')->once();

        $response = $this->handler->handle($query->id, 'yes', $payload, $this->tenant);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function test_handle_uses_container_message_ts_as_fallback(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $payload = [
            'channel' => [
                'id' => 'C123456',
            ],
            'message' => [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => 'Result',
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [],
                    ],
                ],
            ],
            'container' => [
                'message_ts' => '9876543210.654321',
            ],
            'actions' => [
                [
                    'value' => (string) $query->id,
                ],
            ],
        ];

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessage')
            ->with($this->anything(), 'C123456', '9876543210.654321', $this->anything(), $this->anything());

        Log::shouldReceive('info')->once();

        $this->handler->handle($query->id, 'yes', $payload, $this->tenant);
    }

    /**
     * Create a standard test payload
     */
    private function createPayload(int $queryId): array
    {
        return [
            'channel' => [
                'id' => 'C123456',
            ],
            'message' => [
                'ts' => '1234567890.123456',
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => 'Query result here',
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [
                            [
                                'type' => 'button',
                                'text' => 'Yes',
                            ],
                            [
                                'type' => 'button',
                                'text' => 'No',
                            ],
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'value' => (string) $queryId,
                ],
            ],
        ];
    }
}
