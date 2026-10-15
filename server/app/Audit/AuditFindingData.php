<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * XPLENDOR — Auditoria: um achado como a regra o devolve. O painel mostra quatro blocos: "O que
 * vimos" (seen: os factos, com números, unidades e datas), "Porque importa" (why), "O que fazer"
 * (suggestion) e "Quanto está em jogo" (stake: o valor e como se calculou). O motor calcula a impressão digital
 * (regra + entidade + evidenceKey) e a assinatura da evidência (signature, ou o hash da evidência).
 *
 * evidence: ['facts' => [['label','value','format'?]], 'table' => ['columns' => [['key','label','align'?,'format'?]], 'rows' => [...]], 'note' => ?]
 * Os euros vão em chaves *_cents ou com format "eur": saem do servidor só para quem tem financas.ver.
 */
final class AuditFindingData
{
    /**
     * @param  list<array{0:string,1:string}>  $refs  outras entidades (tipo, id) a que o achado se refere
     */
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $entityLabel,
        public readonly string $title,
        public readonly string $why,
        public readonly string $suggestion,
        public readonly array $evidence = [],
        public readonly string $evidenceKey = '',
        public readonly ?string $signature = null,
        public readonly ?int $impactCents = null,
        public readonly ?int $weightCents = null,
        public readonly ?string $severity = null,
        public readonly ?string $fixUrl = null,
        public readonly ?string $fixLabel = null,
        public readonly ?array $fixAction = null,
        public readonly array $refs = [],
        /** O impacto "a confirmar": mostra-se, mas não entra na soma do Pareto. */
        public readonly ?int $impactPendingCents = null,
        /** "O que vimos": os factos, em linguagem simples. */
        public readonly string $seen = '',
        /** "Quanto está em jogo": o valor e como se calculou, numa frase. */
        public readonly string $stake = '',
    ) {}
}
