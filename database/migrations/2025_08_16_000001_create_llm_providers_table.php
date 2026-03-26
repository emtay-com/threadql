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
        Schema::create('llm_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // human-friendly name ("Company Default", "Local Ollama", etc.)
            $table->string('adapter', 64); // e.g. 'ollama', 'openai', 'anthropic' (validated in code)
            $table->string('url', 2048)->nullable(); // base URL or host for the provider
            $table->string('model_name'); // concrete model id/name
            $table->text('api_key')->nullable(); // store securely (app-level encryption recommended)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_providers');
    }
};
