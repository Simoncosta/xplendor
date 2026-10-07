<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1c: link de configuração do cliente (sem conta na XPLENDOR).
 *  · company_setup_links: um link por empresa de cada vez; o token só em hash (pesquisa) e
 *    cifrado (para a equipa o copiar de novo); os passos escolhidos e o estado de cada um.
 *  · company_setup_link_opens: aberturas reais, sem IP (PublicLinkOpens).
 *  · setup_link_id nas ligações (redes sociais e integrações): ligadas pelo cliente através do
 *    link, sem utilizador (connected_by_user_id vazio).
 *  · company_connection_events: histórico de ligações e desligamentos (data, origem, detalhe).
 *  · support_ticket_tasks.task_key e chaves fixas na lista de arranque do catálogo: as tarefas
 *    de acesso marcam-se sozinhas pela chave. A atribuição inicial das chaves é feita uma única
 *    vez aqui, pelo texto inicial exato das linhas do catálogo e das tarefas abertas.
 */
return new class extends Migration
{
    /** Texto inicial exato => chave (só para esta migração). */
    private const SEED_KEYS = [
        'Pedir o acesso às redes sociais' => 'social_access',
        'Pedir o acesso à conta de anúncios' => 'meta_ads_access',
    ];

    private const GA4_TASK = 'Pedir o acesso ao Google Analytics';

    /** Serviços do catálogo (nome inicial exato) que passam a ter a tarefa do GA4. */
    private const GA4_SERVICES = ['Website', 'Tráfego Pago'];

    public function up(): void
    {
        Schema::create('company_setup_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_management_id')->nullable()->constrained('company_managements')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->json('steps');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 20)->nullable(); // manual | replaced
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->dateTime('first_opened_at')->nullable();
            $table->dateTime('last_opened_at')->nullable();
            $table->dateTime('last_open_alert_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at'], 'csl_company_created_idx');
        });

        Schema::create('company_setup_link_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setup_link_id')->constrained('company_setup_links')->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            $table->string('device', 10)->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('last_seen_at');
            $table->index(['company_setup_link_id', 'visitor_hash', 'last_seen_at'], 'cslo_visit_idx');
        });

        Schema::table('social_connections', function (Blueprint $table) {
            $table->foreignId('setup_link_id')->nullable()->after('connected_by_user_id')->constrained('company_setup_links')->nullOnDelete();
        });
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->foreignId('setup_link_id')->nullable()->after('connected_by_user_id')->constrained('company_setup_links')->nullOnDelete();
        });

        Schema::create('company_connection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('kind', 20);   // social | meta_ads | ga4
            $table->string('action', 20); // connected | account_changed | disconnected
            $table->foreignId('setup_link_id')->nullable()->constrained('company_setup_links')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('detail')->nullable();
            $table->dateTime('created_at');
            $table->index(['company_id', 'created_at'], 'cce_company_created_idx');
        });

        Schema::table('support_ticket_tasks', function (Blueprint $table) {
            $table->string('task_key', 40)->nullable()->after('catalog_item_id');
            $table->index('task_key');
        });

        // Lista de arranque: as linhas passam a {title, key}; chave pelo texto inicial exato.
        foreach (DB::table('service_catalog_items')->get(['id', 'name', 'onboarding_checklist']) as $item) {
            $lines = array_values(array_filter(array_map(function ($line) {
                $title = is_array($line) ? (string) ($line['title'] ?? '') : (string) $line;
                $title = trim($title);

                return $title === '' ? null : ['title' => $title, 'key' => is_array($line) ? ($line['key'] ?? null) : (self::SEED_KEYS[$title] ?? null)];
            }, (array) json_decode((string) $item->onboarding_checklist, true))));
            if (in_array($item->name, self::GA4_SERVICES, true) && ! in_array('ga4_access', array_column($lines, 'key'), true)) {
                $lines[] = ['title' => self::GA4_TASK, 'key' => 'ga4_access'];
            }
            DB::table('service_catalog_items')->where('id', $item->id)->update(['onboarding_checklist' => $lines === [] ? null : json_encode($lines, JSON_UNESCAPED_UNICODE)]);
        }

        // Tarefas abertas já criadas com o texto inicial exato.
        foreach (self::SEED_KEYS as $title => $key) {
            DB::table('support_ticket_tasks')->whereNull('done_at')->whereNull('task_key')->where('title', $title)->update(['task_key' => $key]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('service_catalog_items')->get(['id', 'onboarding_checklist']) as $item) {
            $titles = array_values(array_filter(array_map(fn ($l) => is_array($l) ? ($l['title'] ?? null) : $l, (array) json_decode((string) $item->onboarding_checklist, true))));
            DB::table('service_catalog_items')->where('id', $item->id)->update(['onboarding_checklist' => $titles === [] ? null : json_encode($titles, JSON_UNESCAPED_UNICODE)]);
        }
        Schema::table('support_ticket_tasks', function (Blueprint $table) {
            $table->dropIndex(['task_key']);
            $table->dropColumn('task_key');
        });
        Schema::dropIfExists('company_connection_events');
        Schema::table('company_integrations', fn (Blueprint $t) => $t->dropConstrainedForeignId('setup_link_id'));
        Schema::table('social_connections', fn (Blueprint $t) => $t->dropConstrainedForeignId('setup_link_id'));
        Schema::dropIfExists('company_setup_link_opens');
        Schema::dropIfExists('company_setup_links');
    }
};
