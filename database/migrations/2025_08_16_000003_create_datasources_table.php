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
        Schema::create('datasources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->text('dsn'); // per-tenant, read-only DSN & guardrails
            $table->string('ro_username');
            $table->json('ro_options_json')->nullable();
            $table->json('allowed_schemas_json')->nullable();
            $table->integer('default_limit')->default(200);
            $table->integer('query_timeout_seconds')->default(60);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('datasources');
    }
};
