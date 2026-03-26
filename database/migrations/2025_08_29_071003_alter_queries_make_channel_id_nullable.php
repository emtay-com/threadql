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
        Schema::table('queries', function (Blueprint $table) {
            $table->string('channel_id', 64)->nullable()->change();
            $table->string('user_id', 64)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->string('channel_id', 64)->nullable(false)->change();
            $table->string('user_id', 64)->nullable(false)->change();
        });
    }
};
