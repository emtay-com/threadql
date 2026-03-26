<?php

declare(strict_types=1);

namespace Tests\Unit\PromptViews;

use App\Prompt\Views\InitialPromptView;
use Tests\TestCase;

/**
 * Test the InitialPromptView functionality
 */
class InitialPromptViewTest extends TestCase
{
    /**
     * Test that InitialPromptView renders with basic data
     */
    public function test_renders_with_basic_data(): void
    {
        $data = [
            'query_id' => 123,
            'user_query_text' => 'Show me all users',
        ];

        $view = new InitialPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('Role: SQL Planner & Runner', $rendered);
        $this->assertStringContainsString('123', $rendered);
        $this->assertStringContainsString('Show me all users', $rendered);
    }

    /**
     * Test that query_id appears in the user message
     */
    public function test_query_id_appears_in_user_message(): void
    {
        $data = [
            'query_id' => 456,
            'user_query_text' => 'Test query',
        ];

        $view = new InitialPromptView($data);
        $rendered = $view->render();

        // The rendered content should contain the query_id in the user message
        $this->assertStringContainsString('query_id: 456', $rendered);
    }

    /**
     * Test that definitions are included when provided
     */
    public function test_definitions_are_included_when_provided(): void
    {
        $data = [
            'query_id' => 123,
            'user_query_text' => 'Test query',
            'definitions' => [
                [
                    'subject' => 'user',
                    'definition' => 'A person who uses the system',
                ],
                [
                    'subject' => 'admin',
                    'definition' => 'A user with elevated privileges',
                ],
            ],
        ];

        $view = new InitialPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('## Definitions', $rendered);
        $this->assertStringContainsString('user => A person who uses the system', $rendered);
        $this->assertStringContainsString('admin => A user with elevated privileges', $rendered);
    }

    /**
     * Test that DDLs are included when provided
     */
    public function test_ddls_are_included_when_provided(): void
    {
        $data = [
            'query_id' => 123,
            'user_query_text' => 'Test query',
            'ddls' => [
                [
                    'table' => 'users',
                    'row_count' => 5000,
                    'size_mb' => 2.5,
                    'ddl' => 'CREATE TABLE users (id INT, name VARCHAR(255))',
                ],
                [
                    'table' => 'orders',
                    'row_count' => 100000,
                    'size_mb' => 50.0,
                    'ddl' => 'CREATE TABLE orders (id INT, user_id INT)',
                ],
            ],
        ];

        $view = new InitialPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('## Database Schema', $rendered);
        $this->assertStringContainsString('Available tables: users, orders', $rendered);
        $this->assertStringContainsString('CREATE TABLE users', $rendered);
        $this->assertStringContainsString('CREATE TABLE orders', $rendered);
        $this->assertStringContainsString('5,000 rows', $rendered);
        $this->assertStringContainsString('2.5 MB', $rendered);
    }

    /**
     * Test that tables_available are included when provided
     */
    public function test_tables_available_are_included_when_provided(): void
    {
        $data = [
            'query_id' => 123,
            'user_query_text' => 'Test query',
            'tables_available' => [
                [
                    'name' => 'logs',
                    'row_count' => 50000,
                    'size_mb' => 12.5,
                ],
                [
                    'name' => 'settings',
                    'row_count' => 100,
                    'size_mb' => 0.1,
                ],
                [
                    'name' => 'cache',
                    'row_count' => null,
                    'size_mb' => null,
                ],
            ],
        ];

        $view = new InitialPromptView($data);
        $rendered = $view->render();

        $this->assertStringContainsString('## Other tables available', $rendered);
        $this->assertStringContainsString('logs', $rendered);
        $this->assertStringContainsString('settings', $rendered);
        $this->assertStringContainsString('cache', $rendered);
        $this->assertStringContainsString('50,000 rows', $rendered);
        $this->assertStringContainsString('12.5 MB', $rendered);
    }

    /**
     * Test that Slack mentions are cleaned from user query text
     */
    public function test_slack_mentions_are_cleaned(): void
    {
        $data = [
            'query_id' => 123,
            'user_query_text' => '<@U123456> Show me the users',
        ];

        $view = new InitialPromptView($data);
        $rendered = $view->render();

        // The user message should contain the cleaned text
        $this->assertStringContainsString('Show me the users', $rendered);
        // The mention should not appear in the rendered output
        $this->assertStringNotContainsString('<@U123456>', $rendered);
    }

    /**
     * Test the renderSystem and renderUser methods
     */
    public function test_render_system_and_user_methods(): void
    {
        $data = [
            'query_id' => 123,
            'user_query_text' => 'Test query',
        ];

        $view = new InitialPromptView($data);

        $system = $view->renderSystem();
        $user = $view->renderUser();

        $this->assertStringContainsString('Role: SQL Planner & Runner', $system);
        $this->assertStringContainsString('123', $user);
        $this->assertStringContainsString('Test query', $user);
    }

    /**
     * Test the with() method for chaining data
     */
    public function test_with_method_for_chaining(): void
    {
        $view = new InitialPromptView([
            'query_id' => 123,
        ])
            ->with([
                'user_query_text' => 'Test query',
            ]);

        $rendered = $view->render();
        $this->assertStringContainsString('Test query', $rendered);
    }
}
