<?php

declare(strict_types=1);

namespace App\Services\Compass;

use App\Services\CompanyModuleService;
use App\Services\Compass\Stand\StandCompassProvider;
use App\Services\Restaurant\RestaurantCompassProvider;

/**
 * Escolhe o fornecedor da Bússola pelos módulos da empresa: o PingWin dá a restauração, o
 * stock dá o stand. Uma empresa com os dois tem as duas (a restauração primeiro).
 */
final class CompassProviders
{
    /** @var list<class-string<CompassProvider>> pela ordem de preferência */
    public const PROVIDERS = [RestaurantCompassProvider::class, StandCompassProvider::class, \App\Services\Compass\Imoveis\ImoveisCompassProvider::class];

    public function __construct(private readonly CompanyModuleService $modules) {}

    /** @return list<CompassProvider> os fornecedores que a empresa tem (pelos módulos). */
    public function forCompany(int $companyId): array
    {
        $out = [];
        foreach (self::PROVIDERS as $class) {
            $provider = app($class);
            if ($this->modules->isEnabled($companyId, $provider->module())) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    public function byKey(string $key): ?CompassProvider
    {
        foreach (self::PROVIDERS as $class) {
            $provider = app($class);
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }
}
