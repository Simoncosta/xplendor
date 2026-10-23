<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola 1 (teto de custo do Radar): a relevância do Radar é por ramo, não por empresa, e passa a
 * ficar também em ai_requests, sem empresa. O índice por função e data serve a soma do gasto do dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->index(['mode', 'created_at'], 'ai_requests_mode_created_idx');
        });
    }

    public function down(): void
    {
        // O company_id fica opcional: pode haver linhas da relevância sem empresa.
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropIndex('ai_requests_mode_created_idx');
        });
    }
};
