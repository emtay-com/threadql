<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\ExportCsvTool;
use App\Mcp\FetchTableDdlsTool;
use App\Mcp\RequestDefinitionTool;
use App\Mcp\RunQueryForCsvExportTool;
use App\Mcp\RunSqlQueryTool;
use App\Mcp\ThreadqlServer;
use Laravel\Mcp\Server\Tool;
use Tests\TestCase;

/**
 * Test MCP server discovery endpoints and protocol compliance
 *
 * These tests verify that the MCP (Model Context Protocol) server is properly
 * configured and responds according to the MCP specification.
 */
class McpDiscoveryTest extends TestCase
{
    /**
     * Test that GET requests to MCP endpoint return 405 Method Not Allowed
     *
     * The laravel/mcp package returns 405 for GET requests since it uses
     * POST-based JSON-RPC communication.
     */
    public function test_get_request_returns_method_not_allowed(): void
    {
        $response = $this->get('/mcp');

        $response->assertStatus(405);
    }

    /**
     * Test JSON-RPC endpoint accepts POST requests
     *
     * Verifies that the MCP server responds to JSON-RPC requests with proper
     * protocol compliance.
     */
    public function test_jsonrpc_endpoint_accepts_requests(): void
    {
        $sessionId = 'test-session-'.uniqid();

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'test-client',
                    'version' => '1.0.0',
                ],
            ],
        ], [
            'Mcp-Session-Id' => $sessionId,
        ]);

        // At minimum, we should get a valid JSON-RPC response
        $response->assertJsonStructure(['jsonrpc', 'id']);

        $data = $response->json();
        $this->assertEquals('2.0', $data['jsonrpc']);

        // Verify that the MCP server is responding to JSON-RPC requests
        $this->assertContains(
            $response->status(),
            [200, 400, 500],
            'MCP server should respond to JSON-RPC requests'
        );
    }

    /**
     * Test that all MCP tools can be instantiated
     *
     * Verifies that MCP tool service classes exist and can be instantiated,
     * which means the tools are properly registered.
     */
    public function test_all_tools_are_instantiable(): void
    {
        // Test that all tools can be instantiated
        $runSqlQueryTool = app(RunSqlQueryTool::class);
        $this->assertInstanceOf(RunSqlQueryTool::class, $runSqlQueryTool);

        $runQueryForCsvExportTool = app(RunQueryForCsvExportTool::class);
        $this->assertInstanceOf(RunQueryForCsvExportTool::class, $runQueryForCsvExportTool);

        $fetchTableDdlsTool = app(FetchTableDdlsTool::class);
        $this->assertInstanceOf(FetchTableDdlsTool::class, $fetchTableDdlsTool);

        $exportCsvTool = app(ExportCsvTool::class);
        $this->assertInstanceOf(ExportCsvTool::class, $exportCsvTool);

        $requestDefinitionTool = app(RequestDefinitionTool::class);
        $this->assertInstanceOf(RequestDefinitionTool::class, $requestDefinitionTool);
    }

    /**
     * Test that all MCP tools extend Laravel\Mcp\Server\Tool
     *
     * Verifies that all tool classes properly extend the base Tool class
     * as required by the laravel/mcp package.
     */
    public function test_all_tools_extend_base_tool_class(): void
    {
        $this->assertTrue(
            is_subclass_of(RunSqlQueryTool::class, Tool::class),
            'RunSqlQueryTool should extend Laravel\Mcp\Server\Tool'
        );

        $this->assertTrue(
            is_subclass_of(RunQueryForCsvExportTool::class, Tool::class),
            'RunQueryForCsvExportTool should extend Laravel\Mcp\Server\Tool'
        );

        $this->assertTrue(
            is_subclass_of(FetchTableDdlsTool::class, Tool::class),
            'FetchTableDdlsTool should extend Laravel\Mcp\Server\Tool'
        );

        $this->assertTrue(
            is_subclass_of(ExportCsvTool::class, Tool::class),
            'ExportCsvTool should extend Laravel\Mcp\Server\Tool'
        );

        $this->assertTrue(
            is_subclass_of(RequestDefinitionTool::class, Tool::class),
            'RequestDefinitionTool should extend Laravel\Mcp\Server\Tool'
        );
    }

    /**
     * Test that all tools have correct names defined
     *
     * Verifies that each tool has the expected name property set.
     */
    public function test_all_tools_have_correct_names(): void
    {
        $runSqlQueryTool = app(RunSqlQueryTool::class);
        $this->assertEquals('run_sql_query', $runSqlQueryTool->name());

        $runQueryForCsvExportTool = app(RunQueryForCsvExportTool::class);
        $this->assertEquals('run_query_for_csv_export', $runQueryForCsvExportTool->name());

        $fetchTableDdlsTool = app(FetchTableDdlsTool::class);
        $this->assertEquals('fetch_table_ddls', $fetchTableDdlsTool->name());

        $exportCsvTool = app(ExportCsvTool::class);
        $this->assertEquals('export_csv', $exportCsvTool->name());

        $requestDefinitionTool = app(RequestDefinitionTool::class);
        $this->assertEquals('request_definition', $requestDefinitionTool->name());
    }

    /**
     * Test that ThreadqlServer has all tools registered
     *
     * Verifies that the MCP server class properly registers all available tools
     * by inspecting the default property value using reflection.
     */
    public function test_server_has_all_tools_registered(): void
    {
        // Use reflection to get the default property value without instantiating
        $server = new \ReflectionClass(ThreadqlServer::class);
        $toolsProperty = $server->getProperty('tools');

        // Get the default value from the class definition
        $defaultProperties = $server->getDefaultProperties();
        $tools = $defaultProperties['tools'] ?? [];

        $expectedTools = [
            RunSqlQueryTool::class,
            ExportCsvTool::class,
            FetchTableDdlsTool::class,
            RunQueryForCsvExportTool::class,
            RequestDefinitionTool::class,
        ];

        foreach ($expectedTools as $expectedTool) {
            $this->assertContains($expectedTool, $tools, "ThreadqlServer should have {$expectedTool} registered");
        }
    }
}
