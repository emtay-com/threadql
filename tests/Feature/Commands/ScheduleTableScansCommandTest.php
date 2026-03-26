<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\TenantSettingEnum;
use App\Jobs\TableSchemaCrawlerJob;
use App\Models\Datasource;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduleTableScansCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_jobs_for_tenants_due_in_current_window(): void
    {
        Carbon::setTestNow(Carbon::createFromTime(6, 35));

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $datasource = Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/testdb',
        ]);

        $setting = $tenant->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $setting->update([
            'value' => '06:30',
        ]);

        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        Queue::assertPushed(TableSchemaCrawlerJob::class, 1);
    }

    #[Test]
    public function it_does_not_dispatch_jobs_for_tenants_outside_window(): void
    {
        Carbon::setTestNow(Carbon::createFromTime(10, 0));

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/testdb',
        ]);

        $setting = $tenant->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $setting->update([
            'value' => '06:30',
        ]);

        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        Queue::assertNotPushed(TableSchemaCrawlerJob::class);
    }

    #[Test]
    public function it_dispatches_jobs_for_multiple_datasources(): void
    {
        Carbon::setTestNow(Carbon::createFromTime(2, 5));

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/db1',
        ]);

        Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/db2',
        ]);

        $setting = $tenant->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $setting->update([
            'value' => '02:00',
        ]);

        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        Queue::assertPushed(TableSchemaCrawlerJob::class, 2);
    }

    #[Test]
    public function it_handles_multiple_tenants_with_different_schedules(): void
    {
        Carbon::setTestNow(Carbon::createFromTime(14, 35));

        $tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
        ]);

        Datasource::create([
            'tenant_id' => $tenantA->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/db1',
        ]);

        $settingA = $tenantA->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $settingA->update([
            'value' => '14:30',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
        ]);

        Datasource::create([
            'tenant_id' => $tenantB->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/db2',
        ]);

        $settingB = $tenantB->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $settingB->update([
            'value' => '08:00',
        ]);

        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        // Only tenant A should be dispatched (14:30 is within window, 08:00 is not)
        Queue::assertPushed(TableSchemaCrawlerJob::class, 1);
    }

    #[Test]
    public function it_skips_tenants_with_invalid_schedule(): void
    {
        Carbon::setTestNow(Carbon::createFromTime(2, 5));

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/testdb',
        ]);

        $setting = $tenant->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $setting->update([
            'value' => 'invalid',
        ]);

        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        Queue::assertNotPushed(TableSchemaCrawlerJob::class);
    }

    #[Test]
    public function it_skips_tenants_with_no_datasources(): void
    {
        Carbon::setTestNow(Carbon::createFromTime(2, 5));

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $setting = $tenant->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $setting->update([
            'value' => '02:00',
        ]);

        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        Queue::assertNotPushed(TableSchemaCrawlerJob::class);
    }

    #[Test]
    public function it_uses_default_schedule_from_config(): void
    {
        // Default is 02:00, test at 02:05 should be within window
        Carbon::setTestNow(Carbon::createFromTime(2, 5));

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/testdb',
        ]);

        // Don't set the setting explicitly - let it use the default from config
        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        Queue::assertPushed(TableSchemaCrawlerJob::class, 1);
    }

    #[Test]
    public function it_handles_schedule_at_exact_window_boundary(): void
    {
        // Schedule at 06:30, current time at 06:30 exactly (should be included)
        Carbon::setTestNow(Carbon::createFromTime(6, 30));

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://user:pass@127.0.0.1:3306/testdb',
        ]);

        $setting = $tenant->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
        $setting->update([
            'value' => '06:30',
        ]);

        $this->artisan('schema:schedule-scans')
            ->assertExitCode(0);

        Queue::assertPushed(TableSchemaCrawlerJob::class, 1);
    }

    #[Test]
    public function it_validates_schedule_time_format(): void
    {
        $command = new \App\Console\Commands\ScheduleTableScansCommand;
        $method = new \ReflectionMethod($command, 'isValidScheduleTime');

        $this->assertTrue($method->invoke($command, '00:00'));
        $this->assertTrue($method->invoke($command, '23:30'));
        $this->assertTrue($method->invoke($command, '12:00'));
        $this->assertTrue($method->invoke($command, '02:00'));

        $this->assertFalse($method->invoke($command, 'invalid'));
        $this->assertFalse($method->invoke($command, '24:00'));
        $this->assertFalse($method->invoke($command, '12:15'));
        $this->assertFalse($method->invoke($command, '12:45'));
        $this->assertFalse($method->invoke($command, '1:00'));
        $this->assertFalse($method->invoke($command, ''));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
