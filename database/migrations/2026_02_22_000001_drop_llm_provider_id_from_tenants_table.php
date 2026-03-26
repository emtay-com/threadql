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
        if (! Schema::hasColumn('tenants', 'llm_provider_id')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('llm_provider_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'llm_provider_id')) {
            return;
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('llm_provider_id')
                ->nullable()
                ->after('name')
                ->constrained('llm_providers')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }
};
