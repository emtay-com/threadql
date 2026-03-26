<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('synonyms');
        Schema::dropIfExists('user_profiles');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('user_profiles', function ($table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('user_id', 128);
            $table->text('role_blurb')->nullable();
            $table->json('prefs_json')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('synonyms', function ($table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('term');
            $table->foreignId('table_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('column_name')->nullable();
            $table->string('scope', 32)->default('global');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->float('weight')->default(0.5);
            $table->timestamps();
            $table->index(['tenant_id', 'term']);
            $table->index(['table_id', 'column_name']);
        });
    }
};
