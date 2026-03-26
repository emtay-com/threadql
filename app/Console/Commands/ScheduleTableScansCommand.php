<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantSettingEnum;
use App\Jobs\TableSchemaCrawlerJob;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to dispatch table schema scans for tenants whose scheduled scan time
 * falls within the current 30-minute window.
 *
 * Schedule times are interpreted in UTC. The cron job runs every 30 minutes
 * (0,30 * * * *) and dispatches scans for tenants whose schedule falls within
 * the window (now - 30 minutes, now].
 */
class ScheduleTableScansCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'schema:schedule-scans';

    /**
     * The console command description.
     */
    protected $description = 'Dispatch table schema scans for tenants due in the current 30-minute window';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $windowStart = $now->copy()
            ->subMinutes(30);

        $dispatched = 0;

        foreach (Tenant::with('datasources')->cursor() as $tenant) {
            $setting = $tenant->getSetting(TenantSettingEnum::TABLE_SCAN_SCHEDULE);
            $scheduleTime = $setting->value;

            if (! $this->isValidScheduleTime($scheduleTime)) {
                Log::warning('Invalid table scan schedule for tenant', [
                    'tenant_id' => $tenant->id,
                    'schedule' => $scheduleTime,
                ]);

                continue;
            }

            if (! $this->isDueInWindow($scheduleTime, $now, $windowStart)) {
                continue;
            }

            $this->info("Dispatching table scan for tenant {$tenant->id} (schedule: {$scheduleTime})");

            foreach ($tenant->datasources as $datasource) {
                TableSchemaCrawlerJob::dispatch($datasource->id);
                $dispatched++;
            }

            Log::info('Dispatched table scan jobs for tenant', [
                'tenant_id' => $tenant->id,
                'schedule' => $scheduleTime,
                'datasource_count' => $tenant->datasources->count(),
            ]);
        }

        $this->info("Dispatched {$dispatched} table scan job(s).");

        return self::SUCCESS;
    }

    /**
     * Check if a schedule time string is valid (HH:MM format, minutes 00 or 30).
     */
    private function isValidScheduleTime(string $time): bool
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return false;
        }

        [$hours, $minutes] = explode(':', $time);
        $hours = (int) $hours;
        $minutes = (int) $minutes;

        return $hours >= 0 && $hours <= 23 && in_array($minutes, [0, 30], true);
    }

    /**
     * Check if the given schedule time falls within the 30-minute window.
     * Uses exclusive start, inclusive end: (windowStart, now].
     */
    private function isDueInWindow(string $scheduleTime, Carbon $now, Carbon $windowStart): bool
    {
        [$hours, $minutes] = explode(':', $scheduleTime);

        $scheduledToday = $now->copy()
            ->startOfDay()
            ->addHours((int) $hours)
            ->addMinutes((int) $minutes);

        return $scheduledToday->greaterThan($windowStart) && $scheduledToday->lessThanOrEqualTo($now);
    }
}
