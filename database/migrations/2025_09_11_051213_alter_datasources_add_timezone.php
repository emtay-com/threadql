<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('datasources', function (Blueprint $table) {
            if (!Schema::hasColumn('datasources', 'timezone')) {
                $table->string('timezone', 64)->default('UTC')->after('query_timeout_seconds');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('datasources', function (Blueprint $table) {
            if (Schema::hasColumn('datasources', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
