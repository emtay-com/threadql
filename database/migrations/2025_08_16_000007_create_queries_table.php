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
        Schema::create('queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->string('user_id', 128);
            $table->dateTime('ts'); // timestamp
            $table->text('raw_text');
            $table->json('plan_json')->nullable();
            $table->mediumText('sql_text')->nullable();
            $table->json('result_meta_json')->nullable(); // {row_count, cols, truncated}
            $table->integer('latency_ms')->nullable();
            $table->timestamps();
            
            // Index
            $table->index(['tenant_id', 'ts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queries');
    }
};
