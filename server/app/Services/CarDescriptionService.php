<?php

namespace App\Services;

use App\Models\CarBrand;
use App\Models\CarModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CarDescriptionService
{
    private const OPENAI_TIMEOUT_SECONDS = 30;
    private const OPENAI_CONNECT_TIMEOUT_SECONDS = 10;

    public function generate(array $data): string
    {
        $data['brand_name'] = CarBrand::find($data['car_brand_id'])?->name ?? '';
        $data['model_name'] = CarModel::find($data['car_model_id'])?->name ?? '';

        $prompts = $this->buildPrompts($data);

        return trim($this->callOpenAi($prompts));
    }

    private function buildPrompts(array $data): array
    {
        $vehicleType    = $data['vehicle_type'] ?? 'car';
        $brand          = $data['brand_name'];
        $model          = $data['model_name'];
        $year           = $data['registration_year'] ?? '';
        $version        = $data['version'] ?? '';
        $priceGross     = isset($data['price_gross']) && $data['price_gross'] > 0
            ? number_format((float) $data['price_gross'], 0, ',', '.') . '€'
            : null;
        $promoPriceGross = isset($data['promo_price_gross'])
            && $data['promo_price_gross'] > 0
            && (float) $data['promo_price_gross'] < (float) ($data['price_gross'] ?? PHP_INT_MAX)
            ? number_format((float) $data['promo_price_gross'], 0, ',', '.') . '€'
            : null;

        $system = <<<SYSTEM
És um copywriter especializado em veículos para o mercado português.
Escreves descrições comerciais em Português de Portugal (não Brasil), diretas e objetivas.
Formato obrigatório: texto corrido, entre 80 a 150 palavras, sem bullet points, sem títulos, sem emojis.
Tom: comercial mas direto, focado nos argumentos de venda mais relevantes para o tipo de veículo.
Responde apenas com o texto da descrição, sem qualquer prefácio ou explicação adicional.
SYSTEM;

        $lines = ['Gera uma descrição comercial para o seguinte veículo:', ''];

        match ($vehicleType) {
            'car'       => $this->appendCarLines($lines, $data, $brand, $model, $year, $version, $priceGross, $promoPriceGross),
            'motorhome' => $this->appendMotorhomeLines($lines, $data, $brand, $model, $year, $version, $priceGross, $promoPriceGross),
            'caravan'   => $this->appendCaravanLines($lines, $data, $brand, $model, $year, $version, $priceGross, $promoPriceGross),
            default     => $this->appendGenericLines($lines, $data, $brand, $model, $year, $version, $priceGross, $promoPriceGross),
        };

        return ['system' => $system, 'user' => implode("\n", $lines)];
    }

    private function appendCarLines(array &$lines, array $d, string $brand, string $model, string|int $year, string $version, ?string $price, ?string $promo): void
    {
        $lines[] = 'Tipo: Automóvel';
        $lines[] = "Marca: {$brand}";
        $lines[] = "Modelo: {$model}";
        if ($version) $lines[] = "Versão: {$version}";
        $lines[] = "Ano: {$year}";
        if ($d['fuel_type'] ?? null)          $lines[] = "Combustível: {$d['fuel_type']}";
        if ($d['engine_capacity_cc'] ?? null) $lines[] = "Cilindrada: {$d['engine_capacity_cc']} cc";
        if ($d['power_hp'] ?? null)           $lines[] = "Potência: {$d['power_hp']} cv";
        if ($d['transmission'] ?? null)       $lines[] = "Caixa: {$d['transmission']}";
        if ($d['seats'] ?? null)              $lines[] = "Lugares: {$d['seats']}";
        if ($d['mileage_km'] ?? null)         $lines[] = 'Quilometragem: ' . number_format((int) $d['mileage_km'], 0, ',', '.') . ' km';
        if ($d['segment'] ?? null)            $lines[] = "Segmento: {$d['segment']}";
        if ($d['exterior_color'] ?? null)     $lines[] = "Cor: {$d['exterior_color']}";
        $this->appendExtras($lines, $d);
        $this->appendPrice($lines, $price, $promo);
    }

    private function appendMotorhomeLines(array &$lines, array $d, string $brand, string $model, string|int $year, string $version, ?string $price, ?string $promo): void
    {
        $lines[] = 'Tipo: Autocaravana';
        $lines[] = "Marca: {$brand}";
        $lines[] = "Modelo: {$model}";
        if ($version) $lines[] = "Versão: {$version}";
        $lines[] = "Ano: {$year}";
        if ($d['engine_capacity_cc'] ?? null) $lines[] = "Cilindrada: {$d['engine_capacity_cc']} cc";
        if ($d['power_hp'] ?? null)           $lines[] = "Potência: {$d['power_hp']} cv";
        if ($d['transmission'] ?? null)       $lines[] = "Caixa: {$d['transmission']}";
        if ($d['mileage_km'] ?? null)         $lines[] = 'Quilometragem: ' . number_format((int) $d['mileage_km'], 0, ',', '.') . ' km';
        if ($d['subsegment'] ?? null)         $lines[] = "Tipo de carroçaria: {$d['subsegment']}";

        $attrs = $d['vehicle_attributes'] ?? [];
        if (!empty($attrs['beds'])) {
            $bedCount = count((array) $attrs['beds']);
            $bedTypes = collect($attrs['beds'])->pluck('type')->filter()->implode(', ');
            $lines[] = "Camas: {$bedCount}" . ($bedTypes ? " ({$bedTypes})" : '');
        }
        if (!empty($attrs['has_bathroom'])) $lines[] = 'Casa de banho: Sim';
        if (!empty($attrs['has_kitchen']))  $lines[] = 'Cozinha: Sim';
        if ($attrs['gross_weight'] ?? null) $lines[] = "Peso bruto: {$attrs['gross_weight']} kg";

        $this->appendExtras($lines, $d);
        $this->appendPrice($lines, $price, $promo);
    }

    private function appendCaravanLines(array &$lines, array $d, string $brand, string $model, string|int $year, string $version, ?string $price, ?string $promo): void
    {
        $lines[] = 'Tipo: Caravana';
        $lines[] = "Marca: {$brand}";
        $lines[] = "Modelo: {$model}";
        if ($version) $lines[] = "Versão: {$version}";
        $lines[] = "Ano: {$year}";

        $attrs = $d['vehicle_attributes'] ?? [];
        if (!empty($attrs['beds'])) {
            $bedCount = count((array) $attrs['beds']);
            $lines[] = "Lugares de dormir: {$bedCount}";
        }
        if (!empty($attrs['has_bathroom'])) $lines[] = 'Casa de banho: Sim';
        if (!empty($attrs['has_kitchen']))  $lines[] = 'Cozinha: Sim';
        if ($attrs['gross_weight'] ?? null) $lines[] = "Peso: {$attrs['gross_weight']} kg";
        if ($attrs['length'] ?? null)       $lines[] = "Comprimento: {$attrs['length']} m";

        $this->appendExtras($lines, $d);
        $this->appendPrice($lines, $price, $promo);
    }

    private function appendGenericLines(array &$lines, array $d, string $brand, string $model, string|int $year, string $version, ?string $price, ?string $promo): void
    {
        $lines[] = "Marca: {$brand}";
        $lines[] = "Modelo: {$model}";
        if ($version) $lines[] = "Versão: {$version}";
        $lines[] = "Ano: {$year}";
        if ($d['fuel_type'] ?? null)          $lines[] = "Combustível: {$d['fuel_type']}";
        if ($d['engine_capacity_cc'] ?? null) $lines[] = "Cilindrada: {$d['engine_capacity_cc']} cc";
        if ($d['power_hp'] ?? null)           $lines[] = "Potência: {$d['power_hp']} cv";
        if ($d['transmission'] ?? null)       $lines[] = "Caixa: {$d['transmission']}";
        if ($d['mileage_km'] ?? null)         $lines[] = 'Quilometragem: ' . number_format((int) $d['mileage_km'], 0, ',', '.') . ' km';
        $this->appendExtras($lines, $d);
        $this->appendPrice($lines, $price, $promo);
    }

    private function appendExtras(array &$lines, array $d): void
    {
        $extras = $d['extras'] ?? [];
        $items  = [];

        foreach ($extras as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ($item) $items[] = $item;
            }
        }

        if (!empty($items)) {
            $lines[] = 'Equipamentos/Extras: ' . implode(', ', array_slice($items, 0, 20));
        }
    }

    private function appendPrice(array &$lines, ?string $price, ?string $promo): void
    {
        if ($promo && $price) {
            $lines[] = "Preço original: {$price}";
            $lines[] = "Preço promocional: {$promo} (promoção ativa — menciona a promoção na descrição)";
        } elseif ($price) {
            $lines[] = "Preço: {$price}";
        }
    }

    private function callOpenAi(array $prompts): string
    {
        $apiKey = config('services.openai.key');

        $response = Http::withToken($apiKey)
            ->connectTimeout(self::OPENAI_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::OPENAI_TIMEOUT_SECONDS)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => 'gpt-4o',
                'temperature' => 0.5,
                'max_tokens'  => 400,
                'messages'    => [
                    ['role' => 'system', 'content' => $prompts['system']],
                    ['role' => 'user',   'content' => $prompts['user']],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('CarDescriptionService: OpenAI request failed', [
                'status_code'   => $response->status(),
                'response_body' => substr($response->body(), 0, 500),
            ]);
            $response->throw();
        }

        return $response->json('choices.0.message.content', '');
    }
}
