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
            $table->dropColumn(['ro_username', 'ro_options_json']);
            $table->string('label')->nullable()->after('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('datasources', function (Blueprint $table) {
            $table->dropColumn('label');
            $table->string('ro_username')->after('dsn');
            $table->json('ro_options_json')->nullable()->after('ro_username');
        });
    }
};