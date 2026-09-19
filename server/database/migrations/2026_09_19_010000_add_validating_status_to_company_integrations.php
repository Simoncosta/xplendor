<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Estado "validating" (a validar) para as integrações. O PingWin passou
 * a validar por FILA: grava-se primeiro em "validating" e o job atualiza para
 * "active"/"error". Aditiva: só acrescenta o valor ao enum de status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->enum('status', ['active', 'expired', 'revoked', 'error', 'validating'])
                ->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->enum('status', ['active', 'expired', 'revoked', 'error'])
                ->default('active')->change();
        });
    }
};
