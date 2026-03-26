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
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->foreignId('query_id')->constrained('queries')->onUpdate('cascade')->onDelete('cascade');
            $table->string('user_id', 128);
            $table->integer('score'); // 1..10
            $table->string('category', 32)->nullable(); // 'metric'|'filters'|'window'|'grouping'|'other' (enforced in code)
            $table->text('note')->nullable();
            $table->timestamps();
            
            // Index
            $table->index(['tenant_id', 'query_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
