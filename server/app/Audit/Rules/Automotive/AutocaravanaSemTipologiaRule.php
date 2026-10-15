<?php

declare(strict_types=1);

namespace App\Audit\Rules\Automotive;

use App\Audit\AuditFindingData;
use App\Audit\AuditRuleBase;
use App\Audit\AuditScope;
use App\Models\Car;
use App\Services\CompanyModuleService;
use App\Services\MarketSnapshotService;

/**
 * Parte B2 · Autocaravana sem categoria ou tipologia (média): autocaravanas em stock sem categoria,
 * ou com uma categoria sem tipologia para o motor de preço (MarketSnapshotService::bodyTypeFor).
 * O motor de preço não as avalia. "Corrigir" abre a ficha da viatura.
 */
final class AutocaravanaSemTipologiaRule extends AuditRuleBase
{
    public function code(): string
    {
        return 'automovel.autocaravana_sem_tipologia';
    }

    public function area(): string
    {
        return 'automovel';
    }

    public function entityType(): string
    {
        return 'viatura';
    }

    public function title(): string
    {
        return 'Autocaravanas sem categoria';
    }

    public function description(): string
    {
        return 'Autocaravanas em stock sem categoria, que por isso ficam sem preço de referência.';
    }

    public function appliesTo(int $companyId): bool
    {
        return app(CompanyModuleService::class)->isEnabled($companyId, 'stock');
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $cars = Car::with(['brand:id,name', 'model:id,name', 'category:id,name,slug'])
            ->where('company_id', $companyId)->where('vehicle_type', 'motorhome')
            ->whereIn('status', Car::IN_STOCK_STATUSES)->orderBy('id')->get();

        foreach ($cars as $car) {
            if (MarketSnapshotService::bodyTypeFor($car->category?->slug) !== null) {
                continue;
            }
            $name = trim(implode(' ', array_filter([$car->brand?->name, $car->model?->name, $car->version])));
            $extra = implode(', ', array_filter([$car->registration_year, $car->license_plate]));
            $label = ($name ?: 'Autocaravana') . ($extra !== '' ? " ({$extra})" : '');
            $seen = $car->category
                ? sprintf('A autocaravana %s, em stock, tem a categoria «%s», que não é uma das que o preço de referência usa (capucine, integral, perfilada ou campervan).', $label, $car->category->name)
                : sprintf('A autocaravana %s, em stock, não tem categoria (capucine, integral, perfilada ou campervan).', $label);
            $price = (float) ($car->price_gross ?? 0);

            yield new AuditFindingData(
                entityType: 'viatura', entityId: (string) $car->id, entityLabel: $label,
                title: \App\Audit\Fmt::title($name ?: $label, 'autocaravana sem categoria'),
                why: 'Sem a categoria, a XPLENDOR não a compara com o mercado: não há preço de referência nem posição face à concorrência.',
                suggestion: 'Abra a ficha da viatura e escolha a categoria no campo «Categoria».',
                evidence: ['facts' => [
                    ['label' => 'Viatura', 'value' => $label],
                    ['label' => 'Estado', 'value' => ['active' => 'À venda', 'available_soon' => 'Disponível em breve', 'reserved' => 'Reservada'][$car->status] ?? $car->status],
                    ['label' => 'Categoria', 'value' => $car->category?->name ?? 'sem categoria'],
                    ['label' => 'Ano', 'value' => $car->registration_year ?? '—'],
                ]],
                signature: (string) ($car->category?->slug ?? ''),
                weightCents: (int) round((float) ($car->price_gross ?? 0) * 100) ?: null,
                fixUrl: "/cars/{$car->id}", fixLabel: 'Abrir a viatura',
                refs: [['viatura', (string) $car->id]],
                seen: $seen,
                stake: $price > 0
                    ? sprintf('Não há um valor direto em jogo. A relevância é o preço de venda da viatura: %s.', \App\Audit\Fmt::eur($price))
                    : 'Não há um valor direto em jogo.',
            );
        }
    }
}
