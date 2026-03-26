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
        Schema::create('tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('query_id')->nullable()->constrained()->onDelete('set null');
            $table->string('tool', 128);
            $table->mediumText('request_payload')->nullable();
            $table->mediumText('response_payload')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'created_at']);
            $table->index('query_id');
            $table->index('tool');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_calls');
    }
};
