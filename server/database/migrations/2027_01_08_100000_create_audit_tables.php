<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 2 (Auditoria, motor comum da plataforma):
 *  · audit_runs: cada corrida (empresa, área e ecrã de origem, quem pediu, estado, passos por regra
 *    com contagens, novos e resolvidos).
 *  · audit_findings: os achados (regra, gravidade, entidade, evidência, porquê, sugestão, o que está
 *    em jogo e o peso para o Pareto, o link "Corrigir", a impressão digital que evita duplicados entre
 *    corridas, e o estado).
 *  · audit_finding_refs: as entidades a que cada achado se refere (artigo, fornecedor, documento,
 *    família...), para a coluna "Auditoria" das listas.
 *  · audit_finding_events: o histórico das mudanças de estado.
 *  · article_groups / article_group_members: o "artigo principal" de um grupo de prováveis
 *    duplicados (só na XPLENDOR; não escreve no PingWin).
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_runs')) {
            Schema::create('audit_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('area', 32);                        // restauracao | automovel | tracking
                $table->string('origin', 32);                      // central | artigos | familias | faturas | fornecedores | fichas | noturna | diaria
                $table->string('scope_key', 80);                   // área:origem (uma corrida de cada vez por empresa e âmbito)
                $table->json('rules')->nullable();                 // os códigos das regras desta corrida
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 16)->default('queued');   // queued | running | done | failed
                $table->json('steps')->nullable();                 // [{rule, title, status, found, new, resolved, ms, error}]
                $table->unsignedInteger('new_count')->default(0);
                $table->unsignedInteger('resolved_count')->default(0);
                $table->unsignedInteger('open_count')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'scope_key', 'status']);
                $table->index(['company_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('audit_findings')) {
            Schema::create('audit_findings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('rule', 64);
                $table->string('area', 32);
                $table->string('severity', 8);                     // alta | media | baixa | info
                $table->string('entity_type', 40);
                $table->string('entity_id', 64);
                $table->string('entity_label', 255)->nullable();
                $table->string('title', 255);
                $table->json('evidence')->nullable();
                $table->string('evidence_signature', 40)->nullable(); // muda quando a evidência muda (reabre um Ignorado)
                $table->text('why')->nullable();
                $table->text('suggestion')->nullable();
                $table->bigInteger('impact_cents')->nullable();    // o que está em jogo, quando se pode calcular
                $table->bigInteger('weight_cents')->nullable();    // o peso para o Pareto (compras ou vendas ligadas)
                $table->string('fix_url', 500)->nullable();
                $table->string('fix_label', 80)->nullable();
                $table->json('fix_action')->nullable();            // uma ação no ecrã em vez de um link (ex.: confirmar o fator)
                $table->string('fingerprint', 40);                 // regra + entidade + chave da evidência
                $table->string('state', 16)->default('aberto');    // aberto | aceite | ignorado | corrigido | resolvido
                $table->foreignId('state_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('state_at')->nullable();
                $table->text('state_reason')->nullable();
                $table->string('note', 255)->nullable();           // ex.: "ainda aparece"
                $table->foreignId('last_run_id')->nullable()->constrained('audit_runs')->nullOnDelete();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'fingerprint']);
                $table->index(['company_id', 'area', 'state']);
                $table->index(['company_id', 'rule', 'state']);
            });
        }

        if (! Schema::hasTable('audit_finding_refs')) {
            Schema::create('audit_finding_refs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('finding_id')->constrained('audit_findings')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('entity_type', 40);
                $table->string('entity_id', 64);

                $table->unique(['finding_id', 'entity_type', 'entity_id']);
                $table->index(['company_id', 'entity_type', 'entity_id']);
            });
        }

        if (! Schema::hasTable('audit_finding_events')) {
            Schema::create('audit_finding_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('finding_id')->constrained('audit_findings')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('from_state', 16)->nullable();
                $table->string('to_state', 16);
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('run_id')->nullable()->constrained('audit_runs')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['finding_id', 'id']);
            });
        }

        if (! Schema::hasTable('article_groups')) {
            Schema::create('article_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('key', 160);                        // o nome normalizado do grupo
                $table->string('label', 255)->nullable();
                $table->string('main_product_pingwin_id', 32)->nullable();
                $table->foreignId('chosen_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('chosen_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'key']);
            });
        }

        if (! Schema::hasTable('article_group_members')) {
            Schema::create('article_group_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('article_groups')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('product_pingwin_id', 32);
                $table->timestamps();

                $table->unique(['group_id', 'product_pingwin_id']);
                $table->index(['company_id', 'product_pingwin_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_group_members');
        Schema::dropIfExists('article_groups');
        Schema::dropIfExists('audit_finding_events');
        Schema::dropIfExists('audit_finding_refs');
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('audit_runs');
    }
};
