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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->string('schema_name');
            $table->string('name');
            $table->unsignedTinyInteger('priority')->default(0); // replaces is_important; 0=low, higher=more important
            $table->bigInteger('row_count')->nullable();
            $table->mediumText('ddl_sql')->nullable(); // full CREATE TABLE + FK statements as text
            $table->timestamps();
            
            // Unique constraint
            $table->unique(['tenant_id', 'schema_name', 'name'], 'uq_tenant_schema_table');
            
            // Index for priority queries
            $table->index(['tenant_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
