<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\IneHousingPrice;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A carteira de imóveis (Bússola Master, R3), no módulo imoveis, área de permissão imoveis.
 * Só os campos que a Bússola usa; sem morada exata nem dados de proprietários, compradores ou
 * inquilinos. O interesse são dois contadores atualizados à mão, com a data.
 */
class PropertyController extends Controller
{
    public function index(Request $request, int $companyId)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(array_keys(Property::STATUSES))], 'listing' => ['nullable', Rule::in(array_keys(Property::LISTINGS))]]);
        $today = CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $rows = Property::with('parish.municipality')->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['listing'] ?? null, fn ($q, $v) => $q->where('listing', $v))
            ->orderByDesc('listed_at')->orderByDesc('id')->get()->map(fn (Property $p) => $this->present($p, $today))->all();

        return ApiResponse::success(['properties' => $rows, 'options' => [
            'kinds' => Property::KINDS, 'typologies' => Property::TYPOLOGIES, 'listings' => Property::LISTINGS, 'statuses' => Property::STATUSES, 'energy_classes' => Property::ENERGY_CLASSES,
        ]], 'Imóveis.');
    }

    public function show(int $companyId, int $propertyId)
    {
        return ApiResponse::success($this->present($this->find($companyId, $propertyId), CarbonImmutable::now('Europe/Lisbon')->startOfDay()), 'Imóvel.');
    }

    public function store(Request $request, int $companyId)
    {
        $data = $this->validated($request, $companyId);
        $p = Property::create($data + ['company_id' => $companyId]);

        return ApiResponse::success($this->present($p->fresh('parish.municipality'), CarbonImmutable::now('Europe/Lisbon')->startOfDay()), 'Imóvel criado.', 201);
    }

    public function update(Request $request, int $companyId, int $propertyId)
    {
        $p = $this->find($companyId, $propertyId);
        $p->update($this->validated($request, $companyId, $p->id));

        return ApiResponse::success($this->present($p->fresh('parish.municipality'), CarbonImmutable::now('Europe/Lisbon')->startOfDay()), 'Imóvel guardado.');
    }

    public function destroy(int $companyId, int $propertyId)
    {
        $this->find($companyId, $propertyId)->delete();

        return ApiResponse::success(null, 'Imóvel apagado.');
    }

    private function find(int $companyId, int $propertyId): Property
    {
        $p = Property::with('parish.municipality')->where('company_id', $companyId)->find($propertyId);
        if (! $p) {
            abort(404, 'Imóvel não encontrado.');
        }

        return $p;
    }

    private function validated(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40', Rule::unique('properties', 'reference')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($ignoreId)],
            'title' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(Property::KINDS))],
            'typology' => ['nullable', Rule::in(Property::TYPOLOGIES)],
            'area_m2' => ['nullable', 'integer', 'min:1', 'max:65000'],
            'parish_id' => ['nullable', 'integer', 'exists:parishes,id'],
            'listing' => ['required', Rule::in(array_keys(Property::LISTINGS))],
            'price' => ['required', 'integer', 'min:0', 'max:999999999'],
            'energy_class' => ['nullable', Rule::in(Property::ENERGY_CLASSES)],
            'status' => ['required', Rule::in(array_keys(Property::STATUSES))],
            'listed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $today],
            'closed_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after_or_equal:listed_at'],
            'contacts_30' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'visits_30' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'reference.unique' => 'Já existe um imóvel com esta referência.',
            'listed_at.before_or_equal' => 'A data de entrada em carteira não pode ser futura.',
            'closed_at.before_or_equal' => 'A data de fecho não pode ser futura.',
        ]);
        if ($data['status'] === 'fechado' && empty($data['closed_at'])) {
            abort(422, 'Indique a data de fecho do imóvel fechado.');
        }
        if ($data['status'] !== 'fechado') {
            $data['closed_at'] = null;
        }
        // Os contadores de interesse guardam a data em que foram atualizados (à mão).
        if ($request->has('contacts_30') || $request->has('visits_30')) {
            $data['contacts_30'] = (int) ($data['contacts_30'] ?? 0);
            $data['visits_30'] = (int) ($data['visits_30'] ?? 0);
            $data['interest_updated_at'] = $today;
        }

        return $data;
    }

    private function present(Property $p, CarbonImmutable $today): array
    {
        $ref = IneHousingPrice::latestFor($p->parish?->dicofre);

        return [
            'id' => $p->id, 'reference' => $p->reference, 'title' => $p->title, 'kind' => $p->kind, 'kind_label' => Property::KINDS[$p->kind] ?? $p->kind,
            'typology' => $p->typology, 'area_m2' => $p->area_m2, 'listing' => $p->listing, 'price' => $p->price, 'energy_class' => $p->energy_class,
            'energy_class_missing' => $p->energyClassMissing(), 'status' => $p->status,
            'parish_id' => $p->parish_id, 'parish' => $p->parish?->name, 'municipality_id' => $p->parish?->municipality_id, 'municipality' => $p->parish?->municipality?->name,
            'district_id' => $p->parish?->municipality?->district_id,
            'listed_at' => $p->listed_at?->toDateString(), 'closed_at' => $p->closed_at?->toDateString(),
            'days' => $p->listed_at ? (int) CarbonImmutable::parse($p->listed_at)->diffInDays($p->closed_at ? CarbonImmutable::parse($p->closed_at) : $today) : null,
            'contacts_30' => $p->contacts_30, 'visits_30' => $p->visits_30, 'interest_updated_at' => $p->interest_updated_at?->toDateString(),
            'ine' => $ref && $ref->has_value ? ['median_m2' => $ref->median_m2, 'period' => $ref->period_label, 'attribution' => $ref->attribution()] : null,
        ];
    }
}
