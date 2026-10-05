<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguidores: registo diário por plataforma (Instagram e Página de Facebook).
 *  · Uma linha por empresa, plataforma e dia (data de Lisboa).
 *  · source: manual | api | business_discovery. A leitura automática ganha à manual.
 *  · follows_count e media_count só vêm da leitura automática (null no manual).
 * Na F1 (marcas), company_id passa a apontar para a conta da marca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_follower_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);              // instagram | facebook
            $table->date('snapshot_date');
            $table->unsignedBigInteger('followers_count');
            $table->unsignedBigInteger('follows_count')->nullable();
            $table->unsignedBigInteger('media_count')->nullable();
            $table->string('source', 20);                // manual | api | business_discovery
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'platform', 'snapshot_date'], 'sfs_company_platform_date_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_follower_snapshots');
    }
};
