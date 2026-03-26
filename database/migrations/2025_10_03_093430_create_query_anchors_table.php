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
        Schema::create('query_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained('queries')->onDelete('cascade');
            $table->enum('type', ['table', 'pagination_blocks']);
            $table->string('message_ts');
            $table->json('blocks_json')->nullable();
            $table->json('attachments_json')->nullable();
            $table->timestamps();

            $table->index(['query_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_anchors');
    }
};
