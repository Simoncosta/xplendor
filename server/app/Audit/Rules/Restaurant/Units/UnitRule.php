<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Audit\Rules\Restaurant\RestaurantRule;
use App\Services\Restaurant\Units\UnitEvidence;
use App\Services\Restaurant\Units\UnitProposal;

/**
 * Auditoria v2 · base da família "Unidades" (U0 a U8): as evidências de cada artigo (UnitEvidence,
 * uma vez por corrida), a severidade pelo € em jogo, e o "Corrigir" que abre o Guião de unidades
 * nesse artigo. Os artigos marcados "Não se aplica" no Guião ficam de fora de todas as regras.
 */
abstract class UnitRule extends RestaurantRule
{
    public const FAMILY = 'unidades';

    public function family(): string
    {
        return self::FAMILY;
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function screens(): array
    {
        return ['unidades', 'artigos', 'faturas', 'fichas'];
    }

    protected function evidence(AuditScope $scope): UnitEvidence
    {
        return $scope->memo('restauracao.units', fn () => new UnitEvidence(RestaurantAuditData::for($scope)));
    }

    /**
     * As evidências dos artigos que contam: sem os "Não se aplica" e, por omissão, sem os já decididos
     * no Guião (confirmado ou aplicado no PingWin): a decisão foi tomada; o achado resolve-se.
     */
    protected function articles(AuditScope $scope, bool $skipDecided = true): iterable
    {
        foreach ($this->evidence($scope)->all() as $pid => $e) {
            $state = $e['profile']['state'] ?? null;
            if (! ($e['stock_relevant'] ?? true) || $state === 'nao_se_aplica' || ($skipDecided && in_array($state, ['confirmado', 'aplicado_no_pingwin'], true))) {
                continue;
            }
            yield (string) $pid => $e;
        }
    }

    /** A proposta do Guião para o artigo (uma vez por corrida), com a confiança final. */
    protected function proposal(AuditScope $scope, array $e): array
    {
        return $scope->memo('restauracao.units.proposal.' . $e['product_id'], fn () => UnitProposal::build($e));
    }

    protected function confidence(AuditScope $scope, array $e): float
    {
        return \App\Services\Restaurant\Units\UnitProfileService::confidenceOf($e, $this->proposal($scope, $e));
    }

    protected static function severityFor(float $eur): string
    {
        $t = (array) config('auditoria.units_severity_eur');

        return $eur >= (float) ($t['alta'] ?? 100) ? 'alta' : ($eur >= (float) ($t['media'] ?? 20) ? 'media' : 'baixa');
    }

    /** O custo dos componentes gastos nas fichas em 90 dias (quantidade base × vendas × preço). */
    protected static function recipeConsumptionEur(array $e, ?float $price): ?float
    {
        if ($price === null) {
            return null;
        }
        $q = 0.0;
        foreach ($e['recipes'] as $r) {
            $q += $r['qnt_base'] * $r['sold_qty_90d'];
        }

        return $q > 0 ? round($q * $price, 2) : null;
    }

    /** Os factos comuns do artigo (para o "O que vimos" da evidência). */
    protected static function facts(array $e, array $extra = []): array
    {
        return array_values(array_filter(array_merge([
            ['label' => 'Família', 'value' => $e['family'] ?: 'Sem família'],
            ['label' => 'Unidade base no PingWin', 'value' => $e['base_unit'] ?: ($e['base_family'] ? UnitProposal::baseLabel($e['base_family']) : 'Por saber')],
            $e['packaging'] ? ['label' => 'Embalagem lida na descrição', 'value' => $e['packaging']['label'], 'tip' => 'Lida de «' . ($e['packaging']['source'] ?? $e['packaging']['text']) . '».'] : null,
            ['label' => 'Compras (90 dias)', 'value' => $e['spend_90d_eur'], 'format' => 'eur'],
        ], $extra)));
    }

    /** A tabela das compras por fornecedor e unidade faturada. */
    protected static function purchasesTable(array $purchases, string $title = 'As compras por fornecedor e unidade'): array
    {
        return [
            'title' => $title,
            'columns' => [
                ['key' => 'supplier', 'label' => 'Fornecedor'], ['key' => 'unit', 'label' => 'Unidade faturada'],
                ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'], ['key' => 'pingwin', 'label' => 'Fator no PingWin', 'align' => 'end'],
                ['key' => 'pack', 'label' => 'Embalagem na descrição'], ['key' => 'total_cents', 'label' => 'Total (12 meses)', 'align' => 'end', 'format' => 'cents'],
            ],
            'rows' => array_map(fn ($p) => [
                'supplier' => $p['supplier'], 'unit' => $p['unit_label'], 'qty' => Fmt::qty($p['qnt']),
                'pingwin' => $p['pingwin_factor'] !== null ? Fmt::qty($p['pingwin_factor']) : '',
                'pack' => $p['packaging']['label'] ?? '', 'total_cents' => (int) round($p['total_eur'] * 100),
            ], array_slice($purchases, 0, 12)),
        ];
    }

    /** As referências do achado: o artigo, os fornecedores e os últimos documentos. */
    protected static function refsFor(array $e, ?array $purchases = null): array
    {
        $refs = [['artigo', $e['product_id']]];
        foreach ($purchases ?? $e['purchases'] as $p) {
            if ($p['last_doc']) {
                $refs[] = ['documento', (string) $p['last_doc']];
            }
        }

        return array_values(array_unique($refs, SORT_REGULAR));
    }

    /** O achado com o "Corrigir" que abre o Guião neste artigo. */
    protected function finding(array $e, string $problem, string $seen, string $why, string $todo, string $stake, array $evidence,
        ?int $impactCents = null, ?int $pendingCents = null, ?int $weightCents = null, ?string $severity = null, string $key = '',
        ?string $signature = null, ?array $refs = null): AuditFindingData
    {
        return new AuditFindingData(
            entityType: 'artigo', entityId: $e['product_id'], entityLabel: $e['label'],
            title: Fmt::title($e['description'], $problem),
            why: $why, suggestion: $todo, evidence: $evidence, evidenceKey: $key, signature: $signature,
            impactCents: $impactCents, weightCents: $weightCents ?? ((int) round($e['spend_90d_eur'] * 100) ?: null),
            severity: $severity, fixUrl: self::guiaoUrl($e['product_id']), fixLabel: 'Abrir no Guião de unidades',
            fixAction: ['type' => 'guiao', 'product_id' => $e['product_id']],
            refs: $refs ?? self::refsFor($e), impactPendingCents: $pendingCents, seen: $seen, stake: $stake,
        );
    }

    public static function guiaoUrl(string $pid): string
    {
        return '/restauracao/auditoria/guiao?artigo=' . rawurlencode($pid);
    }
}
