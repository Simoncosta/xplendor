<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Fichas;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\RestaurantAuditData;

/** Regra 8 · Lojas sem ficha técnica em vigor: o prato vendeu (90 dias) numa loja onde nenhuma versão está em vigor. */
final class LojaSemFichaRule extends FichaAvisoRule
{
    public function code(): string
    {
        return 'restauracao.ficha_loja_sem_ficha';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function title(): string
    {
        return 'Pratos vendidos em lojas sem ficha técnica';
    }

    public function description(): string
    {
        return 'Pratos vendidos numa loja onde nenhuma versão da ficha está em vigor.';
    }

    protected function types(): array
    {
        return [];
    }

    protected function texts(array $c): array
    {
        return ['', '', '', ''];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        foreach ($data->recipeWarnings() as $pid => $recipe) {
            if (! $recipe['missing_stores']) {
                continue;
            }
            $pid = (string) $pid;
            $dish = (string) ($recipe['description'] ?? $data->item($pid)?->description ?? $pid);
            $stores = implode(', ', $recipe['missing_stores']);
            $sales = (int) ($recipe['sales_cents'] ?? 0);

            yield new AuditFindingData(
                entityType: 'ficha', entityId: $pid, entityLabel: 'Ficha técnica: ' . $data->label($pid, $dish),
                title: Fmt::title($dish, 'vendido em lojas sem ficha técnica'),
                why: 'Nessas lojas, as vendas não descontam os ingredientes do stock e não têm custo.',
                suggestion: 'Abra a ficha, carregue em «Editar ficha» e ative essas lojas na versão em vigor (ou crie uma versão para elas).',
                evidence: ['facts' => [
                    ['label' => 'Ficha técnica', 'value' => $data->label($pid, $dish)],
                    ['label' => 'Lojas sem ficha em vigor', 'value' => $stores],
                    ['label' => 'Vendas sem IVA (90 dias)', 'value' => $sales / 100, 'format' => 'eur'],
                ]],
                evidenceKey: 'lojas',
                signature: $stores,
                weightCents: $sales > 0 ? $sales : null,
                fixUrl: self::recipeUrl($pid, ['editar' => 1]), fixLabel: 'Editar a ficha',
                refs: [['artigo', $pid]],
                seen: sprintf('Nos últimos 90 dias, %s vendeu em %s, onde nenhuma versão da ficha está em vigor.', $dish, $stores),
                stake: self::stakeText($dish, $sales),
            );
        }
    }
}
