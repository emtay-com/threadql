<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Messages\FetchTableDdlsMessages;
use App\Support\Messages\RunSqlQueryMessages;
use Tests\TestCase;

class ToolMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        RunSqlQueryMessages::resetFake();
        FetchTableDdlsMessages::resetFake();

        parent::tearDown();
    }

    public function test_run_sql_query_messages_returns_valid_messages(): void
    {
        for ($i = 0; $i < 10; $i++) {
            RunSqlQueryMessages::fakeIndex($i);
            $message = RunSqlQueryMessages::random();
            $this->assertContains($message, RunSqlQueryMessages::MESSAGES);
            $this->assertGreaterThan(0, strlen($message));
        }
    }

    public function test_run_sql_query_messages_with_explicit_index(): void
    {
        foreach (RunSqlQueryMessages::MESSAGES as $index => $expectedMessage) {
            RunSqlQueryMessages::fakeIndex($index);
            $this->assertEquals($expectedMessage, RunSqlQueryMessages::random());
        }
    }

    public function test_run_sql_query_messages_index_wraps_around(): void
    {
        $count = count(RunSqlQueryMessages::MESSAGES);
        RunSqlQueryMessages::fakeIndex($count + 1);
        $message = RunSqlQueryMessages::random();
        $this->assertIsString($message);
        $this->assertContains($message, RunSqlQueryMessages::MESSAGES);
    }

    public function test_fetch_table_ddls_messages_returns_valid_messages(): void
    {
        for ($i = 0; $i < 10; $i++) {
            FetchTableDdlsMessages::fakeIndex($i);
            $message = FetchTableDdlsMessages::random();
            $this->assertContains($message, FetchTableDdlsMessages::MESSAGES);
            $this->assertGreaterThan(0, strlen($message));
        }
    }

    public function test_fetch_table_ddls_messages_with_explicit_index(): void
    {
        foreach (FetchTableDdlsMessages::MESSAGES as $index => $expectedMessage) {
            FetchTableDdlsMessages::fakeIndex($index);
            $this->assertEquals($expectedMessage, FetchTableDdlsMessages::random());
        }
    }

    public function test_fetch_table_ddls_messages_index_wraps_around(): void
    {
        $count = count(FetchTableDdlsMessages::MESSAGES);
        FetchTableDdlsMessages::fakeIndex($count + 1);
        $message = FetchTableDdlsMessages::random();
        $this->assertIsString($message);
        $this->assertContains($message, FetchTableDdlsMessages::MESSAGES);
    }

    public function test_messages_are_deterministic_with_index(): void
    {
        RunSqlQueryMessages::fakeIndex(0);
        $message1 = RunSqlQueryMessages::random();
        RunSqlQueryMessages::fakeIndex(0);
        $message2 = RunSqlQueryMessages::random();
        $this->assertEquals($message1, $message2);

        FetchTableDdlsMessages::fakeIndex(1);
        $message3 = FetchTableDdlsMessages::random();
        FetchTableDdlsMessages::fakeIndex(1);
        $message4 = FetchTableDdlsMessages::random();
        $this->assertEquals($message3, $message4);
    }

    public function test_messages_contain_no_invalid_characters(): void
    {
        foreach (RunSqlQueryMessages::MESSAGES as $message) {
            $this->assertStringNotContainsString("\n", $message);
            $this->assertStringNotContainsString("\t", $message);
            $this->assertGreaterThan(10, strlen($message));
            $this->assertLessThan(100, strlen($message));
        }

        foreach (FetchTableDdlsMessages::MESSAGES as $message) {
            $this->assertStringNotContainsString("\n", $message);
            $this->assertStringNotContainsString("\t", $message);
            $this->assertGreaterThan(10, strlen($message));
            $this->assertLessThan(100, strlen($message));
        }
    }
}
