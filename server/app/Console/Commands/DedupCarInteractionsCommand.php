<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Limpeza do histórico técnico de interações duplicadas (duplo-disparo do site).
 *
 * Apaga APENAS o registo mais recente de um par onde: mesmo company_id + car_id
 * + interaction_type + visitor_id E intervalo ≤ N segundos (default 3) do
 * anterior. Cliques reais (visitor_ids diferentes, ou intervalos > N s) NUNCA
 * são tocados — ex.: carro 120 (visitor_ids diferentes, 27s) fica intacto.
 *
 * ⚠️ APAGA dados de produção. Por defeito corre em DRY-RUN (só conta e lista).
 * Só apaga com --apply, e é idempotente (correr de novo não apaga mais nada).
 * Cautela 2026-06-09: correr dry-run → Simon aprova → --apply → re-agregar.
 */
class DedupCarInteractionsCommand extends Command
{
    protected $signature = 'interactions:dedup
                                {--apply : Apaga mesmo. Sem esta flag corre em dry-run (não apaga nada)}
                                {--seconds=3 : Intervalo máximo (s) para considerar duplicado técnico}
                                {--from= : Filtrar created_at >= (Y-m-d ou Y-m-d H:i:s)}
                                {--to=   : Filtrar created_at <= (Y-m-d ou Y-m-d H:i:s)}
                                {--sample=30 : Quantas linhas de exemplo mostrar no dry-run}';

    protected $description = 'Remove interações duplicadas por duplo-disparo técnico (mesmo visitante, ≤N s). Dry-run por defeito.';

    public function handle(): int
    {
        $seconds = max(1, (int) $this->option('seconds'));
        $from    = $this->option('from');
        $to      = $this->option('to');
        $apply   = (bool) $this->option('apply');
        $sample  = max(0, (int) $this->option('sample'));

        // Deteção em PHP (portável — sem funções específicas do motor, testável em
        // SQLite e MariaDB): dentro de cada (empresa, carro, tipo, visitante)
        // ordenado por tempo, uma linha é duplicada se o intervalo para a ANTERIOR
        // (imediatamente precedente) ≤ N s. Mantém-se a PRIMEIRA da rajada e
        // apaga(m)-se a(s) seguinte(s). Só linhas com visitor_id E car_id.
        $candidates = DB::table('car_interactions')
            ->select('id', 'company_id', 'car_id', 'interaction_type', 'visitor_id', 'created_at')
            ->whereNotNull('visitor_id')
            ->whereNotNull('car_id')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderBy('company_id')->orderBy('car_id')->orderBy('interaction_type')
            ->orderBy('visitor_id')->orderBy('created_at')->orderBy('id')
            ->get();

        $rows = [];
        $prevKey = null;
        $prevTime = null;
        foreach ($candidates as $row) {
            $key = $row->company_id . '|' . $row->car_id . '|' . $row->interaction_type . '|' . $row->visitor_id;
            $time = Carbon::parse($row->created_at);

            if ($key === $prevKey && $prevTime !== null) {
                $gap = $prevTime->diffInSeconds($time); // >= 0 (ordenado ascendente)
                if ($gap <= $seconds) {
                    $row->gap = $gap;
                    $rows[] = $row; // esta (a mais recente do par) será apagada
                }
            }

            $prevKey = $key;
            $prevTime = $time; // avança para a imediatamente precedente (semântica LAG)
        }

        $totalInRange = (int) DB::table('car_interactions')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->count();

        $toDelete = count($rows);

        $this->info('── Dedup de car_interactions ──');
        $this->line('Janela de duplicado : ≤ ' . $seconds . 's (mesmo company+car+type+visitor)');
        $this->line('Período             : ' . ($from ?: 'início') . ' → ' . ($to ?: 'agora'));
        $this->line('Total no período    : ' . $totalInRange);
        $this->line('A apagar (duplicados): ' . $toDelete
            . ($totalInRange > 0 ? '  (' . round($toDelete / $totalInRange * 100, 1) . '%)' : ''));

        if ($toDelete === 0) {
            $this->info('Nada a apagar. ✔');
            return Command::SUCCESS;
        }

        // Repartição por tipo — para o Simon ver onde estão os duplicados.
        $byType = [];
        foreach ($rows as $r) {
            $byType[$r->interaction_type] = ($byType[$r->interaction_type] ?? 0) + 1;
        }
        $this->newLine();
        $this->line('Por tipo:');
        foreach ($byType as $type => $n) {
            $this->line("  • {$type}: {$n}");
        }

        // Amostra do que seria apagado (id = a linha REMOVIDA; gap = distância à anterior).
        if ($sample > 0) {
            $this->newLine();
            $this->line("Amostra (até {$sample}) — id é a linha a apagar:");
            $this->table(
                ['id', 'car_id', 'type', 'visitor_id', 'created_at', 'gap(s)'],
                collect($rows)->take($sample)->map(fn ($r) => [
                    $r->id, $r->car_id, $r->interaction_type,
                    substr((string) $r->visitor_id, 0, 8) . '…', $r->created_at, $r->gap,
                ])->all()
            );
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN — nada foi apagado. Revê os números acima.');
            $this->warn('Antes de --apply: FAZER BACKUP da tabela, ex.:');
            $this->line('  mysqldump -u <user> -p <db> car_interactions > car_interactions_backup_$(date +%F).sql');
            $this->line('Depois, apagar a sério:  php artisan interactions:dedup --apply'
                . ($from ? " --from={$from}" : '') . ($to ? " --to={$to}" : ''));
            $this->line('E re-agregar:            php artisan performance:aggregate --sync'
                . ($from ? " --from=" . substr($from, 0, 10) : '') . ($to ? " --to=" . substr($to, 0, 10) : ''));
            return Command::SUCCESS;
        }

        // ── Apagamento real (só com --apply) ──
        if (! $this->confirm("Vais APAGAR {$toDelete} registos de produção. Confirmas?", false)) {
            $this->info('Cancelado.');
            return Command::SUCCESS;
        }

        $ids = array_map(fn ($r) => $r->id, $rows);
        $deleted = 0;
        DB::transaction(function () use ($ids, &$deleted) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $deleted += DB::table('car_interactions')->whereIn('id', $chunk)->delete();
            }
        });

        $this->info("Apagados {$deleted} registos.");
        $this->warn('Agora re-corre a agregação para os KPIs refletirem os dados limpos:');
        $this->line('  php artisan performance:aggregate --sync'
            . ($from ? " --from=" . substr($from, 0, 10) : '') . ($to ? " --to=" . substr($to, 0, 10) : ''));

        return Command::SUCCESS;
    }
}
