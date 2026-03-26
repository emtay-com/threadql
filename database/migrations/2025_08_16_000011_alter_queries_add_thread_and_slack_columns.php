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
        Schema::table('queries', function (Blueprint $table) {
            // Add new columns
            $table->foreignId('thread_id')->after('tenant_id')->constrained('threads')->onUpdate('cascade')->onDelete('cascade');
            $table->string('slack_event_id', 64)->nullable()->after('thread_id');
            $table->string('channel_id', 64)->after('slack_event_id');
            $table->string('message_ts', 32)->nullable()->after('channel_id');
            $table->string('status', 32)->default('received')->after('message_ts');
            
            // Add indexes and constraints
            $table->unique('slack_event_id', 'queries_slack_event_id_unique');
            $table->index(['tenant_id', 'thread_id', 'ts'], 'queries_tenant_thread_ts_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropIndex('queries_tenant_thread_ts_index');
            $table->dropUnique('queries_slack_event_id_unique');
            $table->dropForeign(['thread_id']);
            $table->dropColumn(['thread_id', 'slack_event_id', 'channel_id', 'message_ts', 'status']);
        });
    }
};
