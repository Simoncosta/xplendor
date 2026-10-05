<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamentos com link público: aprovação total ou parcial, aberturas e arranque.
 *
 *  · quote_lines: linhas OPCIONAIS (o cliente pode desmarcar) e linhas do PACOTE (se
 *    alguma sair, o desconto de pacote deixa de se aplicar).
 *  · quotes: totais aceites (o dashboard conta só as linhas aceites), contadores de
 *    abertura, último aviso de abertura (máximo um a cada 6 horas), pedido de
 *    alterações e o ticket de arranque.
 *  · quote_public_links: um link por versão enviada; o token só em hash (pesquisa) e
 *    cifrado (para a equipa o copiar de novo).
 *  · quote_opens: aberturas reais, sem IP (identificador aleatório do browser em hash).
 *  · quote_responses: aceitação (uma por versão), recusa e pedido de alterações.
 *  · service_catalog_items.onboarding_checklist e support_ticket_tasks: a lista de
 *    arranque de cada serviço, copiada para o ticket de arranque.
 */
return new class extends Migration
{
    private const SEED_CHECKLISTS = [
        'Social Media' => ['Preencher o perfil da marca', 'Pedir o acesso às redes sociais', 'Preparar o primeiro calendário editorial'],
        'Tráfego Pago' => ['Pedir o acesso à conta de anúncios', 'Instalar e verificar o pixel', 'Acordar o orçamento mensal de anúncios'],
        'Website'      => ['Confirmar o domínio', 'Confirmar o alojamento', 'Receber os conteúdos e as fotos'],
    ];

    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->boolean('is_optional')->default(false)->after('line_total');
            $table->boolean('in_package')->default(false)->after('is_optional');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('accepted_total_monthly', 10, 2)->nullable()->after('total_one_off');
            $table->decimal('accepted_total_one_off', 10, 2)->nullable()->after('accepted_total_monthly');
            $table->unsignedInteger('open_count')->default(0)->after('expired_at');
            $table->timestamp('first_opened_at')->nullable()->after('open_count');
            $table->timestamp('last_opened_at')->nullable()->after('first_opened_at');
            $table->timestamp('last_open_alert_at')->nullable()->after('last_opened_at');
            $table->timestamp('changes_requested_at')->nullable()->after('last_open_alert_at');
            $table->unsignedBigInteger('onboarding_ticket_id')->nullable()->after('changes_requested_at');
        });

        Schema::table('service_catalog_items', function (Blueprint $table) {
            $table->json('onboarding_checklist')->nullable()->after('sort');
        });
        foreach (self::SEED_CHECKLISTS as $name => $tasks) {
            DB::table('service_catalog_items')->where('name', $name)->whereNull('onboarding_checklist')
                ->update(['onboarding_checklist' => json_encode($tasks, JSON_UNESCAPED_UNICODE)]);
        }

        Schema::create('quote_public_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_public_link_id')->constrained()->cascadeOnDelete();
            $table->char('visitor_hash', 64);
            $table->string('device', 10); // mobile | desktop
            // useCurrent: no MariaDB uma segunda coluna timestamp obrigatória precisa de valor por omissão.
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();

            $table->index(['quote_public_link_id', 'visitor_hash', 'last_seen_at'], 'quote_opens_link_visitor_seen_idx');
            $table->index(['quote_id', 'opened_at'], 'quote_opens_quote_opened_idx');
        });

        Schema::create('quote_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_version_id')->constrained()->cascadeOnDelete();
            // Só preenchido na aceitação: garante uma única aceitação por versão.
            $table->unsignedBigInteger('accepted_version_id')->nullable()->unique();
            $table->string('type', 20); // accepted | refused | changes_requested
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('terms_accepted')->default(false);
            $table->text('message')->nullable();
            $table->json('accepted_line_keys')->nullable();
            $table->json('selection')->nullable();
            $table->boolean('after_changes_request')->default(false);
            $table->string('device', 10)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['quote_id', 'created_at'], 'quote_responses_quote_created_idx');
        });

        Schema::create('support_ticket_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('group_label')->nullable(); // o serviço a que a tarefa pertence
            $table->string('title');
            $table->unsignedBigInteger('catalog_item_id')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['support_ticket_id', 'position'], 'support_ticket_tasks_ticket_pos_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_tasks');
        Schema::dropIfExists('quote_responses');
        Schema::dropIfExists('quote_opens');
        Schema::dropIfExists('quote_public_links');
        Schema::table('service_catalog_items', fn (Blueprint $table) => $table->dropColumn('onboarding_checklist'));
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn([
            'accepted_total_monthly', 'accepted_total_one_off', 'open_count', 'first_opened_at', 'last_opened_at',
            'last_open_alert_at', 'changes_requested_at', 'onboarding_ticket_id',
        ]));
        Schema::table('quote_lines', fn (Blueprint $table) => $table->dropColumn(['is_optional', 'in_package']));
    }
};
