<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinPosDocument;
use App\Services\PingwinService;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — Auditoria v2-C · C3: o espelho dos documentos de caixa (SÓ LEITURA). Lê a lista
 * "Documentos" do PingWin um dia e um tipo de cada vez, em lotes de LOT_DAYS dias por docker exec.
 * À noite (fora do horário de serviço) lê os últimos NIGHTLY_DAYS dias.
 */
class PosDocumentsService
{
    public const LOT_DAYS = 7;
    public const NIGHTLY_DAYS = 2;

    public function __construct(private readonly PingwinService $pingwin) {}

    /** @return array{days:int, lots:int, calls:int, documents:int} */
    public function sync(int $companyId, string $from, string $to, ?array $types = null): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $out = ['days' => 0, 'lots' => 0, 'calls' => 0, 'documents' => 0];
        for ($d = $start; $d->lte($end); $d = $d->addDays(self::LOT_DAYS)) {
            $lotEnd = $d->addDays(self::LOT_DAYS - 1)->min($end);
            $res = $this->pingwin->fetchPosDocuments($companyId, $d->toDateString(), $lotEnd->toDateString(), $types);
            $out['lots']++;
            $out['days'] += $d->diffInDays($lotEnd) + 1;
            $out['calls'] += $res['calls'];
            $out['documents'] += self::upsert($companyId, $res['documents']);
        }

        return $out;
    }

    public static function upsert(int $companyId, array $docs): int
    {
        $now = now();
        $str = static fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
        $rows = [];
        foreach ($docs as $d) {
            $id = $str($d['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $rows[$id] = [
                'company_id' => $companyId, 'docheader_id' => $id, 'docconfig_id' => (string) ($d['docconfig_id'] ?? ''),
                'doctype' => $str($d['doctype'] ?? null), 'document' => $str($d['document'] ?? null),
                'store_pingwin_id' => $str($d['store_id'] ?? null), 'store_name' => $str($d['store'] ?? null),
                'doc_date' => preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', trim((string) ($d['doc_date'] ?? '')), $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null,
                'doc_time' => preg_match('/T?(\d{2}):(\d{2}):(\d{2})$/', trim((string) ($d['doc_time'] ?? '')), $m) ? "{$m[1]}:{$m[2]}:{$m[3]}" : null,
                'total_cents' => (int) round(((float) ($d['total'] ?? 0)) * 100),
                'employee_pingwin_id' => $str($d['employee_id'] ?? null), 'employee_name' => $str($d['employee'] ?? null),
                'docstatus_id' => $str($d['docstatus_id'] ?? null), 'docstatus_description' => $str($d['docstatus_description'] ?? null),
                'accumulator' => $str($d['accumulator'] ?? null),
                'raw' => json_encode($d, JSON_UNESCAPED_UNICODE),
                'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if (! $rows) {
            return 0;
        }
        $rows = array_values($rows);
        $update = array_values(array_diff(array_keys($rows[0]), ['company_id', 'docheader_id', 'first_seen_at', 'created_at']));
        foreach (array_chunk($rows, 200) as $chunk) {
            PingwinPosDocument::upsert($chunk, ['company_id', 'docheader_id'], $update);
        }

        return count($rows);
    }
}
