<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola do stand (aditiva): as tabelas comuns a qualquer fornecedor da Bússola comum (a
 * restauração mantém as suas) e o interruptor do stand por empresa.
 *  · companies.bussola_stand_enabled: nasce desligado; o root liga-o (bussola:stand-switch).
 *  · compass_signals: os sinais calculados (por fornecedor e empresa), recalculados de noite e
 *    quando o stock, os preços ou os anúncios mudam.
 *  · compass_signal_actions: ignorar (4 semanas), restaurar e "publicação criada"; só inserção.
 *  · compass_excluded_subjects: "Não voltar a sugerir este veículo" (pode voltar a incluir-se).
 *  · compass_texts: as frases "O quê" da IA, por jogada.
 *  · compass_post_subjects: a publicação criada a partir da Bússola e o veículo (para saber
 *    se o veículo teve uma publicação nos últimos 30 dias).
 *  · compass_runs: quando os sinais foram calculados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('bussola_stand_enabled')->default(false);
        });

        Schema::create('compass_signals', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('signal_key', 160);
            $table->string('type', 30);
            $table->string('subject_type', 20)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('vehicle_type', 20)->nullable();
            $table->string('confidence', 10);
            $table->json('numbers')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'company_id', 'signal_key'], 'compass_signals_key_unique');
            $table->index(['company_id', 'provider', 'type']);
        });

        Schema::create('compass_signal_actions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('signal_key', 160);
            $table->string('action', 20); // ignored | restored | post_created
            $table->date('hidden_until')->nullable();
            $table->foreignId('editorial_post_id')->nullable()->constrained('editorial_posts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'provider', 'signal_key']);
        });

        Schema::create('compass_excluded_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('excluded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('excluded_at')->useCurrent();
            $table->timestamp('included_at')->nullable();
            $table->foreignId('included_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'provider', 'subject_type', 'subject_id'], 'compass_excluded_subject_idx');
        });

        Schema::create('compass_texts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('play_key', 160);
            $table->text('text');
            $table->timestamp('generated_at')->nullable();
            $table->unique(['provider', 'company_id', 'play_key'], 'compass_texts_key_unique');
        });

        Schema::create('compass_post_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'subject_type', 'subject_id'], 'compass_post_subject_idx');
        });

        Schema::create('compass_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamp('computed_at')->nullable();
            $table->unique(['provider', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compass_runs');
        Schema::dropIfExists('compass_post_subjects');
        Schema::dropIfExists('compass_texts');
        Schema::dropIfExists('compass_excluded_subjects');
        Schema::dropIfExists('compass_signal_actions');
        Schema::dropIfExists('compass_signals');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('bussola_stand_enabled'));
    }
};
