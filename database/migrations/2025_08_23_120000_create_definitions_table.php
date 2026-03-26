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
        Schema::create('definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('user_id', 128);
            $table->unsignedBigInteger('thread_id')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('subject', 255);
            $table->text('definition');
            $table->timestamps();

            // Indexes and constraints
            $table->unique(['tenant_id', 'subject']);
            $table->index(['tenant_id', 'priority']);
            
            // Foreign key constraint
            $table->foreign('thread_id')
                ->references('id')
                ->on('threads')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('definitions');
    }
};

