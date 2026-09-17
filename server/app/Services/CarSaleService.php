<?php

namespace App\Services;

use App\Mail\CarSoldNotificationMail;
use App\Models\Car;
use App\Models\CarLead;
use App\Models\CarPerformanceMetric;
use App\Models\CarSale;
use App\Repositories\Contracts\CarRepositoryInterface;
use App\Repositories\Contracts\CarSaleRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CarSaleService extends BaseService
{
    public function __construct(
        protected CarSaleRepositoryInterface $carSaleRepository,
        protected CarRepositoryInterface $carRepository,
        protected CarService $carService,
        protected SalesLearningService $salesLearningService,
        protected CampaignToSaleAttributionService $campaignToSaleAttributionService,
        protected LeadMatchService $leadMatchService,
    ) {
        parent::__construct($carSaleRepository);
    }

    // Fase 2 — CRM. Estados "abertos" do funil (nem Venda/Perdida/Spam).
    private const OPEN_FUNNEL_STATES = ['new', 'contacted', 'visit', 'qualified', 'negotiation'];
    // Prioridade de sugestão (mais provável primeiro).
    private const STATE_PRIORITY = ['negotiation' => 0, 'qualified' => 1, 'visit' => 2, 'contacted' => 3, 'new' => 4];

    /**
     * Fase 2 — deteção: dado o car vendido, encontra leads ABERTAS no funil do
     * MESMO cliente da venda (match por lead_id direto, senão por email/telefone
     * do cliente vs contacto inline da lead). Devolve candidatas ordenadas
     * (Negociação primeiro). NÃO move nada — só sugere (o Simon confirma).
     *
     * Casos: venda sem cliente → []; cliente sem leads abertas → []; leads já em
     * Venda/Perdida → excluídas (só OPEN_FUNNEL_STATES).
     */
    public function detectOpenLeadsForSale(int $companyId, int $carId): array
    {
        $sale = CarSale::query()->where('car_id', $carId)->where('company_id', $companyId)->first();
        if (! $sale) {
            return [];
        }

        // 1) Ligação direta (se a venda já aponta a uma lead aberta).
        if ($sale->lead_id) {
            $direct = CarLead::query()
                ->where('company_id', $companyId)
                ->where('id', $sale->lead_id)
                ->whereIn('status', self::OPEN_FUNNEL_STATES)
                ->first();
            if ($direct) {
                return [$this->leadCandidate($direct)];
            }
        }

        // 2) Match pelo cliente da venda (contacto). Sem cliente → não dá match.
        $customer = $sale->customer;
        if (! $customer) {
            return [];
        }

        // Fonte única de match (reutilizada pela ficha-hub, Fase 3).
        $matches = $this->leadMatchService
            ->byContact($companyId, $customer->email, $customer->phone, onlyOpen: true)
            ->sortBy(fn (CarLead $l) => [self::STATE_PRIORITY[$l->status] ?? 9, -$l->id])
            ->values();

        return $matches->map(fn (CarLead $l) => $this->leadCandidate($l))->all();
    }

    /**
     * Fase 2 — confirmação: liga a lead à venda (car_sales.lead_id) E move-a para
     * "Venda" (won) no funil. Só age sobre leads ABERTAS desta empresa (nunca
     * mexe em Venda/Perdida). Atómico.
     */
    public function linkLeadAndWin(int $companyId, int $carId, int $leadId): CarLead
    {
        return DB::transaction(function () use ($companyId, $carId, $leadId) {
            $lead = CarLead::query()
                ->where('company_id', $companyId)
                ->where('id', $leadId)
                ->whereIn('status', self::OPEN_FUNNEL_STATES)
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                throw new \DomainException('Lead não encontrada ou já não está aberta no funil.');
            }

            $sale = CarSale::query()->where('car_id', $carId)->where('company_id', $companyId)->first();
            if ($sale) {
                $sale->update(['lead_id' => $lead->id]); // amarra venda→lead
            }

            // Move para "Venda" no funil (mesma semântica do CarLeadController@update).
            $lead->update(['status' => 'won', 'lost_reason' => null, 'closed_at' => now()]);

            return $lead->fresh();
        });
    }

    /** Forma compacta de uma lead candidata para a UI. */
    private function leadCandidate(CarLead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'status' => $lead->status,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'created_at' => optional($lead->created_at)->toIso8601String(),
        ];
    }

    /**
     * Update OR create os dados PII do comprador num car_sale existente.
     *
     * Difere do closeSale():
     *   - NÃO actualiza o car (apenas o sale).
     *   - NÃO chama markAsSold (car já está sold por pré-condição).
     *   - NÃO dispara email/notificações de venda.
     *   - Idempotente: pode ser chamado N vezes.
     *
     * Pré-condição: o car tem de pertencer à empresa (tenant) E estar sold.
     * Garante upsert por car_id (UNIQUE constraint em car_sales.car_id).
     */
    public function updateSale(int $companyId, int $carId, array $data): CarSale
    {
        $car = Car::query()
            ->where('id', $carId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        if ($car->status !== 'sold') {
            throw new \DomainException('A viatura tem de estar vendida para editar os dados do comprador.');
        }

        return DB::transaction(function () use ($companyId, $carId, $data) {
            $existing = CarSale::query()->where('car_id', $carId)->first();

            // RGPD — o MESMO gate de consentimento do fecho aplica-se aqui
            // (antes a edição gravava PII sem verificar — buraco corrigido).
            $payload = $this->applyBuyerConsentGate(array_merge($data, [
                'car_id'     => $carId,
                'company_id' => $companyId,
            ]));

            if ($existing) {
                $existing->update($payload);
                $sale = $existing->refresh();
            } else {
                $sale = CarSale::create($payload);
            }

            Log::info('[Car Sale] Dados do comprador actualizados', [
                'company_id' => $companyId,
                'car_id'     => $carId,
                'created'    => !$existing,
            ]);

            return $sale;
        });
    }

    public function closeSale(int $companyId, int $carId, array $data): CarSale
    {
        $car = $this->carRepository->findOrFail(
            $carId,
            'id',
            ['*'],
            [],
            ['company_id' => $companyId]
        );

        if (!isset($car->id)) {
            throw new \RuntimeException('Viatura não encontrada para esta empresa.');
        }

        return DB::transaction(function () use ($companyId, $carId, $data) {
            $carData = $this->extractCarData($data, $companyId);
            $saleData = $this->extractSaleData($data, $companyId, $carId);

            $this->carService->update($carId, $carData);

            $existingSale = $this->carSaleRepository->findOrFail($carId, 'car_id');

            if (isset($existingSale->id)) {
                $sale = $this->carSaleRepository->update($existingSale->id, $saleData);
            } else {
                $sale = $this->carSaleRepository->store($saleData);
            }

            Log::info('[Car Sale] Venda registada com sucesso', [
                'company_id' => $companyId,
                'car_id' => $carId,
                'sale_price' => $saleData['sale_price'],
                'sale_channel' => $saleData['sale_channel'],
            ]);

            $car = Car::query()->where('company_id', $companyId)->findOrFail($carId);
            $this->markAsSold($car, [
                ...$saleData,
                'buyer_age' => $data['buyer_age'] ?? null,
                'force_notification' => true,
            ]);

            // DMS Pós-venda — ao fechar a venda, garante o relatório de
            // satisfação (token estável). Idempotente: reabrir/reguardar a venda
            // não muda o link que o cliente já recebeu.
            \App\Models\SatisfactionReport::ensureForSale($sale);

            return $sale->load(['car', 'company']);
        });
    }

    public function markAsSold(Car $car, array $context = []): void
    {
        $soldAt = Carbon::parse($context['sold_at'] ?? $car->sold_at ?? now());
        $wasAlreadySold = $car->status === 'sold' && $car->sold_at !== null;

        if (!$car->sold_at || !$car->sold_at->equalTo($soldAt) || $car->status !== 'sold') {
            $car->forceFill([
                'status' => 'sold',
                'sold_at' => $soldAt,
            ])->save();
        }

        $car->refresh();

        $this->fillTimesToSale($car, $soldAt);
        $this->salesLearningService->captureSaleSnapshot($car, [
            'sold_at' => $soldAt,
            'sale_price' => $context['sale_price'] ?? null,
            'buyer_age' => $context['buyer_age'] ?? null,
            'buyer_gender' => $context['buyer_gender'] ?? null,
        ]);
        $this->campaignToSaleAttributionService->recordSaleAttribution($car, [
            'sold_at' => $soldAt,
            'sale_price' => $context['sale_price'] ?? null,
        ]);

        if (empty($context['skip_notification']) && (!$wasAlreadySold || !empty($context['force_notification']))) {
            $this->sendSoldNotification($car);
        }
    }

    private function extractCarData(array $data, int $companyId): array
    {
        return [
            'status' => 'sold',
            'sold_at' => $data['sold_at'] ?? now(),
            'origin' => $data['origin'],
            'license_plate' => $data['license_plate'] ?? null,
            'vin' => $data['vin'] ?? null,
            'registration_month' => $data['registration_month'] ?? null,
            'registration_year' => $data['registration_year'],
            'car_brand_id' => $data['car_brand_id'],
            'car_model_id' => $data['car_model_id'],
            'version' => $data['version'],
            'public_version_name' => $data['public_version_name'] ?? null,
            'fuel_type' => $data['fuel_type'],
            'power_hp' => $data['power_hp'],
            'engine_capacity_cc' => $data['engine_capacity_cc'],
            'doors' => $data['doors'],
            'transmission' => $data['transmission'],
            'segment' => $data['segment'],
            'seats' => $data['seats'],
            'exterior_color' => $data['exterior_color'],
            'is_metallic' => $data['is_metallic'] ?? false,
            'interior_color' => $data['interior_color'] ?? null,
            'condition' => $data['condition'],
            'mileage_km' => $data['mileage_km'] ?? null,
            'co2_emissions' => $data['co2_emissions'] ?? null,
            'toll_class' => $data['toll_class'] ?? null,
            'cylinders' => $data['cylinders'] ?? null,
            'warranty_available' => $data['warranty_available'] ?? null,
            'warranty_due_date' => $data['warranty_due_date'] ?? null,
            'warranty_km' => $data['warranty_km'] ?? null,
            'service_records' => $data['service_records'] ?? null,
            'has_spare_key' => $data['has_spare_key'] ?? false,
            'has_manuals' => $data['has_manuals'] ?? false,
            'price_gross' => $data['price_gross'] ?? null,
            'promo_price_gross' => $data['promo_price_gross'] ?? null,
            'price_net' => $data['price_net'] ?? null,
            'hide_price_online' => $data['hide_price_online'] ?? false,
            'monthly_payment' => $data['monthly_payment'] ?? null,
            'extras' => $data['extras'] ?? [],
            'lifestyle' => $data['lifestyle'] ?? null,
            'description_website_pt' => $data['description_website_pt'] ?? null,
            'description_website_en' => $data['description_website_en'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
            'youtube_url' => $data['youtube_url'] ?? null,
            'images' => $data['images'] ?? [],
            'existing_images' => $data['existing_images'] ?? [],
            'images_meta' => $data['images_meta'] ?? [],
            'existing_images_meta' => $data['existing_images_meta'] ?? [],
            'exterior_360_images' => $data['exterior_360_images'] ?? [],
            'exterior_360_meta' => $data['exterior_360_meta'] ?? [],
            'company_id' => $companyId,
        ];
    }

    private function extractSaleData(array $data, int $companyId, int $carId): array
    {
        // Fecho da venda — mesmo gate de consentimento da edição (applyBuyerConsentGate).
        return $this->applyBuyerConsentGate([
            'car_id' => $carId,
            'company_id' => $companyId,
            'customer_id' => $data['customer_id'] ?? null, // DMS — cliente da venda
            'sale_price' => $data['sale_price'] ?? null,
            'buyer_gender' => $data['buyer_gender'],
            'buyer_age_range' => $data['buyer_age_range'],
            'sale_channel' => $data['sale_channel'],
            'buyer_name' => $data['buyer_name'] ?? null,
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'buyer_email' => $data['buyer_email'] ?? null,
            'contact_consent' => (bool) ($data['contact_consent'] ?? false),
            'notes' => $data['notes'] ?? null,
            'sold_at' => $data['sold_at'] ?? now(),
            // Fase 1 — registo de venda enriquecido (tudo opcional/nullable).
            'advertised_price' => $data['advertised_price'] ?? null,
            'discount_amount' => $data['discount_amount'] ?? null,
            'offers' => $data['offers'] ?? null,
            'has_financing' => $data['has_financing'] ?? null,
            'financing_entity' => $data['financing_entity'] ?? null,
            'financed_amount' => $data['financed_amount'] ?? null,
            'has_trade_in' => $data['has_trade_in'] ?? null,
            'trade_in_vehicle' => $data['trade_in_vehicle'] ?? null,
            'trade_in_value' => $data['trade_in_value'] ?? null,
            'first_motorhome' => $data['first_motorhome'] ?? null,
            'previous_vehicle' => $data['previous_vehicle'] ?? null,
        ]);
    }

    /**
     * RGPD — gate de consentimento ÚNICO, partilhado pelo fecho (extractSaleData)
     * e pela edição (updateSale). Sem `contact_consent`, a PII de contacto do
     * comprador NÃO é gravada. Só actua quando a chave `contact_consent` está
     * presente no payload (permite updates parciais que não mexem no consentimento).
     */
    private function applyBuyerConsentGate(array $sale): array
    {
        if (array_key_exists('contact_consent', $sale) && ! (bool) $sale['contact_consent']) {
            $sale['buyer_name'] = null;
            $sale['buyer_phone'] = null;
            $sale['buyer_email'] = null;
        }

        return $sale;
    }

    private function fillTimesToSale(Car $car, Carbon $soldAt): void
    {
        $publishedAt = Carbon::parse($car->created_at);
        $days = (int) $publishedAt->diffInDays($soldAt);

        CarPerformanceMetric::where('car_id', $car->id)
            ->whereNull('time_to_sale_days')
            ->update(['time_to_sale_days' => $days]);
    }

    private function sendSoldNotification(Car $car): void
    {
        $car->loadMissing(['company', 'brand', 'model']);

        Mail::to('simonfrtd@gmail.com')->send(new CarSoldNotificationMail($car));
    }
}
