<?php

declare(strict_types=1);

namespace App\Audit\Rules\Tracking;

use App\Audit\AuditRuleBase;

/** Base das regras do tracking dos sites: área "tracking". */
abstract class TrackingRule extends AuditRuleBase
{
    public function area(): string
    {
        return 'tracking';
    }

    /** O nome simples de um tipo de evento. */
    public const TYPE_LABELS = [
        'whatsapp_click' => 'Cliques no WhatsApp', 'whatsapp_send' => 'Mensagens de WhatsApp enviadas', 'call_click' => 'Cliques para ligar',
        'show_phone' => 'Pedidos para ver o telefone', 'copy_phone' => 'Telefones copiados', 'favorite' => 'Viaturas guardadas nos favoritos',
        'share' => 'Partilhas', 'form_open' => 'Formulários abertos', 'form_start' => 'Formulários começados',
        'location_view' => 'Visitas à localização', 'car_view' => 'Visitas a viaturas', 'car_lead' => 'Pedidos de contacto',
    ];

    protected static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /** O motivo da recusa, em palavras simples. */
    protected static function reasonText(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'car_id') => 'vinham sem a viatura identificada',
            str_contains($reason, 'tipo inválido') => 'traziam um tipo de evento que não existe',
            str_contains($reason, 'viatura não encontrada') => 'traziam uma viatura que não é desta empresa',
            str_contains($reason, 'client_view_key') => 'vinham sem a identificação da visita',
            default => 'traziam dados em falta ou inválidos',
        };
    }

    protected static function integrationsUrl(int $companyId): string
    {
        return "/companies/{$companyId}?tab=integrations";
    }
}
