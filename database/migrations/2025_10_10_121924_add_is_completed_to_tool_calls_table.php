<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->boolean('is_completed')->default(true)->after('response_payload');
        });

        // Backfill existing rows to true
        DB::table('tool_calls')->update(['is_completed' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropColumn('is_completed');
        });
    }
};
