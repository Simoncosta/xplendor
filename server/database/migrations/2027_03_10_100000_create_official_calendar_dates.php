<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola 6: as datas lidas numa fonte oficial por máquina (hoje, o calendário de difusão do INE).
 * Cada linha é uma ocorrência de uma entrada do OfficialDates (source_key), com o dia em que foi lida.
 * Tabela nova (só aditiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_calendar_dates', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 80);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('title')->nullable();
            $table->dateTime('fetched_at');
            $table->timestamps();

            $table->unique(['source_key', 'starts_on'], 'official_cal_dates_key_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_calendar_dates');
    }
};
