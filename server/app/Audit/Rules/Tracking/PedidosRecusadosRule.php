<?php

declare(strict_types=1);

namespace App\Audit\Rules\Tracking;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\TrackingRejectionDaily;
use Carbon\CarbonImmutable;

/**
 * Parte B1 · Pedidos recusados pelo /api/public/track (média): um tipo recusado mais de N vezes
 * por dia (config auditoria.tracking_rejections_per_day) nos últimos 7 dias, por estado e motivo
 * (ex.: car_view com 422 nas páginas que não passam a viatura).
 */
final class PedidosRecusadosRule extends TrackingRule
{
    public function code(): string
    {
        return 'tracking.pedidos_recusados';
    }

    public function entityType(): string
    {
        return 'recusa_tracking';
    }

    public function title(): string
    {
        return 'Eventos do site recusados pela XPLENDOR';
    }

    public function description(): string
    {
        return 'Eventos que o site envia e a XPLENDOR recusa muitas vezes por dia.';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $limit = max(1, (int) config('auditoria.tracking_rejections_per_day', 20));
        $since = CarbonImmutable::now('Europe/Lisbon')->subDays(7)->toDateString();
        $groups = TrackingRejectionDaily::where('company_id', $companyId)->where('day', '>=', $since)->orderBy('day')->get()
            ->groupBy(fn ($r) => "{$r->event_type}|{$r->status}|{$r->reason}");

        foreach ($groups as $key => $rows) {
            $worst = $rows->sortByDesc('count')->first();
            if ($worst->count <= $limit) {
                continue;
            }
            $total = (int) $rows->sum('count');
            $type = (string) $worst->event_type;
            $reason = (string) $worst->reason;
            $label = self::typeLabel($type);
            $tip = match (true) {
                str_contains($reason, 'car_id') => 'Peça a quem gere o site para pôr o script da XPLENDOR só nas páginas de viatura, ou para essas páginas indicarem a viatura (data.car_id).',
                str_contains($reason, 'tipo inválido') => 'Peça a quem gere o site para corrigir o nome deste evento no script da XPLENDOR.',
                str_contains($reason, 'viatura não encontrada') => 'Peça a quem gere o site para confirmar o código de acesso da XPLENDOR e as viaturas que o site envia.',
                default => 'Peça a quem gere o site para confirmar os dados que o script da XPLENDOR envia neste evento.',
            };

            yield new AuditFindingData(
                entityType: 'recusa_tracking', entityId: (string) $key, entityLabel: "{$label} recusados",
                title: Fmt::title($label, 'o site envia pedidos que a XPLENDOR recusa'),
                why: 'Cada pedido recusado é uma visita ou um contacto do site que não entra na XPLENDOR.',
                suggestion: $tip,
                evidence: [
                    'facts' => [
                        ['label' => 'Evento', 'value' => $label], ['label' => 'Porque foram recusados', 'value' => self::reasonText($reason)],
                        ['label' => 'Recusados (7 dias)', 'value' => $total], ['label' => 'A partir de quantos por dia aparece aqui', 'value' => $limit],
                    ],
                    'table' => [
                        'columns' => [['key' => 'day', 'label' => 'Dia', 'format' => 'date'], ['key' => 'count', 'label' => 'Recusados', 'align' => 'end']],
                        'rows' => $rows->sortByDesc('day')->map(fn ($r) => ['day' => $r->day->toDateString(), 'count' => $r->count])->values()->all(),
                    ],
                ],
                signature: (string) $key,
                severity: $worst->count > $limit * 5 ? 'alta' : 'media',
                fixUrl: self::integrationsUrl($companyId), fixLabel: 'Abrir as integrações',
                refs: [['evento_tracking', $type]],
                seen: sprintf('Nos últimos 7 dias, a XPLENDOR recusou %s pedidos de %s porque %s. No pior dia, %s, foram %s.',
                    Fmt::qty($total, 0), Fmt::lower($label), self::reasonText($reason), Fmt::date($worst->day->toDateString()), Fmt::qty($worst->count, 0)),
                stake: sprintf('Não há um valor em euros calculável. O que está em jogo são %s %s que não entraram na XPLENDOR.', Fmt::qty($total, 0), Fmt::lower($label)),
            );
        }
    }
}
