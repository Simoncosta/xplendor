<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atribuição de leads: o modelo CarLead já tinha assigned_user_id no fillable (e assigned_at
 * existe desde 2026_03_05), mas nenhuma migração criou a coluna. Fica o comercial a quem a lead
 * está atribuída, com chave estrangeira para users; se o utilizador for apagado, a lead fica
 * sem atribuição.
 *
 * ADITIVA: só uma coluna nova, nullable. A vista car_funnel_metrics_daily depende de car_leads:
 * no sqlite larga-se e recria-se à volta da alteração (como em 2027_02_10_100000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('car_leads', 'assigned_user_id')) {
            return;
        }

        $this->withViewSafety(fn () => Schema::table('car_leads', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
        }));
    }

    public function down(): void
    {
        if (! Schema::hasColumn('car_leads', 'assigned_user_id')) {
            return;
        }

        $this->withViewSafety(fn () => Schema::table('car_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
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
