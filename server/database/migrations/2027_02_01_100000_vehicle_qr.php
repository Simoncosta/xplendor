<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha da viatura (A4) com QR Code: o modelo do endereço da viatura no site de cada empresa
 * (companies.vehicle_url_template, com {id}), o código curto e aleatório de cada viatura
 * (cars.qr_code, criado quando é preciso; nunca o id) e a contagem das leituras (car_qr_scans:
 * empresa, viatura, data e hora; sem IP, sem agente do browser, sem cookies). Só acrescenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('vehicle_url_template', 255)->nullable();
        });
        Schema::table('cars', function (Blueprint $table) {
            $table->string('qr_code', 16)->nullable()->unique();
        });
        Schema::create('car_qr_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('car_id')->constrained('cars')->cascadeOnDelete();
            $table->dateTime('scanned_at');
            $table->index(['car_id', 'scanned_at']);
            $table->index(['company_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_qr_scans');
        Schema::table('cars', fn (Blueprint $t) => $t->dropUnique(['qr_code']));
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn('qr_code'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('vehicle_url_template'));
    }
};
