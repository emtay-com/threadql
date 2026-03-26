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
        Schema::table('tenants', function (Blueprint $table) {
            // Drop legacy columns
            $table->dropColumn(['api_key', 'api_secret']);

            // Add new Slack columns (using text for encrypted data)
            $table->text('slack_app_id')->nullable();
            $table->text('slack_client_id')->nullable();
            $table->text('slack_bot_token')->nullable();
            $table->text('slack_signing_secret')->nullable();
            $table->text('slack_verification_token')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Drop the new Slack columns
            $table->dropColumn([
                'slack_app_id',
                'slack_client_id',
                'slack_bot_token',
                'slack_signing_secret',
                'slack_verification_token'
            ]);

            // Restore legacy columns
            $table->string('api_key')->nullable();
            $table->string('api_secret')->nullable();
        });
    }
};
