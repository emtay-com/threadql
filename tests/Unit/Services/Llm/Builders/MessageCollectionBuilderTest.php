<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm\Builders;

use App\Enums\MessageRole;
use App\Services\Llm\Builders\MessageCollectionBuilder;
use Tests\TestCase;

final class MessageCollectionBuilderTest extends TestCase
{
    public function test_builds_empty_array_by_default(): void
    {
        $builder = new MessageCollectionBuilder;

        $messages = $builder->build();

        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
        $this->assertTrue($builder->isEmpty());
        $this->assertEquals(0, $builder->count());
    }

    public function test_adds_system_message(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addSystemMessage('You are a helpful assistant');
        $messages = $builder->build();

        $this->assertCount(1, $messages);
        $this->assertEquals(MessageRole::SYSTEM->value, $messages[0]['role']);
        $this->assertEquals('You are a helpful assistant', $messages[0]['content']);
    }

    public function test_adds_user_message(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addUserMessage('What is the weather?');
        $messages = $builder->build();

        $this->assertCount(1, $messages);
        $this->assertEquals(MessageRole::USER->value, $messages[0]['role']);
        $this->assertEquals('What is the weather?', $messages[0]['content']);
    }

    public function test_adds_assistant_message(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addAssistantMessage('The weather is sunny');
        $messages = $builder->build();

        $this->assertCount(1, $messages);
        $this->assertEquals(MessageRole::ASSISTANT->value, $messages[0]['role']);
        $this->assertEquals('The weather is sunny', $messages[0]['content']);
    }

    public function test_adds_message_with_custom_role(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addMessage('tool', 'Tool result here');
        $messages = $builder->build();

        $this->assertCount(1, $messages);
        $this->assertEquals('tool', $messages[0]['role']);
        $this->assertEquals('Tool result here', $messages[0]['content']);
    }

    public function test_does_not_add_empty_system_message(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addSystemMessage('');
        $messages = $builder->build();

        $this->assertEmpty($messages);
    }

    public function test_does_not_add_empty_user_message(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addUserMessage('');
        $messages = $builder->build();

        $this->assertEmpty($messages);
    }

    public function test_does_not_add_empty_assistant_message(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addAssistantMessage('');
        $messages = $builder->build();

        $this->assertEmpty($messages);
    }

    public function test_adds_definitions_message(): void
    {
        $builder = new MessageCollectionBuilder;

        $definitions = [
            [
                'subject' => 'ARR',
                'definition' => 'Annual Recurring Revenue',
            ],
            [
                'subject' => 'MRR',
                'definition' => 'Monthly Recurring Revenue',
            ],
        ];

        $builder->addDefinitionsMessage($definitions);
        $messages = $builder->build();

        $this->assertCount(1, $messages);
        $this->assertEquals(MessageRole::USER->value, $messages[0]['role']);
        $this->assertStringContainsString('Here is the definition', $messages[0]['content']);
        $this->assertStringContainsString('ARR => Annual Recurring Revenue', $messages[0]['content']);
        $this->assertStringContainsString('MRR => Monthly Recurring Revenue', $messages[0]['content']);
    }

    public function test_does_not_add_definitions_message_when_empty(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder->addDefinitionsMessage([]);
        $messages = $builder->build();

        $this->assertEmpty($messages);
    }

    public function test_fluent_interface_chains_multiple_messages(): void
    {
        $builder = new MessageCollectionBuilder;

        $messages = $builder
            ->addSystemMessage('System prompt')
            ->addUserMessage('User question')
            ->addAssistantMessage('Assistant response')
            ->addUserMessage('Follow-up')
            ->build();

        $this->assertCount(4, $messages);
        $this->assertEquals(MessageRole::SYSTEM->value, $messages[0]['role']);
        $this->assertEquals(MessageRole::USER->value, $messages[1]['role']);
        $this->assertEquals(MessageRole::ASSISTANT->value, $messages[2]['role']);
        $this->assertEquals(MessageRole::USER->value, $messages[3]['role']);
    }

    public function test_count_returns_correct_number(): void
    {
        $builder = new MessageCollectionBuilder;

        $this->assertEquals(0, $builder->count());

        $builder->addUserMessage('Message 1');
        $this->assertEquals(1, $builder->count());

        $builder->addAssistantMessage('Message 2');
        $this->assertEquals(2, $builder->count());

        $builder->addUserMessage('Message 3');
        $this->assertEquals(3, $builder->count());
    }

    public function test_is_empty_returns_false_when_messages_exist(): void
    {
        $builder = new MessageCollectionBuilder;

        $this->assertTrue($builder->isEmpty());

        $builder->addUserMessage('Test');

        $this->assertFalse($builder->isEmpty());
    }

    public function test_create_static_method_returns_new_instance(): void
    {
        $builder = MessageCollectionBuilder::create();

        $this->assertInstanceOf(MessageCollectionBuilder::class, $builder);
    }

    public function test_reset_clears_all_messages(): void
    {
        $builder = new MessageCollectionBuilder;

        $builder
            ->addSystemMessage('System')
            ->addUserMessage('User')
            ->reset();

        $messages = $builder->build();

        $this->assertEmpty($messages);
        $this->assertEquals(0, $builder->count());
        $this->assertTrue($builder->isEmpty());
    }

    public function test_reset_allows_reuse_of_builder(): void
    {
        $builder = new MessageCollectionBuilder;

        $messages1 = $builder
            ->addUserMessage('First conversation')
            ->build();

        $this->assertCount(1, $messages1);

        $builder->reset();

        $messages2 = $builder
            ->addUserMessage('Second conversation')
            ->build();

        $this->assertCount(1, $messages2);
        $this->assertEquals('Second conversation', $messages2[0]['content']);
    }
}
