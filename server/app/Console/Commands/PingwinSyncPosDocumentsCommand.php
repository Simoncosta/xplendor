<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Restaurant\PosDocumentsService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Auditoria v2-C · C3: documentos de caixa do PingWin (SÓ LEITURA), um dia e um tipo de
 * cada vez, em lotes de 7 dias. Fora do horário de serviço.
 *
 * ⚠️ O invoke() faz `docker exec` ao container do scraper → corre no WORKER:
 *   docker exec xplendor-worker php artisan pingwin:sync-pos-documents 5 --from=2026-09-18 --to=2026-10-17
 */
class PingwinSyncPosDocumentsCommand extends Command
{
    protected $signature = 'pingwin:sync-pos-documents
        {company : ID da empresa (ex.: 5)}
        {--from= : Primeiro dia (AAAA-MM-DD); por omissão, anteontem}
        {--to= : Último dia (AAAA-MM-DD); por omissão, ontem}
        {--types= : Só estes tipos (ex.: 1002,1031,1018)}';

    protected $description = 'Sincroniza (leitura) os documentos de caixa do PingWin (anulações, consultas de mesa, vendas).';

    public function handle(PosDocumentsService $docs): int
    {
        $to = (string) ($this->option('to') ?: now('Europe/Lisbon')->subDay()->toDateString());
        $from = (string) ($this->option('from') ?: now('Europe/Lisbon')->subDays(PosDocumentsService::NIGHTLY_DAYS)->toDateString());
        $types = $this->option('types') ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('types'))))) : null;
        try {
            $s = $docs->sync((int) $this->argument('company'), $from, $to, $types);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('%s a %s · dias: %d · lotes: %d · pedidos: %d · documentos: %d', $from, $to, $s['days'], $s['lots'], $s['calls'], $s['documents']));

        return self::SUCCESS;
    }
}
