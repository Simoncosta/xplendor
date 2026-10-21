<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Pedir proposta" (pedido da Quebom): o pedido entra como lead (car_leads), com o tipo
 * "proposta", o financiamento, a retoma, a origem (origem=qr) e os consentimentos (o de ser
 * contactado, obrigatório, e o de receber novidades, opcional e à parte), cada um com a data e o
 * texto aceite. O event_id evita duas leads num duplo clique. Nada sobre quem enviou (sem IP).
 *
 * ADITIVA: só colunas novas; as leads de hoje ficam com o tipo "contacto". A vista
 * car_funnel_metrics_daily depende de car_leads: no sqlite larga-se e recria-se à volta da
 * alteração (como em 2026_09_17_120000).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->withViewSafety(fn () => Schema::table('car_leads', function (Blueprint $table) {
            $table->string('type', 20)->default('contacto')->after('message')->index();
            $table->boolean('wants_financing')->nullable()->after('type');
            $table->json('trade_in')->nullable()->after('wants_financing');
            $table->string('origin', 20)->nullable()->after('channel');
            $table->timestamp('consent_contact_at')->nullable();
            $table->text('consent_contact_text')->nullable();
            $table->boolean('consent_marketing')->default(false);
            $table->timestamp('consent_marketing_at')->nullable();
            $table->text('consent_marketing_text')->nullable();
            $table->string('policy_version', 20)->nullable();
            $table->uuid('event_id')->nullable()->unique();
        }));
    }

    public function down(): void
    {
        $this->withViewSafety(fn () => Schema::table('car_leads', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'wants_financing', 'trade_in', 'origin', 'consent_contact_at', 'consent_contact_text', 'consent_marketing',
                'consent_marketing_at', 'consent_marketing_text', 'policy_version', 'event_id']);
        }));
    }

    private function withViewSafety(callable $change): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $change();

            return;
        }
        $view = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='view' AND name='car_funnel_metrics_daily'");
        DB::statement('DROP VIEW IF EXISTS car_funnel_metrics_daily');
        $change();
        if ($view && ! empty($view->sql)) {
            DB::statement($view->sql);
        }
    }
};
