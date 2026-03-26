<?php

declare(strict_types=1);

namespace Tests\Unit\PromptViews;

use App\Prompt\Views\FollowupPromptView;
use Tests\TestCase;

/**
 * Test the FollowupPromptView functionality
 */
class FollowupPromptViewTest extends TestCase
{
    /**
     * Test that FollowupPromptView renders with basic data
     */
    public function test_renders_with_basic_data(): void
    {
        $data = [
            'query_id' => 123,
            'last_sql_call_id' => 456,
            'user_query_text' => 'Export that data as CSV',
        ];

        $view = new FollowupPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('You are continuing an existing thread. Use tools to either', $rendered);
        $this->assertStringContainsString('123', $rendered);
        $this->assertStringContainsString('456', $rendered);
        $this->assertStringContainsString('Export that data as CSV', $rendered);
    }

    /**
     * Test that query_id and last_sql_call_id appear on separate lines
     */
    public function test_query_id_and_last_sql_call_id_appear_on_separate_lines(): void
    {
        $data = [
            'query_id' => 789,
            'last_sql_call_id' => 101,
            'user_query_text' => 'Show me the results',
        ];

        $view = new FollowupPromptView($data);
        $rendered = $view->render();

        // The rendered content should contain the query_id and last_sql_call_id in the user message
        $this->assertStringContainsString('789', $rendered);
        $this->assertStringContainsString('101', $rendered);
        $this->assertStringContainsString('Show me the results', $rendered);
    }

    /**
     * Test that export_csv tool information is included
     */
    public function test_export_csv_tool_information_is_included(): void
    {
        $data = [
            'query_id' => 123,
            'last_sql_call_id' => 456,
            'user_query_text' => 'Export the data',
        ];

        $view = new FollowupPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('export_csv', $rendered);
        $this->assertStringContainsString('sql_call_id', $rendered);
        $this->assertStringContainsString('CSV export requested', $rendered);
    }

    /**
     * Test that definitions are included when provided
     */
    public function test_definitions_are_included_when_provided(): void
    {
        $data = [
            'query_id' => 123,
            'last_sql_call_id' => 456,
            'user_query_text' => 'Test query',
            'definitions' => [
                [
                    'subject' => 'premium_user',
                    'definition' => 'A user with paid subscription',
                ],
                [
                    'subject' => 'trial_user',
                    'definition' => 'A user on free trial',
                ],
            ],
        ];

        $view = new FollowupPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('## Definitions', $rendered);
        $this->assertStringContainsString('premium_user => A user with paid subscription', $rendered);
        $this->assertStringContainsString('trial_user => A user on free trial', $rendered);
    }

    /**
     * Test that DDLs are included when provided
     */
    public function test_ddls_are_included_when_provided(): void
    {
        $data = [
            'query_id' => 123,
            'last_sql_call_id' => 456,
            'user_query_text' => 'Test query',
            'ddls' => [
                [
                    'table' => 'subscriptions',
                    'row_count' => 10000,
                    'size_mb' => 5.0,
                    'ddl' => 'CREATE TABLE subscriptions (id INT, user_id INT, plan VARCHAR(50))',
                ],
            ],
        ];

        $view = new FollowupPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('## Database Schema', $rendered);
        $this->assertStringContainsString('Available tables: subscriptions', $rendered);
        $this->assertStringContainsString('CREATE TABLE subscriptions', $rendered);
        $this->assertStringContainsString('10,000 rows', $rendered);
        $this->assertStringContainsString('5 MB', $rendered);
    }

    /**
     * Test that tables_available are included when provided
     */
    public function test_tables_available_are_included_when_provided(): void
    {
        $data = [
            'query_id' => 123,
            'last_sql_call_id' => 456,
            'user_query_text' => 'Test query',
            'tables_available' => [
                [
                    'name' => 'audit_logs',
                    'row_count' => 100000,
                    'size_mb' => 25.0,
                ],
                [
                    'name' => 'notifications',
                    'row_count' => 500,
                    'size_mb' => 0.5,
                ],
            ],
        ];

        $view = new FollowupPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('## Other tables available', $rendered);
        $this->assertStringContainsString('audit_logs', $rendered);
        $this->assertStringContainsString('notifications', $rendered);
        $this->assertStringContainsString('100,000 rows', $rendered);
        $this->assertStringContainsString('25 MB', $rendered);
    }

    /**
     * Test that Slack mentions are cleaned from user query text
     */
    public function test_slack_mentions_are_cleaned(): void
    {
        $data = [
            'query_id' => 123,
            'last_sql_call_id' => 456,
            'user_query_text' => '<@U123456> Can you export that?',
        ];

        $view = new FollowupPromptView($data);
        $rendered = $view->render();

        // The mention should be replaced with the model name
        $this->assertStringContainsString('gpt-4o Can you export that?', $rendered);
        $this->assertStringNotContainsString('<@U123456>', $rendered);
    }

    /**
     * Test the renderSystem and renderUser methods
     */
    public function test_render_system_and_user_methods(): void
    {
        $data = [
            'query_id' => 123,
            'last_sql_call_id' => 456,
            'user_query_text' => 'Test follow-up query',
        ];

        $view = new FollowupPromptView($data);

        $system = $view->renderSystem();
        $user = $view->renderUser();

        $this->assertStringContainsString('You are continuing an existing thread. Use tools to either', $system);
        $this->assertStringContainsString('123', $user);
        $this->assertStringContainsString('456', $user);
        $this->assertStringContainsString('Test follow-up query', $user);
    }

    /**
     * Test chaining methods
     */
    public function test_chaining_methods(): void
    {
        $view = new FollowupPromptView([
            'query_id' => 123,
        ])
            ->setLastSqlCallId(456)
            ->with([
                'user_query_text' => 'Test query',
            ]);

        $rendered = $view->render();
        $this->assertStringContainsString('123', $rendered);
        $this->assertStringContainsString('456', $rendered);
        $this->assertStringContainsString('Test query', $rendered);
    }
}
