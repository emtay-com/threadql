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
        Schema::table('datasources', function (Blueprint $table) {
            $table->boolean('use_ssh')->default(false)->after('timezone');
            $table->string('ssh_host')->nullable()->after('use_ssh');
            $table->unsignedSmallInteger('ssh_port')->default(22)->nullable()->after('ssh_host');
            $table->string('ssh_username')->nullable()->after('ssh_port');
            $table->text('ssh_password')->nullable()->after('ssh_username');
            $table->text('ssh_private_key')->nullable()->after('ssh_password');
            $table->text('ssh_public_key')->nullable()->after('ssh_private_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('datasources', function (Blueprint $table) {
            $table->dropColumn([
                'use_ssh',
                'ssh_host',
                'ssh_port',
                'ssh_username',
                'ssh_password',
                'ssh_private_key',
                'ssh_public_key',
            ]);
        });
    }
};
