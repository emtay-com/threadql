<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\DataSource;

use App\Models\Datasource;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CreateDataSourceTest extends TestCase
{
    /**
     * Set up the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure JWT secret is set for tests
        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);
    }

    /**
     * Test that unauthenticated requests are rejected.
     */
    public function test_it_requires_authentication(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
            'label' => 'Test DB',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'testdb',
            'username' => 'user',
            'password' => 'pass',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test that a datasource can be created with all fields.
     */
    public function test_it_creates_datasource_with_all_fields(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Production Database',
                'host' => 'db.example.com',
                'port' => 3306,
                'database' => 'production',
                'username' => 'readonly_user',
                'password' => 'secret_password',
                'allowed_schemas' => ['public', 'analytics'],
                'default_limit' => 500,
                'query_timeout_seconds' => 120,
                'timezone' => 'America/New_York',
            ]);

        $response->assertStatus(201);

        // Assert response structure
        $response->assertJsonStructure([
            'data' => [
                'id',
                'tenant_id',
                'label',
                'has_dsn',
                'allowed_schemas',
                'default_limit',
                'query_timeout_seconds',
                'timezone',
                'created_at',
                'updated_at',
            ],
        ]);

        // Assert data content
        $data = $response->json('data');
        $this->assertEquals($tenant->id, $data['tenant_id']);
        $this->assertEquals('Production Database', $data['label']);
        $this->assertTrue($data['has_dsn']);
        $this->assertEquals(['public', 'analytics'], $data['allowed_schemas']);
        $this->assertEquals(500, $data['default_limit']);
        $this->assertEquals(120, $data['query_timeout_seconds']);
        $this->assertEquals('America/New_York', $data['timezone']);

        // Assert DSN is not exposed
        $this->assertArrayNotHasKey('dsn', $data);

        // Assert database record was created
        $this->assertDatabaseHas('datasources', [
            'tenant_id' => $tenant->id,
            'label' => 'Production Database',
            'default_limit' => 500,
            'query_timeout_seconds' => 120,
            'timezone' => 'America/New_York',
        ]);

        // Verify DSN was properly built and stored
        $datasource = Datasource::find($data['id']);
        $this->assertStringContainsString('readonly_user', $datasource->dsn);
        $this->assertStringContainsString('db.example.com', $datasource->dsn);
        $this->assertStringContainsString('production', $datasource->dsn);
    }

    /**
     * Test that a datasource can be created with minimum required fields.
     */
    public function test_it_creates_datasource_with_minimum_fields(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Minimal DB',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'testdb',
                'username' => 'user',
                'password' => 'pass',
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals('Minimal DB', $data['label']);
        $this->assertEquals(200, $data['default_limit']); // Default value
        $this->assertEquals(60, $data['query_timeout_seconds']); // Default value
        $this->assertEquals('UTC', $data['timezone']); // Default value
        $this->assertNull($data['allowed_schemas']);
    }

    /**
     * Test that special characters in password are properly escaped.
     */
    public function test_it_escapes_special_characters_in_password(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $specialPassword = 'p@ss:word/123!#$%';

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Special Password DB',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'testdb',
                'username' => 'user',
                'password' => $specialPassword,
            ]);

        $response->assertStatus(201);

        // Verify DSN was properly built with escaped characters
        $datasource = Datasource::find($response->json('data.id'));
        $this->assertNotNull($datasource->dsn);

        // Parse the DSN and verify password was correctly stored
        $parser = new \App\Infrastructure\Dsn\DsnParser();
        $components = $parser->parse($datasource->dsn);
        $this->assertEquals($specialPassword, $components->password);
    }

    /**
     * Test that special characters in username are properly escaped.
     */
    public function test_it_escapes_special_characters_in_username(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $specialUsername = 'user@domain.com';

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Special Username DB',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'testdb',
                'username' => $specialUsername,
                'password' => 'pass',
            ]);

        $response->assertStatus(201);

        // Verify username was correctly stored
        $datasource = Datasource::find($response->json('data.id'));
        $parser = new \App\Infrastructure\Dsn\DsnParser();
        $components = $parser->parse($datasource->dsn);
        $this->assertEquals($specialUsername, $components->username);
    }

    /**
     * Test validation errors for missing required fields.
     */
    public function test_it_validates_required_fields(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['label', 'host', 'port', 'database', 'username', 'password']);
    }

    /**
     * Test validation for invalid port.
     */
    public function test_it_validates_port_range(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Test DB',
                'host' => 'localhost',
                'port' => 99999, // Invalid port
                'database' => 'testdb',
                'username' => 'user',
                'password' => 'pass',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['port']);
    }

    /**
     * Test validation for invalid timezone.
     */
    public function test_it_validates_timezone(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Test DB',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'testdb',
                'username' => 'user',
                'password' => 'pass',
                'timezone' => 'Invalid/Timezone',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }

    /**
     * Test that 404 is returned for non-existent tenant.
     */
    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/tenants/99999/datasources', [
                'label' => 'Test DB',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'testdb',
                'username' => 'user',
                'password' => 'pass',
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test that a datasource can be created with SSH tunnel fields.
     */
    public function test_it_creates_datasource_with_ssh_fields(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'SSH DB',
                'host' => 'db.internal',
                'port' => 3306,
                'database' => 'app',
                'username' => 'user',
                'password' => 'pass',
                'use_ssh' => true,
                'ssh_host' => 'bastion.example.com',
                'ssh_port' => 22,
                'ssh_username' => 'ec2-user',
                'ssh_private_key' => '-----BEGIN RSA PRIVATE KEY-----',
                'ssh_public_key' => 'ssh-rsa AAAA...',
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertTrue($data['use_ssh']);
        $this->assertEquals('bastion.example.com', $data['ssh_host']);
        $this->assertEquals(22, $data['ssh_port']);
        $this->assertEquals('ec2-user', $data['ssh_username']);
        $this->assertTrue($data['has_ssh_private_key']);
        $this->assertFalse($data['has_ssh_password']);
        $this->assertEquals('ssh-rsa AAAA...', $data['ssh_public_key']);

        // Sensitive fields must not be exposed
        $this->assertArrayNotHasKey('ssh_private_key', $data);
        $this->assertArrayNotHasKey('ssh_password', $data);
    }

    /**
     * Test that ssh_host and ssh_username are required when use_ssh is true.
     */
    public function test_it_requires_ssh_host_and_username_when_use_ssh_is_true(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'SSH DB',
                'host' => 'db.internal',
                'port' => 3306,
                'database' => 'app',
                'username' => 'user',
                'password' => 'pass',
                'use_ssh' => true,
                // ssh_host and ssh_username intentionally omitted
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ssh_host', 'ssh_username']);
    }

    /**
     * Test that SSH fields default to disabled when not provided.
     */
    public function test_it_defaults_to_no_ssh_when_fields_not_provided(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Plain DB',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'mydb',
                'username' => 'user',
                'password' => 'pass',
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertFalse($data['use_ssh']);
        $this->assertNull($data['ssh_host']);
        $this->assertNull($data['ssh_username']);
        $this->assertFalse($data['has_ssh_password']);
        $this->assertFalse($data['has_ssh_private_key']);
    }

    /**
     * Test that allowed_schemas must be an array.
     */
    public function test_it_validates_allowed_schemas_is_array(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/datasources", [
                'label' => 'Test DB',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'testdb',
                'username' => 'user',
                'password' => 'pass',
                'allowed_schemas' => 'not-an-array',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['allowed_schemas']);
    }
}
