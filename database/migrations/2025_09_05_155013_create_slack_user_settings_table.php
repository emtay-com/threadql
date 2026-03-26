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
        Schema::create('slack_user_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('slack_user_id');
            $table->string('key', 64);
            $table->string('value', 16);
            $table->timestamps();

            $table->foreign('slack_user_id')->references('id')->on('slack_users')->onDelete('cascade');
            $table->unique(['slack_user_id', 'key']);
            $table->index('key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_user_settings');
    }
};
