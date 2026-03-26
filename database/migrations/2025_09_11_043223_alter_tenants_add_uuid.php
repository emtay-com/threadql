<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if uuid column exists and has proper constraints
        $hasUuidColumn = Schema::hasColumn('tenants', 'uuid');
        $hasUniqueIndex = false;

        if ($hasUuidColumn) {
            $indexes = DB::select("SHOW INDEX FROM tenants WHERE Column_name = 'uuid'");
            foreach ($indexes as $index) {
                if ($index->Non_unique == 0) {
                    $hasUniqueIndex = true;
                    break;
                }
            }
        }

        // Only add column if it doesn't exist
        if (!$hasUuidColumn) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->char('uuid', 36)->nullable();
            });
        }

        // Backfill existing records with UUIDs if needed
        $nullCount = DB::table('tenants')->whereNull('uuid')->count();
        if ($nullCount > 0) {
            DB::table('tenants')
                ->whereNull('uuid')
                ->update(['uuid' => Str::uuid()]);
        }

        // Only modify column if it doesn't have proper constraints
        if (!$hasUniqueIndex) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->char('uuid', 36)->nullable(false)->unique()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'uuid')) {
                $table->dropIndex(['uuid']);
                $table->dropColumn('uuid');
            }
        });
    }
};
