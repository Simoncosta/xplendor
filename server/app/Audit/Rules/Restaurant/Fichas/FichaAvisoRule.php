<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Audit\Rules\Restaurant\RestaurantRule;

/**
 * Regra 8 · Os avisos das fichas técnicas como regras. Reaproveita o cálculo do detalhe da ficha
 * (RecipeReportService::auditWarnings, que usa o RecipeCostService e os avisos da Fatia 1b): nada de
 * lógica duplicada. Cada regra escolhe os tipos de aviso; um achado por ficha e por ingrediente
 * (avisos de linha) ou por versão (avisos de versão), nas versões em vigor ou futuras.
 * Os textos são de cada regra (texts()), em linguagem simples. Relevância: as vendas do prato.
 */
abstract class FichaAvisoRule extends RestaurantRule
{
    /** @return list<string> os tipos de aviso (RecipeCostService / RecipeReportService) */
    abstract protected function types(): array;

    /**
     * Os textos do achado: [o que está mal (para o título), "O que vimos", "Porque importa", "O que fazer"].
     *
     * @param  array{dish:string, comp:?string, compLabel:?string, version:string, warnings:list<array>, v:array}  $c
     * @return array{0:string, 1:string, 2:string, 3:string}
     */
    abstract protected function texts(array $c): array;

    public function entityType(): string
    {
        return 'ficha';
    }

    public function screens(): array
    {
        return ['fichas'];
    }

    /** Outros filtros por aviso (ex.: o sem custo deixa de fora os que têm um parecido comprado). */
    protected function keep(array $warning, array $all): bool
    {
        return true;
    }

    /** Onde "Corrigir" leva. */
    /** Fatia 3: o € do achado é a receita do prato (as regras que deixam o custo incompleto). */
    protected function revenueIsImpact(): bool
    {
        return false;
    }

    protected function fixUrlFor(string $pid, ?string $componentId, array $warning): string
    {
        return self::recipeUrl($pid);
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        foreach ($data->recipeWarnings() as $pid => $recipe) {
            $pid = (string) $pid;
            $found = [];
            foreach ($recipe['versions'] as $v) {
                foreach ($v['warnings'] as $w) {
                    if (! in_array($w['type'], $this->types(), true) || ! $this->keep($w, $v['warnings'])) {
                        continue;
                    }
                    $component = isset($w['component_id']) && $w['component_id'] !== null ? (string) $w['component_id'] : null;
                    $key = $component ?? 'versao:' . $v['bom_id'];
                    $found[$key] ??= ['component' => $component, 'version' => $v, 'warnings' => []];
                    $found[$key]['warnings'][] = $w;
                }
            }
            foreach ($found as $key => $f) {
                yield $this->finding($data, $pid, $recipe, (string) $key, $f['component'], $f['version'], $f['warnings']);
            }
        }
    }

    /** "versão de 26/05/2026, em vigor" */
    protected static function versionText(array $v): string
    {
        return sprintf('versão de %s, %s', Fmt::date($v['valid_from'] ?? null), $v['in_force'] ? 'em vigor' : 'futura');
    }

    /** "Quanto está em jogo" das fichas: a relevância são as vendas do prato. */
    protected static function stakeText(string $dish, int $salesCents): string
    {
        return $salesCents > 0
            ? sprintf('Não há um valor direto em jogo. A relevância são as vendas de %s nos últimos 90 dias: %s sem IVA.', $dish, Fmt::cents($salesCents))
            : sprintf('Não há um valor direto em jogo: %s não teve vendas nos últimos 90 dias.', $dish);
    }

    protected function finding(RestaurantAuditData $data, string $pid, array $recipe, string $key, ?string $component, array $v, array $warnings): AuditFindingData
    {
        $dish = (string) ($recipe['description'] ?? $data->item($pid)?->description ?? $pid);
        $compItem = $component ? $data->item($component) : null;
        $comp = $component ? (string) ($compItem?->description ?? $warnings[0]['line'] ?? $component) : null;
        $compLabel = $component ? str_replace(' · ', ' ', $data->label($component, $warnings[0]['line'] ?? null)) : null;
        [$problem, $seen, $why, $todo] = $this->texts([
            'dish' => $dish, 'comp' => $comp, 'compLabel' => $compLabel, 'version' => self::versionText($v), 'warnings' => $warnings, 'v' => $v,
        ]);
        $sales = (int) ($recipe['sales_cents'] ?? 0);

        return new AuditFindingData(
            entityType: 'ficha', entityId: $pid, entityLabel: 'Ficha técnica: ' . $data->label($pid, $dish),
            title: Fmt::title($dish, $problem),
            why: $why,
            suggestion: $todo,
            evidence: ['facts' => array_values(array_filter([
                ['label' => 'Ficha técnica', 'value' => $data->label($pid, $dish)],
                ['label' => 'Versão', 'value' => ucfirst(self::versionText($v))],
                $compLabel ? ['label' => 'Ingrediente', 'value' => $compLabel] : null,
                ['label' => 'Vendas sem IVA (90 dias)', 'value' => $sales / 100, 'format' => 'eur'],
            ]))],
            evidenceKey: $key,
            signature: implode('|', array_column($warnings, 'type')),
            impactCents: $this->revenueIsImpact() && $sales > 0 ? $sales : null,
            weightCents: $sales > 0 ? $sales : null,
            fixUrl: $this->fixUrlFor($pid, $component, $warnings[0]), fixLabel: 'Abrir a ficha',
            refs: array_values(array_filter([['artigo', $pid], $component ? ['artigo', $component] : null])),
            seen: $seen,
            stake: $this->revenueIsImpact() && $sales > 0
                ? sprintf('%s: a receita sem IVA de %s nos últimos 90 dias, de que só se conhece parte do custo (fica como Parcial na engenharia de menu).', Fmt::cents($sales), $dish)
                : self::stakeText($dish, $sales),
        );
    }
}
