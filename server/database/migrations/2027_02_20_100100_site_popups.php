<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos (pop-up) nos sites dos clientes, geridos na XPLENDOR: o aviso (texto simples, imagem
 * publicada como cópia no disco público, botão, datas, páginas, atraso, frequência e prioridade)
 * e as contagens diárias anónimas de vistas, cliques e fechos (só números: sem IP, sem cookies,
 * sem identificador de visitante). ADITIVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_popups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->foreignId('image_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('button_label', 60)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('pages');
            $table->unsignedSmallInteger('delay_seconds')->default(3);
            $table->string('frequency', 20)->default('once_per_visitor');
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('site_popup_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_popup_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('closes')->default(0);
            $table->unique(['site_popup_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_popup_daily_counts');
        Schema::dropIfExists('site_popups');
    }
};
