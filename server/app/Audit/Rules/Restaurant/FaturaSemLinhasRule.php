<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinSupplierDocument;
use App\Services\Restaurant\RecipeCostService;
use App\Services\SupplierDocumentLinesService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Regra 9b · Fatura sem linhas (baixa): faturas, faturas-recibo e notas de crédito ou débito de
 * fornecedor, não anuladas, sem linhas no espelho:
 *  · fechada e lida, mas sem linhas (ou a leitura das linhas falhou);
 *  · ainda aberta ou em edição no PingWin há mais de 3 dias (as linhas só se leem quando fecha).
 * As anuladas ficam de fora (o spike contava 40 com as 31 anuladas).
 */
final class FaturaSemLinhasRule extends RestaurantRule
{
    private const TYPES = ['1209', '584955579139752236', '1205', '1206'];
    private const OPEN_DAYS = 3;

    public function code(): string
    {
        return 'restauracao.fatura_sem_linhas';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function severity(): string
    {
        return 'baixa';
    }

    public function title(): string
    {
        return 'Faturas sem linhas ou por fechar';
    }

    public function description(): string
    {
        return 'Faturas de fornecedor fechadas sem linhas, ou ainda abertas ou em edição no PingWin.';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $today = (new RecipeCostService($companyId))->today();
        $docs = PingwinSupplierDocument::from('pingwin_supplier_documents as d')
            ->where('d.company_id', $companyId)->whereIn('d.docconfig_id', self::TYPES)
            ->where('d.docstatus_id', '!=', PingwinSupplierDocument::STATUS_ANULADO)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pingwin_supplier_document_lines as l')
                ->whereColumn('l.company_id', 'd.company_id')->whereColumn('l.docheader_id', 'd.docheader_id'))
            ->get(['d.docheader_id', 'd.document', 'd.doctype', 'd.doc_date', 'd.entity_name', 'd.entity_pingwin_id', 'd.total_cents',
                'd.docstatus_id', 'd.docstatus_description', 'd.lines_status', 'd.lines_error']);

        foreach ($docs as $d) {
            $closed = (string) $d->docstatus_id === SupplierDocumentLinesService::STATUS_CLOSED;
            $date = $d->doc_date ? substr((string) $d->doc_date, 0, 10) : null;
            $age = $date ? (int) Carbon::parse($date)->diffInDays($today) : null;
            if (! $closed && ($age === null || $age < self::OPEN_DAYS)) {
                continue;
            }
            $doc = Fmt::doc($d->document);
            $state = mb_strtolower((string) ($d->docstatus_description ?: 'aberta'));
            [$kind, $title, $seen, $why, $suggestion] = match (true) {
                ! $closed => ['aberta', "fatura por fechar no PingWin há {$age} dias",
                    sprintf('A fatura %s de %s, de %s, está %s no PingWin há %d dias.', $doc, $d->entity_name, Fmt::date($date), $state === 'edição' ? 'em edição' : $state, $age),
                    'Enquanto não fechar, a XPLENDOR não lê as linhas: as compras não entram no custo das fichas nem na conta corrente.',
                    'Feche a fatura no PingWin. Se não for para usar, anule-a.'],
                $d->lines_status === 'failed' => ['falhou', 'não foi possível ler as linhas',
                    sprintf('A fatura %s de %s está fechada, mas a XPLENDOR não conseguiu ler as linhas.', $doc, $d->entity_name),
                    'Sem as linhas, as compras desta fatura não entram no custo das fichas nem no stock.',
                    'Sincronize de novo os documentos do PingWin. Se continuar a falhar, fale com o suporte.'],
                default => ['vazia', 'fatura fechada sem linhas',
                    sprintf('A fatura %s de %s, de %s, está fechada no PingWin e não tem nenhuma linha.', $doc, $d->entity_name, Fmt::date($date)),
                    'Uma fatura sem linhas não entra no custo das fichas nem no stock.',
                    'Confirme a fatura no PingWin: acrescente as linhas ou anule-a.'],
            };

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $d->docheader_id, entityLabel: (string) $d->document,
                title: Fmt::title($doc, $title),
                why: $why, suggestion: $suggestion,
                evidence: ['facts' => [
                    ['label' => 'Documento', 'value' => "{$d->doctype} {$doc}"],
                    ['label' => 'Data', 'value' => Fmt::date($date)],
                    ['label' => 'Fornecedor', 'value' => $d->entity_name],
                    ['label' => 'Estado no PingWin', 'value' => $d->docstatus_description ?: $d->docstatus_id],
                    ['label' => 'Total', 'value' => ((int) $d->total_cents) / 100, 'format' => 'eur'],
                ]],
                evidenceKey: $kind,
                signature: (string) $d->docstatus_id,
                weightCents: abs((int) $d->total_cents) ?: null,
                fixUrl: self::documentUrl((string) $d->docheader_id), fixLabel: 'Abrir o documento',
                refs: array_values(array_filter([['documento', (string) $d->docheader_id], $d->entity_pingwin_id ? ['fornecedor', (string) $d->entity_pingwin_id] : null])),
                seen: $seen,
                stake: sprintf('Não há um valor direto em jogo. A relevância é o total da fatura: %s.', Fmt::cents(abs((int) $d->total_cents))),
            );
        }
    }
}
