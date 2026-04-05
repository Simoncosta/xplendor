<?php

namespace App\Services\Ads;

use App\Models\Car;

class InterestFilteringService
{
    private const MAX_RESULTS = 6;

    private const BLOCKED_KEYWORDS = [
        'birthday',
        'anniversary',
        'friends of',
        'relationship',
        'behavior',
        'device',
        'mobile device',
        'facebook access',
        'operating system',
        'engaged shoppers',
        'digital activities',
    ];

    private const GENERIC_PENALTIES = [
        'facebook',
        'internet',
        'smartphones',
        'social media',
    ];

    private const AUTOMOTIVE_KEYWORDS = [
        'carro',
        'carros',
        'automovel',
        'automoveis',
        'veiculo',
        'veiculos',
        'electric car',
        'electric cars',
        'carros eletricos',
        'carros electricos',
        'urban car',
        'urban cars',
        'suv',
    ];

    private const LIFESTYLE_KEYWORDS = [
        'mobilidade urbana',
        'familia',
        'viagens',
        'eficiencia',
        'economia',
    ];

    public function filterAndRank(array $interests, Car $car): array
    {
        $car->loadMissing(['brand', 'model']);

        $brand = $this->normalize((string) ($car->brand?->name ?? ''));
        $model = $this->normalize((string) ($car->model?->name ?? ''));
        $modelTokens = $this->extractTokens($model);

        return collect($interests)
            ->filter(fn($interest) => is_array($interest) && !empty($interest['meta_id']) && !empty($interest['name']))
            ->map(function (array $interest) use ($brand, $model, $modelTokens) {
                $name = trim((string) $interest['name']);
                $normalizedName = $this->normalize($name);

                return [
                    ...$interest,
                    'name' => $name,
                    '_normalized_name' => $normalizedName,
                    '_score' => $this->scoreInterest($normalizedName, $brand, $model, $modelTokens),
                ];
            })
            ->filter(fn(array $interest) => !$this->isBlocked($interest['_normalized_name']))
            ->sortByDesc('_score')
            ->unique(fn(array $interest) => $interest['meta_id'] . '|' . $interest['_normalized_name'])
            ->filter(fn(array $interest) => $interest['_score'] > 0)
            ->take(self::MAX_RESULTS)
            ->map(fn(array $interest) => [
                'meta_id' => $interest['meta_id'],
                'name' => $interest['name'],
                'audience_size' => $interest['audience_size'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function scoreInterest(string $normalizedName, string $brand, string $model, array $modelTokens): int
    {
        $score = 0;

        if ($brand !== '' && str_contains($normalizedName, $brand)) {
            $score += 40;
        }

        if ($model !== '' && str_contains($normalizedName, $model)) {
            $score += 30;
        } elseif ($this->containsMeaningfulModelToken($normalizedName, $modelTokens)) {
            $score += 18;
        }

        if ($this->containsAny($normalizedName, self::AUTOMOTIVE_KEYWORDS)) {
            $score += 25;
        }

        if ($this->containsAny($normalizedName, self::LIFESTYLE_KEYWORDS)) {
            $score += 15;
        }

        if ($this->containsAny($normalizedName, self::GENERIC_PENALTIES)) {
            $score -= 50;
        }

        return max(0, min(100, $score));
    }

    private function containsMeaningfulModelToken(string $normalizedName, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }

            if (ctype_digit($token) || mb_strlen($token) >= 3) {
                if (preg_match('/(^|[^[:alnum:]])' . preg_quote($token, '/') . '([^[:alnum:]]|$)/u', $normalizedName)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isBlocked(string $normalizedName): bool
    {
        return $this->containsAny($normalizedName, self::BLOCKED_KEYWORDS);
    }

    private function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $this->normalize($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function extractTokens(string $value): array
    {
        return collect(preg_split('/[^[:alnum:]]+/u', $value) ?: [])
            ->map(fn($token) => trim((string) $token))
            ->filter()
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));

        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ];

        return strtr($value, $map);
    }
}
