<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PingwinDocumentConfig;
use App\Services\PingwinService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Disparo MANUAL da LEITURA RICA de Documentos (Fase D0, só leitura). Síncrono.
 * ⚠️ Corre no container COM socket Docker (worker):
 *   docker exec xplendor-worker php artisan pingwin:sync-docconfig 5
 */
class PingwinSyncDocConfigCommand extends Command
{
    protected $signature = 'pingwin:sync-docconfig {company : ID da empresa (ex.: 5 = Yuko)}';

    protected $description = 'Leitura RICA (D0) da config de documentos do PingWin para o espelho da empresa.';

    public function handle(PingwinService $pingwin): int
    {
        $companyId = (int) $this->argument('company');
        $this->info("A ler config rica de documentos do Yuko/empresa {$companyId}…");

        try {
            $count = $pingwin->syncDocumentConfigsRich($companyId);
        } catch (\Throwable $e) {
            $this->error('Falhou: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("OK — {$count} documento(s) guardado(s).");

        $rows = PingwinDocumentConfig::where('company_id', $companyId)
            ->whereNotNull('rich_synced_at')
            ->orderBy('code')
            ->get()
            ->take(12);

        $this->table(
            ['external_id', 'code', 'descrição', 'taxscenario_id', 'doctype_id', '#paycond', '#docaccount', '#import'],
            $rows->map(fn ($r) => [
                $r->external_id,
                $r->code,
                mb_strimwidth((string) $r->description, 0, 22, '…'),
                $r->taxscenario_id,
                $r->doctype_id,
                is_array($r->docconfig_paycond) ? count($r->docconfig_paycond) : 0,
                is_array($r->docconfig_docaccount) ? count($r->docconfig_docaccount) : 0,
                is_array($r->docconfig_import) ? count($r->docconfig_import) : 0,
            ])->all()
        );

        return self::SUCCESS;
    }
}
