<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Queries\Anchors;

use App\Domain\Queries\Anchors\AnchorType;
use App\Domain\Queries\Anchors\QueryAnchorManager;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Models\QueryAnchor;
use App\Models\Tenant;
use App\Models\Thread;
use App\Repositories\QueryAnchorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueryAnchorManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private SlackMessenger|MockInterface $slackMessenger;

    private QueryAnchorService|MockInterface $anchorRepository;

    private QueryAnchorManager $manager;

    private Tenant $tenant;

    private Thread $thread;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slackMessenger = Mockery::mock(SlackMessenger::class);
        $this->anchorRepository = Mockery::mock(QueryAnchorService::class);
        $this->manager = new QueryAnchorManager($this->slackMessenger, $this->anchorRepository);

        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => 'C12345678',
            'last_message_ts' => '1234567890.123456',
        ]);
        $this->query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
        ]);
    }

    #[Test]
    public function upsert_table_anchor_creates_new_anchor_when_none_exists(): void
    {
        $tablePayload = [
            'blocks' => [[
                'type' => 'table',
            ]],
        ];

        $this->anchorRepository
            ->shouldReceive('getByQueryAndType')
            ->with($this->query->id, AnchorType::TABLE)
            ->once()
            ->andReturn(null);

        $this->slackMessenger
            ->shouldReceive('replyInThreadAsAttachment')
            ->once()
            ->andReturn([
                'ts' => '1234567890.999999',
            ]);

        $this->anchorRepository
            ->shouldReceive('createForQuery')
            ->once()
            ->withArgs(function ($q, $type, $ts, $blocks, $attachments) {
                return $q->id === $this->query->id
                    && $type === AnchorType::TABLE
                    && $ts === '1234567890.999999'
                    && $blocks === null;
            });

        $this->manager->upsertTableAnchor($this->query, $tablePayload);
    }

    #[Test]
    public function upsert_table_anchor_updates_existing_anchor(): void
    {
        $tablePayload = [
            'blocks' => [[
                'type' => 'table',
            ]],
        ];

        $existingAnchor = new QueryAnchor([
            'query_id' => $this->query->id,
            'anchor_type' => AnchorType::TABLE->value,
            'message_ts' => '1234567890.111111',
        ]);
        $existingAnchor->id = 1;

        $this->anchorRepository
            ->shouldReceive('getByQueryAndType')
            ->with($this->query->id, AnchorType::TABLE)
            ->once()
            ->andReturn($existingAnchor);

        $this->slackMessenger
            ->shouldReceive('updateMessageAttachments')
            ->with(
                Mockery::on(fn ($t) => $t->id === $this->tenant->id),
                'C12345678',
                '1234567890.111111',
                'Query results (updated)',
                [$tablePayload]
            )
            ->once();

        $this->anchorRepository
            ->shouldReceive('updateAttachments')
            ->with($existingAnchor, $tablePayload)
            ->once();

        $this->manager->upsertTableAnchor($this->query, $tablePayload);
    }

    #[Test]
    public function upsert_table_anchor_does_not_create_anchor_when_slack_returns_no_ts(): void
    {
        $tablePayload = [
            'blocks' => [[
                'type' => 'table',
            ]],
        ];

        $this->anchorRepository
            ->shouldReceive('getByQueryAndType')
            ->andReturn(null);

        $this->slackMessenger
            ->shouldReceive('replyInThreadAsAttachment')
            ->andReturn(null); // No ts returned

        $this->anchorRepository
            ->shouldNotReceive('createForQuery');

        $this->manager->upsertTableAnchor($this->query, $tablePayload);
    }

    #[Test]
    public function upsert_paging_anchor_creates_new_anchor_when_none_exists(): void
    {
        $blocksPayload = [
            'text' => 'Total: 100',
            'blocks' => [[
                'type' => 'section',
            ]],
        ];

        $this->anchorRepository
            ->shouldReceive('getByQueryAndType')
            ->with($this->query->id, AnchorType::PAGINATION_BLOCKS)
            ->once()
            ->andReturn(null);

        $this->slackMessenger
            ->shouldReceive('replyInThreadWithBlocks')
            ->once()
            ->andReturn([
                'ts' => '1234567890.222222',
            ]);

        $this->anchorRepository
            ->shouldReceive('createForQuery')
            ->once()
            ->withArgs(function ($q, $type, $ts, $blocks) {
                return $q->id === $this->query->id
                    && $type === AnchorType::PAGINATION_BLOCKS
                    && $ts === '1234567890.222222';
            });

        $this->manager->upsertPagingAnchor($this->query, $blocksPayload);
    }

    #[Test]
    public function upsert_paging_anchor_updates_existing_anchor(): void
    {
        $blocksPayload = [
            'text' => 'Total: 100',
            'blocks' => [[
                'type' => 'section',
            ]],
        ];

        $existingAnchor = new QueryAnchor([
            'query_id' => $this->query->id,
            'anchor_type' => AnchorType::PAGINATION_BLOCKS->value,
            'message_ts' => '1234567890.333333',
        ]);
        $existingAnchor->id = 2;

        $this->anchorRepository
            ->shouldReceive('getByQueryAndType')
            ->with($this->query->id, AnchorType::PAGINATION_BLOCKS)
            ->once()
            ->andReturn($existingAnchor);

        $this->slackMessenger
            ->shouldReceive('updateMessageBlocks')
            ->with(
                Mockery::on(fn ($t) => $t->id === $this->tenant->id),
                'C12345678',
                '1234567890.333333',
                'Total: 100',
                [[
                    'type' => 'section',
                ]]
            )
            ->once();

        $this->anchorRepository
            ->shouldReceive('updateBlocks')
            ->with($existingAnchor, [[
                'type' => 'section',
            ]])
            ->once();

        $this->manager->upsertPagingAnchor($this->query, $blocksPayload);
    }

    #[Test]
    public function hide_paging_anchor_updates_with_all_results_shown_message(): void
    {
        $existingAnchor = new QueryAnchor([
            'query_id' => $this->query->id,
            'anchor_type' => AnchorType::PAGINATION_BLOCKS->value,
            'message_ts' => '1234567890.333333',
        ]);
        $existingAnchor->id = 2;

        $this->anchorRepository
            ->shouldReceive('getByQueryAndType')
            ->with($this->query->id, AnchorType::PAGINATION_BLOCKS)
            ->once()
            ->andReturn($existingAnchor);

        $this->slackMessenger
            ->shouldReceive('updateMessageBlocks')
            ->with(
                Mockery::on(fn ($t) => $t->id === $this->tenant->id),
                'C12345678',
                '1234567890.333333',
                'All results shown',
                Mockery::on(fn ($blocks) => $blocks[0]['text']['text'] === '_All results shown._')
            )
            ->once();

        $this->anchorRepository
            ->shouldReceive('updateBlocks')
            ->once();

        $this->manager->hidePagingAnchor($this->query);
    }

    #[Test]
    public function hide_paging_anchor_does_nothing_when_no_anchor_exists(): void
    {
        $this->anchorRepository
            ->shouldReceive('getByQueryAndType')
            ->with($this->query->id, AnchorType::PAGINATION_BLOCKS)
            ->once()
            ->andReturn(null);

        $this->slackMessenger
            ->shouldNotReceive('updateMessageBlocks');

        $this->anchorRepository
            ->shouldNotReceive('updateBlocks');

        $this->manager->hidePagingAnchor($this->query);
    }
}
