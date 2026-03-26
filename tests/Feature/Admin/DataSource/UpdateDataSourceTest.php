<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\DataSource;

use App\Infrastructure\Dsn\DsnParser;
use App\Models\Datasource;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateDataSourceTest extends TestCase
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
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
            'label' => 'Updated Label',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test that label can be updated without changing DSN.
     */
    public function test_it_updates_label_without_changing_dsn(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
            'dsn' => 'mysql://user:pass@localhost:3306/mydb',
        ]);

        $originalDsn = $datasource->dsn;

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
            ]);

        $response->assertStatus(200);

        // Assert label was updated
        $this->assertEquals('Updated Label', $response->json('data.label'));

        // Assert DSN was not changed
        $datasource->refresh();
        $this->assertEquals($originalDsn, $datasource->dsn);
    }

    /**
     * Test that DSN is updated when all DSN parts are provided.
     */
    public function test_it_updates_dsn_when_all_parts_provided(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
            'dsn' => 'mysql://olduser:oldpass@oldhost:3306/olddb',
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
                'host' => 'newhost.example.com',
                'port' => 3307,
                'database' => 'newdb',
                'username' => 'newuser',
                'password' => 'newpass',
            ]);

        $response->assertStatus(200);

        // Assert DSN was updated
        $datasource->refresh();
        $parser = new DsnParser();
        $components = $parser->parse($datasource->dsn);

        $this->assertEquals('newhost.example.com', $components->host);
        $this->assertEquals(3307, $components->port);
        $this->assertEquals('newdb', $components->database);
        $this->assertEquals('newuser', $components->username);
        $this->assertEquals('newpass', $components->password);
    }

    /**
     * Test that DSN is not updated when only some parts are provided.
     */
    public function test_it_does_not_update_dsn_when_partial_parts_provided(): void
    {
        $tenant = Tenant::factory()->create();
        $originalDsn = 'mysql://user:pass@localhost:3306/mydb';
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
            'dsn' => $originalDsn,
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Only provide host and port, missing database and username
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
                'host' => 'newhost.example.com',
                'port' => 3307,
            ]);

        $response->assertStatus(200);

        // Assert DSN was NOT updated
        $datasource->refresh();
        $this->assertEquals($originalDsn, $datasource->dsn);
    }

    /**
     * Test that other fields can be updated.
     */
    public function test_it_updates_other_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
            'default_limit' => 100,
            'query_timeout_seconds' => 30,
            'timezone' => 'UTC',
            'allowed_schemas_json' => ['schema1'],
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
                'default_limit' => 500,
                'query_timeout_seconds' => 120,
                'timezone' => 'America/New_York',
                'allowed_schemas' => ['public', 'analytics'],
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Updated Label', $data['label']);
        $this->assertEquals(500, $data['default_limit']);
        $this->assertEquals(120, $data['query_timeout_seconds']);
        $this->assertEquals('America/New_York', $data['timezone']);
        $this->assertEquals(['public', 'analytics'], $data['allowed_schemas']);
    }

    /**
     * Test that allowed_schemas can be set to null.
     */
    public function test_it_can_clear_allowed_schemas(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
            'allowed_schemas_json' => ['schema1', 'schema2'],
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
                'allowed_schemas' => null,
            ]);

        $response->assertStatus(200);

        $datasource->refresh();
        $this->assertNull($datasource->allowed_schemas_json);
    }

    /**
     * Test that special characters in password are properly escaped.
     */
    public function test_it_escapes_special_characters_in_new_password(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
            'dsn' => 'mysql://user:pass@localhost:3306/mydb',
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $specialPassword = 'p@ss:word/123!#$%';

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'mydb',
                'username' => 'user',
                'password' => $specialPassword,
            ]);

        $response->assertStatus(200);

        // Verify password was correctly stored
        $datasource->refresh();
        $parser = new DsnParser();
        $components = $parser->parse($datasource->dsn);
        $this->assertEquals($specialPassword, $components->password);
    }

    /**
     * Test that label is required.
     */
    public function test_it_requires_label(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['label']);
    }

    /**
     * Test that 404 is returned for non-existent datasource.
     */
    public function test_it_returns_404_for_nonexistent_datasource(): void
    {
        $tenant = Tenant::factory()->create();

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/99999", [
                'label' => 'Updated Label',
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test that 404 is returned for datasource belonging to different tenant.
     */
    public function test_it_returns_404_for_datasource_of_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        // Try to update tenant2's datasource via tenant1's route
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant1->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test that DSN can be updated with empty password.
     */
    public function test_it_allows_empty_password_when_updating_dsn(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
            'dsn' => 'mysql://user:pass@localhost:3306/mydb',
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'mydb',
                'username' => 'user',
                'password' => '',
            ]);

        $response->assertStatus(200);

        // Verify DSN was updated without password
        $datasource->refresh();
        $parser = new DsnParser();
        $components = $parser->parse($datasource->dsn);
        $this->assertEquals('user', $components->username);
        $this->assertEmpty($components->password);
    }

    /**
     * Test that validation works for invalid timezone.
     */
    public function test_it_validates_timezone(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Generate token for master admin
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'Updated Label',
                'timezone' => 'Invalid/Timezone',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }

    /**
     * Test that SSH fields can be enabled on an existing datasource.
     */
    public function test_it_enables_ssh_tunnel_on_existing_datasource(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'Original Label',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'SSH Enabled DB',
                'use_ssh' => true,
                'ssh_host' => 'bastion.example.com',
                'ssh_port' => 22,
                'ssh_username' => 'deploy',
                'ssh_private_key' => '-----BEGIN RSA PRIVATE KEY-----',
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertTrue($data['use_ssh']);
        $this->assertEquals('bastion.example.com', $data['ssh_host']);
        $this->assertEquals(22, $data['ssh_port']);
        $this->assertEquals('deploy', $data['ssh_username']);
        $this->assertTrue($data['has_ssh_private_key']);
        $this->assertFalse($data['has_ssh_password']);
    }

    /**
     * Test that SSH fields can be disabled by setting use_ssh to false.
     */
    public function test_it_can_disable_ssh_tunnel(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'label' => 'SSH DB',
            'use_ssh' => true,
            'ssh_host' => 'bastion.example.com',
            'ssh_username' => 'deploy',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/datasources/{$datasource->id}", [
                'label' => 'SSH DB',
                'use_ssh' => false,
            ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.use_ssh'));
    }
}
