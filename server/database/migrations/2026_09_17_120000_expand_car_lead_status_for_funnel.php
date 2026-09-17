<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — CRM/funil de leads. O `status` era um ENUM restrito
 * (new/contacted/qualified/won/lost/spam). O funil da Matilde precisa de mais
 * fases (Visita, Negociação). Converte-se para STRING: as fases passam a ser
 * validadas na aplicação (CarLead::STATUSES) — acrescentar fases no futuro deixa
 * de exigir migration.
 *
 * ADITIVA e segura: só ALARGA a coluna (varchar), sem perder dados.
 *
 * ⚠️ No sqlite (testes), o ->change() reconstrói a tabela, mas a VIEW
 * `car_funnel_metrics_daily` depende de car_leads e bloqueia o rename. Por isso,
 * no sqlite, guardamos a definição da view, largamo-la, alteramos a coluna e
 * recriamo-la (genérico, sem copiar SQL). No MySQL o ->change() usa MODIFY e a
 * view não é afetada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->withViewSafety(fn () => Schema::table('car_leads', function (Blueprint $table) {
            $table->string('status', 20)->default('new')->change();
        }));
    }

    public function down(): void
    {
        $this->withViewSafety(fn () => Schema::table('car_leads', function (Blueprint $table) {
            $table->enum('status', ['new', 'contacted', 'qualified', 'won', 'lost', 'spam'])
                ->default('new')->change();
        }));
    }

    /**
     * Corre $change protegendo a view dependente no sqlite (largar antes,
     * recriar depois). Noutros drivers corre diretamente.
     */
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
