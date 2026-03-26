<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\SendSlackAttachments;
use App\Jobs\SendSlackBlocks;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

final class SlackMessageDispatcherFeatureTest extends TestCase
{
    private SlackMessageDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the messenger to avoid real API calls
        $this->app->instance(SlackMessenger::class, Mockery::mock(SlackMessenger::class));

        $this->dispatcher = $this->app->make(SlackMessageDispatcher::class);
    }

    public function test_dispatch_blocks_with_table_creates_proper_jobs(): void
    {
        Bus::fake();

        // Create blocks with a table
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Here are the results:',
                ],
            ],
            [
                'type' => 'table',
                'rows' => [
                    [
                        [
                            'type' => 'raw_text',
                            'text' => 'Name',
                        ],
                        [
                            'type' => 'raw_text',
                            'text' => 'Age',
                        ],
                    ],
                    [
                        [
                            'type' => 'raw_text',
                            'text' => 'John',
                        ],
                        [
                            'type' => 'raw_text',
                            'text' => '25',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'That\'s all.',
                ],
            ],
        ];

        $this->dispatcher->dispatchBlocks(123, 'C123', '123.456', $blocks);

        // Should create multiple jobs due to the table
        Bus::assertDispatched(SendSlackBlocks::class, 2);
        Bus::assertDispatched(SendSlackAttachments::class, 1);

        // Rate limiting is handled inside each job; no dispatch-time delay is applied.
        $blockJobs = Bus::dispatched(SendSlackBlocks::class);
        $attachmentJobs = Bus::dispatched(SendSlackAttachments::class);

        $this->assertNull($blockJobs[0]->delay);
        $this->assertNull($attachmentJobs[0]->delay);
        $this->assertNull($blockJobs[1]->delay);
    }

    public function test_dispatch_blocks_without_table_creates_single_job(): void
    {
        Bus::fake();

        // Test with plain text blocks
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'This is a simple response without any tables.',
                ],
            ],
        ];

        $this->dispatcher->dispatchBlocks(456, 'C456', '456.789', $blocks);

        // Should create only one job with no delay
        Bus::assertDispatched(SendSlackBlocks::class, 1);
        Bus::assertNotDispatched(SendSlackAttachments::class);

        $jobs = Bus::dispatched(SendSlackBlocks::class);
        $this->assertNull($jobs[0]->delay);
        $this->assertEquals(456, $jobs[0]->queryId);
    }

    public function test_all_jobs_are_dispatched_without_delay(): void
    {
        Bus::fake();

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Before',
                ],
            ],
            [
                'type' => 'table',
                'rows' => [
                    [[
                        'type' => 'raw_text',
                        'text' => 'A',
                    ], [
                        'type' => 'raw_text',
                        'text' => 'B',
                    ]],
                    [[
                        'type' => 'raw_text',
                        'text' => '1',
                    ], [
                        'type' => 'raw_text',
                        'text' => '2',
                    ]],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'After',
                ],
            ],
        ];

        $this->dispatcher->dispatchBlocks(789, 'C789', '789.012', $blocks);

        Bus::assertDispatched(SendSlackBlocks::class, 2);
        Bus::assertDispatched(SendSlackAttachments::class, 1);

        // Rate limiting is handled in-job; no dispatch-time delay should be set.
        foreach (Bus::dispatched(SendSlackBlocks::class) as $job) {
            $this->assertNull($job->delay);
        }
        foreach (Bus::dispatched(SendSlackAttachments::class) as $job) {
            $this->assertNull($job->delay);
        }
    }
}
