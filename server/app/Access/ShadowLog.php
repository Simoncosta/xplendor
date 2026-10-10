<?php

declare(strict_types=1);

namespace App\Access;

use Illuminate\Support\Facades\Log;

/**
 * ACL em modo sombra: as divergências entre a decisão do Access e a resposta de hoje.
 *  · perda: o Access recusa, mas hoje a rota respondeu 2xx (a pessoa perderia o acesso);
 *  · excesso: o Access permite, mas hoje a rota respondeu 403 (a F3 daria acesso a mais);
 *  · inconclusivo: o Access recusa e a resposta foi outra (404, 422…): não se sabe se hoje
 *    a rota recusaria; não é divergência, mas fica contado.
 * Ficam em memória durante o processo (para os testes) e as divergências vão para o registo.
 */
final class ShadowLog
{
    /** @var array<int, array{tipo: string, rota: string, permissao: string, estado: int, utilizador: ?int, empresa: int, motivo: ?string}> */
    public static array $entries = [];

    public static function record(string $type, string $route, string $permission, int $status, ?int $userId, int $companyId, ?string $reason): void
    {
        $entry = ['tipo' => $type, 'rota' => $route, 'permissao' => $permission, 'estado' => $status, 'utilizador' => $userId, 'empresa' => $companyId, 'motivo' => $reason];
        self::$entries[] = $entry;
        if ($type !== 'inconclusivo') {
            Log::warning('[ACL sombra] divergência', $entry);
        }
    }

    /** @return array<int, array> */
    public static function divergences(): array
    {
        return array_values(array_filter(self::$entries, fn ($e) => $e['tipo'] !== 'inconclusivo'));
    }

    /** Nos testes: cada decisão do middleware e o estado da resposta (o varrimento da F3). @var array<int, array{rota: string, permitido: bool, estado: int, motivo: ?string}> */
    public static array $decisions = [];

    public static function decision(string $route, bool $allowed, int $status, ?string $reason): void
    {
        self::$decisions[] = ['rota' => $route, 'permitido' => $allowed, 'estado' => $status, 'motivo' => $reason];
    }

    public static function reset(): void
    {
        self::$entries = [];
        self::$decisions = [];
    }
}
