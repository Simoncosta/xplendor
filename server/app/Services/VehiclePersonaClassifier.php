<?php

namespace App\Services;

use App\Models\Car;

class VehiclePersonaClassifier
{
    public const CAR_PERSONAS = [
        'status_seeker',
        'practical_buyer',
        'family_driver',
        'tech_enthusiast',
        'budget_hunter',
    ];

    public const MOTORHOME_PERSONAS = [
        'traveler',
        'couple',
        'family',
        'digital_nomad',
        'adventurer',
    ];

    public function classify(Car $car): string
    {
        return $car->vehicle_type === 'motorhome'
            ? $this->classifyMotorhomePersona($car)
            : $this->classifyCarPersona($car);
    }

    public function classifyCarPersona(Car $car): string
    {
        $price = (float) ($car->price_gross ?? 0);
        $segment = strtolower((string) ($car->segment ?? ''));
        $fuel = strtolower((string) ($car->fuel_type ?? ''));
        $version = strtolower((string) ($car->version ?? ''));

        if (
            $price >= 35000
            || $this->containsAny($version, ['amg', 'm ', 'm-sport', 'm sport', 'rs', 's line', 'quattro', 'gti', 'gt line', 'vignale'])
        ) {
            return 'status_seeker';
        }

        if ($fuel === 'electric' || $this->containsAny($fuel, ['plug-in hybrid', 'phev', 'hybrid', 'híbrido', 'hibrido'])) {
            return 'tech_enthusiast';
        }

        if (in_array($segment, ['station_wagon', 'suv', 'suv_tt', 'mpv'], true)) {
            return 'family_driver';
        }

        if ($price > 0 && $price <= 15000) {
            return 'budget_hunter';
        }

        return 'practical_buyer';
    }

    public function classifyMotorhomePersona(Car $car): string
    {
        $price = (float) ($car->price_gross ?? 0);
        $segment = strtolower((string) ($car->segment ?? ''));
        $version = strtolower((string) ($car->version ?? ''));
        $attributes = $this->vehicleAttributes($car);

        $beds = $this->extractNumericAttribute($attributes, [
            'beds',
            'sleeping_places',
            'sleeping_positions',
            'berths',
            'number_of_beds',
            'dormidas',
            'lugares_dormida',
            'camas',
        ]);

        $length = $this->extractNumericAttribute($attributes, [
            'length',
            'vehicle_length',
            'overall_length',
            'comprimento',
            'length_cm',
            'length_m',
        ]);

        $hasWorkspaceSignal = $this->containsAny($version, ['office', 'remote', 'work', 'nomad', 'business'])
            || $this->containsAttributeText($attributes, ['workspace', 'desk', 'office', 'remote', 'usb', 'inverter', 'solar']);

        $adventureSignal = $this->containsAny($segment, ['4x4', 'offroad', 'off-road'])
            || $this->containsAny($version, ['4x4', 'offroad', 'off-road', 'adventure'])
            || $this->containsAttributeText($attributes, ['solar', 'bike', 'outdoor', 'offgrid', 'off-grid', 'all terrain']);

        if ($hasWorkspaceSignal && $price >= 45000) {
            return 'digital_nomad';
        }

        if ($beds !== null && $beds >= 4) {
            return 'family';
        }

        if (
            ($beds !== null && $beds <= 2)
            || ($length !== null && $length > 0 && $length <= 620)
        ) {
            return 'couple';
        }

        if ($adventureSignal) {
            return 'adventurer';
        }

        if ($price >= 55000 && $hasWorkspaceSignal) {
            return 'digital_nomad';
        }

        return 'traveler';
    }

    private function vehicleAttributes(Car $car): array
    {
        if (is_array($car->vehicle_attributes ?? null)) {
            return $car->vehicle_attributes;
        }

        if ($car->relationLoaded('vehicleAttribute') && is_array($car->vehicleAttribute?->attributes)) {
            return $car->vehicleAttribute->attributes;
        }

        return is_array($car->vehicleAttribute()->first()?->attributes)
            ? $car->vehicleAttribute()->first()->attributes
            : [];
    }

    private function extractNumericAttribute(array $attributes, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->findAttributeValue($attributes, $key);

            if ($value === null) {
                continue;
            }

            if (is_numeric($value)) {
                return (float) $value;
            }

            if (is_array($value) && $key === 'beds') {
                return (float) count($value);
            }

            if (is_string($value) && preg_match('/(\d+[.,]?\d*)/', $value, $matches)) {
                return (float) str_replace(',', '.', $matches[1]);
            }
        }

        return null;
    }

    private function findAttributeValue(array $attributes, string $targetKey): mixed
    {
        $normalizedTarget = $this->normalize((string) $targetKey);

        foreach ($attributes as $key => $value) {
            if ($this->normalize((string) $key) === $normalizedTarget) {
                return $value;
            }

            if (is_array($value)) {
                $label = $value['label'] ?? $value['name'] ?? null;

                if ($label !== null && $this->normalize((string) $label) === $normalizedTarget) {
                    return $value['value'] ?? $value['label_value'] ?? null;
                }
            }
        }

        return null;
    }

    private function containsAttributeText(array $attributes, array $needles): bool
    {
        $haystack = $this->normalize(json_encode($attributes, JSON_UNESCAPED_UNICODE));

        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function containsAny(string $value, array $needles): bool
    {
        $normalizedValue = $this->normalize($value);

        foreach ($needles as $needle) {
            if (str_contains($normalizedValue, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        $value = trim((string) $value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
