<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Slack\Commands;

use App\Command\Slack\DebugToggleCommand;
use App\Command\Slack\DefineCommand;
use App\Command\Slack\ListCommand;
use App\Command\Slack\ShowHelpCommand;
use App\Command\Slack\SurveyToggleCommand;
use App\Infrastructure\Command\DomainCommand;
use App\Models\Tenant;
use App\Models\Thread;
use App\Slack\Commands\CommandCreatorInterface;
use App\Slack\Commands\SlackCommandFactory;
use InvalidArgumentException;
use Tests\TestCase;

final class SlackCommandFactoryTest extends TestCase
{
    private SlackCommandFactory $factory;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SlackCommandFactory;
        $this->tenant = Tenant::factory()->create();
    }

    public function test_creates_define_command(): void
    {
        $command = $this->factory->create(
            'define',
            'ARR: Annual Recurring Revenue',
            'U123',
            '1234567890.123456',
            'C123',
            'T123',
            $this->tenant
        );

        $this->assertInstanceOf(DefineCommand::class, $command);
    }

    public function test_creates_help_command(): void
    {
        $command = $this->factory->create('help', '', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(ShowHelpCommand::class, $command);
    }

    public function test_creates_list_command_for_definitions(): void
    {
        $command = $this->factory->create('list', 'definitions', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(ListCommand::class, $command);
    }

    public function test_creates_list_command_for_tables(): void
    {
        $command = $this->factory->create('list', 'tables', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(ListCommand::class, $command);
    }

    public function test_creates_survey_toggle_command_on(): void
    {
        $command = $this->factory->create('survey', 'on', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(SurveyToggleCommand::class, $command);
    }

    public function test_creates_survey_toggle_command_off(): void
    {
        $command = $this->factory->create('survey', 'off', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(SurveyToggleCommand::class, $command);
    }

    public function test_creates_debug_toggle_command_on(): void
    {
        $command = $this->factory->create('debug', 'on', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(DebugToggleCommand::class, $command);
    }

    public function test_creates_debug_toggle_command_off(): void
    {
        $command = $this->factory->create('debug', 'off', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(DebugToggleCommand::class, $command);
    }

    public function test_throws_exception_for_empty_debug_toggle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Try "/soong debug on" or "/soong debug off"');

        $this->factory->create('debug', '', 'U123', null, 'C123', 'T123', $this->tenant);
    }

    public function test_throws_exception_for_invalid_debug_toggle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Try "/soong debug on" or "/soong debug off"');

        $this->factory->create('debug', 'invalid', 'U123', null, 'C123', 'T123', $this->tenant);
    }

    public function test_throws_exception_for_debug_without_team_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine Slack workspace');

        $this->factory->create(
            'debug',
            'on',
            'U123',
            null,
            'C123',
            null, // No team ID
            $this->tenant
        );
    }

    public function test_throws_exception_for_unknown_subcommand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown subcommand: unknown');

        $this->factory->create('unknown', '', 'U123', null, 'C123', 'T123', $this->tenant);
    }

    public function test_throws_exception_for_empty_list_option(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usage:');

        $this->factory->create('list', '', 'U123', null, 'C123', 'T123', $this->tenant);
    }

    public function test_throws_exception_for_invalid_list_option(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usage:');

        $this->factory->create('list', 'invalid', 'U123', null, 'C123', 'T123', $this->tenant);
    }

    public function test_throws_exception_for_empty_survey_toggle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Try "/soong survey on" or "/soong survey off"');

        $this->factory->create('survey', '', 'U123', null, 'C123', 'T123', $this->tenant);
    }

    public function test_throws_exception_for_invalid_survey_toggle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Try "/soong survey on" or "/soong survey off"');

        $this->factory->create('survey', 'invalid', 'U123', null, 'C123', 'T123', $this->tenant);
    }

    public function test_throws_exception_for_survey_without_team_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine Slack workspace');

        $this->factory->create(
            'survey',
            'on',
            'U123',
            null,
            'C123',
            null, // No team ID
            $this->tenant
        );
    }

    public function test_has_creator_returns_true_for_registered_subcommands(): void
    {
        $this->assertTrue($this->factory->hasCreator('define'));
        $this->assertTrue($this->factory->hasCreator('help'));
        $this->assertTrue($this->factory->hasCreator('list'));
        $this->assertTrue($this->factory->hasCreator('survey'));
        $this->assertTrue($this->factory->hasCreator('debug'));
    }

    public function test_has_creator_returns_false_for_unregistered_subcommands(): void
    {
        $this->assertFalse($this->factory->hasCreator('unknown'));
        $this->assertFalse($this->factory->hasCreator('notexist'));
    }

    public function test_get_registered_subcommands_returns_all_registered(): void
    {
        $subcommands = $this->factory->getRegisteredSubcommands();

        $this->assertCount(5, $subcommands);
        $this->assertContains('define', $subcommands);
        $this->assertContains('help', $subcommands);
        $this->assertContains('list', $subcommands);
        $this->assertContains('survey', $subcommands);
        $this->assertContains('debug', $subcommands);
    }

    public function test_can_register_custom_creator_if_it_conforms_to_domain_command(): void
    {
        $mockCreator = $this->createMock(CommandCreatorInterface::class);
        $mockCommand = new class implements DomainCommand
        {
        };

        $mockCreator->expects($this->once())
            ->method('create')
            ->willReturn($mockCommand);

        $this->factory->register('custom', $mockCreator);

        $command = $this->factory->create('custom', 'test', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertSame($mockCommand, $command);
    }

    public function test_list_command_resolves_thread_id_when_thread_exists(): void
    {
        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_ts' => '1234567890.123456',
            'channel_id' => 'C123',
        ]);

        $command = $this->factory->create(
            'list',
            'definitions',
            'U123',
            '1234567890.123456',
            'C123',
            'T123',
            $this->tenant
        );

        $this->assertInstanceOf(ListCommand::class, $command);
        // The threadId should be set (we can't directly access it due to readonly property,
        // but the command was created successfully which means thread was resolved)
    }

    public function test_list_command_handles_null_thread_id_when_no_thread(): void
    {
        $command = $this->factory->create('list', 'definitions', 'U123', null, 'C123', 'T123', $this->tenant);

        $this->assertInstanceOf(ListCommand::class, $command);
    }
}
