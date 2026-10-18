<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\Stock\StockRule;
use App\Models\PingwinStock;
use App\Models\StockTransferRequest;
use App\Models\StockTransferRoute;
use App\Services\Restaurant\StockUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fatia 7c · Base das regras das transferências (T1 a T8). Lê o espelho dos documentos de transferência
 * (encomendas, Guias 1102/1007 e Entradas 1104 lidas do PingWin), os circuitos e o espelho do stock;
 * nunca chama o PingWin.
 *
 * As linhas gravadas no PingWin não guardam a linha de origem (provado no passo 0 da 7c): uma Entrada
 * emparelha-se com as Guias do mesmo artigo enviadas até 3 dias antes. O qnt_delivered da linha da Guia
 * diz quanto já entrou (na unidade da Guia).
 */
abstract class TransferRule extends StockRule
{
    /** Dias entre a Guia e a Entrada para as emparelhar. */
    public const PAIR_DAYS = 3;

    /** @var \WeakMap<AuditScope, array<string, mixed>>|null */
    private static ?\WeakMap $memo = null;

    public function screens(): array
    {
        return ['transferencias'];
    }

    /** Severidade pelo valor em jogo (config/auditoria.php: transfers_severity_eur). */
    protected static function severityFor(float $eur): string
    {
        $lim = (array) config('auditoria.transfers_severity_eur', ['alta' => 100, 'media' => 20]);

        return $eur >= (float) ($lim['alta'] ?? 100) ? 'alta' : ($eur >= (float) ($lim['media'] ?? 20) ? 'media' : 'baixa');
    }

    /**
     * Os documentos dos últimos dias (não anulados), as origens dos circuitos (o Economato) e os PCM.
     *
     * @return array{docs: Collection, guides: Collection, entries: Collection, orders: Collection, origins: list<string>, pcm: array<string, array<string, float>>, stock: Collection, today: string}
     */
    protected function transferData(int $companyId, AuditScope $scope): array
    {
        self::$memo ??= new \WeakMap();
        if (isset(self::$memo[$scope])) {
            return self::$memo[$scope];
        }
        $today = self::data($scope)->today();
        $docs = StockTransferRequest::where('company_id', $companyId)->where('docstatus_id', '!=', '8003')->with('lines')->get();
        $origins = StockTransferRoute::where('company_id', $companyId)->pluck('origin_warehouse_id')->map(fn ($v) => (string) $v)->unique()->values()->all()
            ?: array_values(array_filter([\App\Services\Restaurant\Transfers\TransferService::economato($companyId)['warehouse_id'] ?? null]));
        $stock = PingwinStock::where('company_id', $companyId)->get();
        $pcm = [];
        foreach ($stock as $s) {
            if ($s->pcm !== null) {
                $pcm[(string) $s->product_pingwin_id][(string) $s->warehouse_pingwin_id] = (float) $s->pcm;
            }
        }

        return self::$memo[$scope] = [
            'docs' => $docs, 'guides' => $docs->where('kind', 'guia')->values(), 'entries' => $docs->where('kind', 'entrada')->values(),
            'orders' => $docs->where('kind', 'encomenda')->values(), 'origins' => $origins, 'pcm' => $pcm, 'stock' => $stock, 'today' => $today,
        ];
    }

    /** A quantidade de uma linha em unidades de stock: o fator confirmado da unidade; senão o do PingWin. */
    protected static function base(StockUnits $units, object $line): float
    {
        $f = $line->unit_desc ? $units->factor((string) $line->product_id, (string) $line->unit_desc) : null;

        return (float) $line->qnt * ($f ?? ((float) ($line->unit_value ?: 1)));
    }

    /** O PCM no Economato (a primeira origem de circuito com PCM), ou null. */
    protected static function originPcm(array $d, string $pid): ?float
    {
        foreach ($d['origins'] as $o) {
            if (isset($d['pcm'][$pid][$o]) && $d['pcm'][$pid][$o] > 0) {
                return $d['pcm'][$pid][$o];
            }
        }

        return null;
    }

    /**
     * Emparelha as linhas das Entradas com as das Guias (mesmo artigo, Guia até PAIR_DAYS antes). Devolve
     * [pares, entradas sem guia, guias sem entrada] com as quantidades em unidades de stock.
     *
     * @return array{pairs: list<array<string, mixed>>, orphan_entries: list<array<string, mixed>>, open_guides: list<array<string, mixed>>}
     */
    protected function pairs(int $companyId, array $d): array
    {
        $units = new StockUnits($companyId);
        $g = [];
        foreach ($d['guides'] as $doc) {
            foreach ($doc->lines as $l) {
                if ((float) $l->qnt == 0.0) {
                    continue;
                }
                $g[] = ['doc' => $doc, 'line' => $l, 'base' => self::base($units, $l), 'left' => self::base($units, $l), 'date' => (string) $doc->doc_date,
                    'delivered' => (float) ($l->qnt_delivered ?? 0) > 0];
            }
        }
        usort($g, fn ($a, $b) => [$a['date'], $a['doc']->id] <=> [$b['date'], $b['doc']->id]);
        $pairs = [];
        $orphans = [];
        $entries = $d['entries']->sortBy(fn ($e) => [(string) $e->doc_date, $e->id]);
        foreach ($entries as $doc) {
            foreach ($doc->lines as $l) {
                if ((float) $l->qnt == 0.0) {
                    continue;
                }
                $base = self::base($units, $l);
                $date = (string) $doc->doc_date;
                $match = [];
                foreach ($g as $i => $x) {
                    if ($x['left'] > 1e-6 && (string) $x['line']->product_id === (string) $l->product_id && $x['delivered'] && $x['date'] <= $date
                        && CarbonImmutable::parse($x['date'])->diffInDays(CarbonImmutable::parse($date)) <= self::PAIR_DAYS) {
                        $match[] = $i;
                    }
                }
                if (! $match) {
                    $orphans[] = ['doc' => $doc, 'line' => $l, 'base' => $base];

                    continue;
                }
                $sent = 0.0;
                $guides = [];
                foreach ($match as $i) {
                    $sent += $g[$i]['left'];
                    $guides[] = $g[$i];
                    $g[$i]['left'] = 0.0;
                }
                $pairs[] = ['entry' => $doc, 'line' => $l, 'received' => $base, 'sent' => $sent, 'guides' => $guides];
            }
        }
        $open = array_values(array_filter($g, fn ($x) => ! $x['delivered']));

        return ['pairs' => $pairs, 'orphan_entries' => $orphans, 'open_guides' => $open];
    }

    protected static function transfersUrl(string $tab = ''): string
    {
        return '/restauracao/transferencias' . ($tab !== '' ? "?tab={$tab}" : '');
    }

    /** O nome do armazém ou o id. */
    protected function wh(int $companyId, ?string $id): string
    {
        return $id ? $this->warehouseName($companyId, $id) : 'armazém desconhecido';
    }
}
