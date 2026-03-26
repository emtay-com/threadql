<?php

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
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->string('function_call_id', 128)->nullable()->after('response_payload');
            $table->string('result_id', 128)->nullable()->after('function_call_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tool_calls', function (Blueprint $table) {
            $table->dropColumn(['function_call_id', 'result_id']);
        });
    }
};
