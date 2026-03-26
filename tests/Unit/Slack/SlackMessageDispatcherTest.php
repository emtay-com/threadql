<?php

declare(strict_types=1);

namespace Tests\Unit\Slack;

use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\SendSlackAttachments;
use App\Jobs\SendSlackBlocks;
use App\Slack\Formatting\ResponseFormatter;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

final class SlackMessageDispatcherTest extends TestCase
{
    private SlackMessageDispatcher $dispatcher;

    private SlackMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messenger = Mockery::mock(SlackMessenger::class);
        // Use the real formatter since it's final and can't be mocked
        $this->dispatcher = new SlackMessageDispatcher($this->messenger, app(ResponseFormatter::class));
    }

    public function test_dispatch_blocks_without_tables_creates_single_job(): void
    {
        Bus::fake();

        // Blocks without tables
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Here is some text',
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'More text',
                ],
            ],
        ];

        $this->dispatcher->dispatchBlocks(123, 'C123', '123.456', $blocks);

        Bus::assertDispatched(SendSlackBlocks::class, 1);
        Bus::assertNotDispatched(SendSlackAttachments::class);

        // Check the job details
        $jobs = Bus::dispatched(SendSlackBlocks::class);
        $this->assertEquals(123, $jobs[0]->queryId);
        $this->assertEquals('C123', $jobs[0]->channelId);
        $this->assertEquals('123.456', $jobs[0]->threadTs);
        $this->assertNull($jobs[0]->delay);
    }

    public function test_dispatch_blocks_with_table_creates_multiple_jobs(): void
    {
        Bus::fake();

        // Blocks with a table in the middle
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Before table text',
                ],
            ],
            [
                'type' => 'table',
                'rows' => [
                    [
                        [
                            'type' => 'raw_text',
                            'text' => 'Header1',
                        ],
                        [
                            'type' => 'raw_text',
                            'text' => 'Header2',
                        ],
                    ],
                    [
                        [
                            'type' => 'raw_text',
                            'text' => 'Row1Col1',
                        ],
                        [
                            'type' => 'raw_text',
                            'text' => 'Row1Col2',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'After table text',
                ],
            ],
        ];

        $this->dispatcher->dispatchBlocks(123, 'C123', '123.456', $blocks);

        // Should dispatch 3 jobs: blocks (before), attachment (table), blocks (after)
        Bus::assertDispatched(SendSlackBlocks::class, 2);
        Bus::assertDispatched(SendSlackAttachments::class, 1);

        // Rate limiting is handled inside each job; no dispatch-time delay is applied.
        $blockJobs = Bus::dispatched(SendSlackBlocks::class);
        $attachmentJobs = Bus::dispatched(SendSlackAttachments::class);

        $this->assertNull($blockJobs[0]->delay);
        $this->assertNull($attachmentJobs[0]->delay);
        $this->assertNull($blockJobs[1]->delay);
    }

    public function test_dispatch_blocks_with_multiple_tables_creates_multiple_jobs(): void
    {
        Bus::fake();

        // Blocks with multiple tables
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Intro text',
                ],
            ],
            [
                'type' => 'table',
                'rows' => [
                    [[
                        'type' => 'raw_text',
                        'text' => 'H1',
                    ], [
                        'type' => 'raw_text',
                        'text' => 'H2',
                    ]],
                    [[
                        'type' => 'raw_text',
                        'text' => 'R1',
                    ], [
                        'type' => 'raw_text',
                        'text' => 'R2',
                    ]],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Middle text',
                ],
            ],
            [
                'type' => 'table',
                'rows' => [
                    [[
                        'type' => 'raw_text',
                        'text' => 'H3',
                    ], [
                        'type' => 'raw_text',
                        'text' => 'H4',
                    ]],
                    [[
                        'type' => 'raw_text',
                        'text' => 'R3',
                    ], [
                        'type' => 'raw_text',
                        'text' => 'R4',
                    ]],
                ],
            ],
        ];

        $this->dispatcher->dispatchBlocks(123, 'C123', '123.456', $blocks);

        // Rate limiting is handled inside each job; no dispatch-time delay is applied.
        Bus::assertDispatched(SendSlackBlocks::class, 2);
        Bus::assertDispatched(SendSlackAttachments::class, 2);

        $blockJobs = Bus::dispatched(SendSlackBlocks::class);
        $attachmentJobs = Bus::dispatched(SendSlackAttachments::class);

        $this->assertNull($blockJobs[0]->delay);
        $this->assertNull($blockJobs[1]->delay);
        $this->assertNull($attachmentJobs[0]->delay);
        $this->assertNull($attachmentJobs[1]->delay);
    }

    public function test_dispatch_from_assistant_text_creates_jobs(): void
    {
        Bus::fake();

        // Test with simple text that will be formatted by the real formatter
        $this->dispatcher->dispatchFromAssistantText(123, 'C123', '123.456', 'test response');

        Bus::assertDispatched(SendSlackBlocks::class, function ($job) {
            return $job->queryId === 123
                && $job->channelId === 'C123'
                && $job->threadTs === '123.456';
        });
    }

    public function test_extract_text_from_blocks_handles_various_block_types(): void
    {
        $reflection = new \ReflectionClass($this->dispatcher);
        $method = $reflection->getMethod('extractTextFromBlocks');
        $method->setAccessible(true);

        // Test section blocks
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Section text',
                ],
            ],
        ];
        $result = $method->invoke($this->dispatcher, $blocks);
        $this->assertEquals('Section text', $result);

        // Test context blocks
        $blocks = [
            [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => 'Context text',
                    ],
                ],
            ],
        ];
        $result = $method->invoke($this->dispatcher, $blocks);
        $this->assertEquals('Context text', $result);

        // Test truncation
        $longText = str_repeat('a', 200);
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $longText,
                ],
            ],
        ];
        $result = $method->invoke($this->dispatcher, $blocks);
        $this->assertStringEndsWith('...', $result);
        $this->assertLessThanOrEqual(150, strlen($result));
    }
}
