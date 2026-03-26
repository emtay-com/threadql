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
        Schema::create('synonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->string('term'); // synonym term (English)
            $table->foreignId('table_id')->constrained('tables')->onUpdate('cascade')->onDelete('cascade');
            $table->string('column_name')->nullable(); // optional: specific column within the table
            $table->string('scope', 32)->default('global'); // 'global' | 'user' (enforced in code)
            $table->bigInteger('user_id')->nullable(); // when scope='user', references app user id (not FK'd here)
            $table->float('weight')->default(0.5);
            $table->timestamps();
            
            // Indexes
            $table->index(['tenant_id', 'term']);
            $table->index(['table_id', 'column_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('synonyms');
    }
};
