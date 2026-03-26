<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Slack;

use App\Infrastructure\Slack\DefinitionModalBuilder;
use PHPUnit\Framework\TestCase;

class DefinitionModalBuilderTest extends TestCase
{
    private DefinitionModalBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DefinitionModalBuilder();
    }

    public function test_builds_modal_with_correct_structure(): void
    {
        $queryId = 123;
        $subject = 'test subject';

        $modal = $this->builder->buildDefinitionModal($queryId, $subject);

        $this->assertIsArray($modal);
        $this->assertEquals('modal', $modal['type']);
        $this->assertEquals("request_definition_modal_{$queryId}", $modal['callback_id']);
    }

    public function test_modal_has_correct_title(): void
    {
        $modal = $this->builder->buildDefinitionModal(1, 'test');

        $this->assertArrayHasKey('title', $modal);
        $this->assertEquals('plain_text', $modal['title']['type']);
        $this->assertEquals('Add Definition', $modal['title']['text']);
    }

    public function test_modal_has_submit_button(): void
    {
        $modal = $this->builder->buildDefinitionModal(1, 'test');

        $this->assertArrayHasKey('submit', $modal);
        $this->assertEquals('plain_text', $modal['submit']['type']);
        $this->assertEquals('Save', $modal['submit']['text']);
    }

    public function test_modal_has_close_button(): void
    {
        $modal = $this->builder->buildDefinitionModal(1, 'test');

        $this->assertArrayHasKey('close', $modal);
        $this->assertEquals('plain_text', $modal['close']['type']);
        $this->assertEquals('Cancel', $modal['close']['text']);
    }

    public function test_modal_has_two_input_blocks(): void
    {
        $modal = $this->builder->buildDefinitionModal(1, 'test');

        $this->assertArrayHasKey('blocks', $modal);
        $this->assertIsArray($modal['blocks']);
        $this->assertCount(2, $modal['blocks']);
    }

    public function test_subject_block_has_correct_structure(): void
    {
        $subject = 'My Test Subject';
        $modal = $this->builder->buildDefinitionModal(1, $subject);

        $subjectBlock = $modal['blocks'][0];

        $this->assertEquals('input', $subjectBlock['type']);
        $this->assertEquals('subject_block', $subjectBlock['block_id']);
        $this->assertEquals('Subject', $subjectBlock['label']['text']);
        $this->assertEquals('plain_text_input', $subjectBlock['element']['type']);
        $this->assertEquals('subject', $subjectBlock['element']['action_id']);
        $this->assertEquals($subject, $subjectBlock['element']['initial_value']);
    }

    public function test_definition_block_has_correct_structure(): void
    {
        $modal = $this->builder->buildDefinitionModal(1, 'test');

        $definitionBlock = $modal['blocks'][1];

        $this->assertEquals('input', $definitionBlock['type']);
        $this->assertEquals('definition_block', $definitionBlock['block_id']);
        $this->assertEquals('Definition', $definitionBlock['label']['text']);
        $this->assertEquals('plain_text_input', $definitionBlock['element']['type']);
        $this->assertEquals('definition', $definitionBlock['element']['action_id']);
        $this->assertTrue($definitionBlock['element']['multiline']);
    }

    public function test_definition_block_has_placeholder(): void
    {
        $modal = $this->builder->buildDefinitionModal(1, 'test');

        $definitionBlock = $modal['blocks'][1];

        $this->assertArrayHasKey('placeholder', $definitionBlock['element']);
        $this->assertEquals('plain_text', $definitionBlock['element']['placeholder']['type']);
        $this->assertEquals('e.g., member with status 3', $definitionBlock['element']['placeholder']['text']);
    }

    public function test_modal_callback_id_includes_query_id(): void
    {
        $queryId = 456;
        $modal = $this->builder->buildDefinitionModal($queryId, 'test');

        $this->assertStringContainsString((string) $queryId, $modal['callback_id']);
    }

    public function test_subject_initial_value_preserved(): void
    {
        $subject = 'Complex Subject with Special Chars !@#$%';
        $modal = $this->builder->buildDefinitionModal(1, $subject);

        $this->assertEquals($subject, $modal['blocks'][0]['element']['initial_value']);
    }

    public function test_empty_subject_handled(): void
    {
        $modal = $this->builder->buildDefinitionModal(1, '');

        $this->assertEquals('', $modal['blocks'][0]['element']['initial_value']);
    }

    public function test_large_query_id_handled(): void
    {
        $queryId = 999999999;
        $modal = $this->builder->buildDefinitionModal($queryId, 'test');

        $this->assertEquals("request_definition_modal_{$queryId}", $modal['callback_id']);
    }
}
