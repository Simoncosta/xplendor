<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\StockTransfer;
use App\Services\Restaurant\StockUnits;
use Carbon\CarbonImmutable;

/**
 * Fatia 7c · T8 · Encomenda de um restaurante por enviar há mais de 3 dias (informação): fechada no
 * PingWin, sem Guia feita a partir dela e sem envio na XPLENDOR.
 */
final class EncomendaPorEnviarRule extends TransferRule
{
    public function code(): string
    {
        return 'restauracao.transfer_encomenda_parada';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Encomendas dos restaurantes por enviar';
    }

    public function description(): string
    {
        return 'Encomendas dos restaurantes que o Economato ainda não enviou.';
    }

    public function severity(): string
    {
        return 'info';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        $limit = CarbonImmutable::parse($d['today'])->subDays((int) config('auditoria.transfers_order_days', 3))->toDateString();
        $active = StockTransfer::where('company_id', $companyId)->whereNotIn('status', ['anulado', 'falhou_envio'])->whereNotNull('request_id')->pluck('request_id')->all();
        $units = new StockUnits($companyId);
        foreach ($d['orders'] as $o) {
            if ($o->docstatus_id !== '8002' || $o->converted || in_array($o->id, $active, true) || ! $o->doc_date || (string) $o->doc_date > $limit) {
                continue;
            }
            $eur = $o->lines->sum(fn ($l) => self::base($units, $l) * (self::originPcm($d, (string) $l->product_id) ?? 0));
            $days = CarbonImmutable::parse((string) $o->doc_date)->diffInDays(CarbonImmutable::parse($d['today']));

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $o->pingwin_doc_id, entityLabel: (string) $o->document,
                title: Fmt::title(Fmt::doc($o->document), 'por enviar'),
                why: 'O restaurante pediu e o Economato ainda não enviou. Ou a mercadoria seguiu sem Guia (e o stock dos dois armazéns está errado), ou o pedido ficou esquecido.',
                suggestion: 'Envie a encomenda em Restauração › Transferências › Pedidos por enviar. Se já não é precisa, anule-a no PingWin.',
                evidence: ['table' => ['title' => 'Linhas pedidas', 'columns' => [
                    ['key' => 'article', 'label' => 'Artigo'], ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'],
                ], 'rows' => $o->lines->map(fn ($l) => ['article' => trim(($l->code ? $l->code . ' ' : '') . $l->description), 'qty' => Fmt::qty($l->qnt) . ' ' . $l->unit_desc])->values()->all()]],
                signature: (string) $o->pingwin_doc_id,
                weightCents: $eur > 0 ? (int) round($eur * 100) : null,
                fixUrl: self::transfersUrl('pedidos'), fixLabel: 'Abrir os pedidos por enviar',
                seen: sprintf('A %s de %s, de %s, está por enviar há %d dias (%d %s).', $o->document, $o->store_name ?: 'um restaurante', Fmt::date((string) $o->doc_date), $days,
                    $o->lines->count(), $o->lines->count() === 1 ? 'linha' : 'linhas'),
                stake: $eur > 0 ? sprintf('Não há um valor direto em jogo. O pedido vale %s ao PCM do Economato.', Fmt::eur($eur)) : 'Não há um valor direto em jogo.',
            );
        }
    }
}
