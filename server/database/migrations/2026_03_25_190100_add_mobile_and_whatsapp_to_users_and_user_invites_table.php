<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 50)->nullable()->after('birthdate');
            $table->string('whatsapp', 50)->nullable()->after('mobile');
        });

        Schema::table('user_invites', function (Blueprint $table) {
            $table->string('mobile', 50)->nullable()->after('birthdate');
            $table->string('whatsapp', 50)->nullable()->after('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mobile', 'whatsapp']);
        });

        Schema::table('user_invites', function (Blueprint $table) {
            $table->dropColumn(['mobile', 'whatsapp']);
        });
    }
};
