<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Auditoria v2 (Guião de unidades). Aditiva e idempotente:
 *  · product_unit_profiles: a fonte de verdade das unidades de cada artigo na XPLENDOR (base
 *    proposta e confirmada, tipo de artigo, unidade de contagem, estado, confiança, evidências);
 *  · product_unit_profile_events: o histórico das decisões (quem, quando, antes, depois, porquê);
 *  · product_unit_conversions ganha profile_id e purpose (compra | contagem);
 *  · pingwin_unit_writes: cada escrita das unidades de um artigo no PingWin (uma de cada vez, com
 *    releitura);
 *  · audit_findings ganha a atribuição ("Enviar ao administrador") e os euros resolvidos (o € em
 *    jogo no momento, quem resolveu e quando).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_unit_profiles')) {
            Schema::create('product_unit_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                // Unidade base: a família (kg | L | un) proposta e a confirmada por uma pessoa.
                $table->string('base_proposed', 8)->nullable();
                $table->string('base_confirmed', 8)->nullable();
                // peso | volume | contavel | embalado_peso | embalado_inteiro
                $table->string('article_type', 20)->nullable();
                $table->string('count_unit', 80)->nullable();
                $table->decimal('count_factor', 18, 6)->nullable();
                // por_identificar | sugerido | confirmado | aplicado_no_pingwin | nao_se_aplica
                $table->string('state', 24)->default('sugerido');
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->string('reason', 500)->nullable();
                $table->decimal('confidence', 4, 3)->default(0);
                $table->json('evidence')->nullable();
                $table->json('proposal')->nullable();
                $table->json('answers')->nullable();
                // "Dar mais informação": texto livre e foto opcional (ficheiro privado).
                $table->text('info_text')->nullable();
                $table->string('info_photo_path', 500)->nullable();
                // "Perguntar à IA": a resposta validada, guardada (cache); sempre "a confirmar".
                $table->json('ai_suggestion')->nullable();
                $table->timestamp('ai_suggested_at')->nullable();
                $table->string('ai_signature', 40)->nullable();
                $table->timestamp('skipped_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'product_pingwin_id']);
                $table->index(['company_id', 'state']);
            });
        }

        if (! Schema::hasTable('product_unit_profile_events')) {
            Schema::create('product_unit_profile_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('profile_id')->constrained('product_unit_profiles')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                // confirmar | ajustar | saltar | nao_se_aplica | mais_info | ia | enviar_admin | aplicado | reavaliado
                $table->string('action', 24);
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['profile_id', 'created_at']);
            });
        }

        if (Schema::hasTable('product_unit_conversions')) {
            Schema::table('product_unit_conversions', function (Blueprint $table) {
                if (! Schema::hasColumn('product_unit_conversions', 'profile_id')) {
                    $table->unsignedBigInteger('profile_id')->nullable()->after('company_id');
                    $table->index('profile_id');
                }
                if (! Schema::hasColumn('product_unit_conversions', 'purpose')) {
                    $table->string('purpose', 12)->nullable()->after('supplier_nif'); // compra | contagem
                }
            });
        }

        if (! Schema::hasTable('pingwin_unit_writes')) {
            Schema::create('pingwin_unit_writes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->string('product_code', 32)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('profile_id')->nullable();
                // create | edit_factor | void | set_purchase
                $table->string('op', 16);
                $table->json('data')->nullable();
                $table->string('unit_pingwin_id', 32)->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->json('progress')->nullable();
                // queued | running | ok | mismatch | refused | failed
                $table->string('status', 12)->default('queued');
                $table->string('note', 255)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'product_pingwin_id']);
            });
        }

        if (Schema::hasTable('audit_findings')) {
            Schema::table('audit_findings', function (Blueprint $table) {
                if (! Schema::hasColumn('audit_findings', 'assigned_to')) {
                    // "Enviar ao administrador": o perfil a quem foi atribuído (administrador).
                    $table->string('assigned_to', 32)->nullable()->after('note');
                    $table->foreignId('assigned_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
                    $table->timestamp('assigned_at')->nullable()->after('assigned_by');
                }
                if (! Schema::hasColumn('audit_findings', 'resolved_cents')) {
                    // Euros resolvidos: o € em jogo no momento de resolver, quem e quando.
                    $table->bigInteger('resolved_cents')->nullable()->after('resolved_at');
                    $table->foreignId('resolved_by')->nullable()->after('resolved_cents')->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_findings')) {
            Schema::table('audit_findings', function (Blueprint $table) {
                foreach (['assigned_by', 'resolved_by'] as $fk) {
                    if (Schema::hasColumn('audit_findings', $fk)) {
                        $table->dropConstrainedForeignId($fk);
                    }
                }
                foreach (['assigned_to', 'assigned_at', 'resolved_cents'] as $c) {
                    if (Schema::hasColumn('audit_findings', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('product_unit_conversions')) {
            Schema::table('product_unit_conversions', function (Blueprint $table) {
                foreach (['profile_id', 'purpose'] as $c) {
                    if (Schema::hasColumn('product_unit_conversions', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        Schema::dropIfExists('pingwin_unit_writes');
        Schema::dropIfExists('product_unit_profile_events');
        Schema::dropIfExists('product_unit_profiles');
    }
};
