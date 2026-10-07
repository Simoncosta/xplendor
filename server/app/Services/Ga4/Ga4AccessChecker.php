<?php

declare(strict_types=1);

namespace App\Services\Ga4;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * "Verificar acesso" a uma propriedade GA4 com a conta de serviço da XPLENDOR: a chamada mais
 * barata (activeUsers dos últimos 7 dias). Sem dados não é erro de acesso. Os erros do Google
 * passam a uma mensagem simples; o detalhe técnico (sem a chave privada) fica só no registo.
 */
class Ga4AccessChecker
{
    public const OK = 'ok';
    public const PERMISSION = 'permission';
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly Ga4ClientInterface $client) {}

    /** @return array{ok: bool, kind: string, message: string} */
    public function check(int $propertyId): array
    {
        $end = CarbonImmutable::today();
        try {
            $this->client->runReport($propertyId, ['start' => $end->subDays(6)->toDateString(), 'end' => $end->toDateString(), 'metrics' => ['activeUsers']]);

            return ['ok' => true, 'kind' => self::OK, 'message' => 'Acesso confirmado: a XPLENDOR já consegue ler esta propriedade.'];
        } catch (\Throwable $e) {
            $kind = self::classify($e->getMessage());
            Log::warning('[GA4] Verificação de acesso falhou', ['property_id' => $propertyId, 'kind' => $kind, 'error' => Ga4Redact::message($e->getMessage())]);

            return ['ok' => false, 'kind' => $kind, 'message' => self::message($kind)];
        }
    }

    public static function classify(string $raw): string
    {
        $m = strtolower($raw);

        return match (true) {
            str_contains($m, 'permission') || str_contains($m, 'denied') || str_contains($m, '403') => self::PERMISSION,
            str_contains($m, 'not found') || str_contains($m, '404') => self::NOT_FOUND,
            default => self::UNAVAILABLE,
        };
    }

    public static function message(string $kind): string
    {
        return match ($kind) {
            self::PERMISSION => 'A conta de serviço da XPLENDOR ainda não tem acesso a esta propriedade. Confirme que a adicionou como Visualizador e tente de novo dentro de alguns minutos (o acesso pode demorar a ficar ativo).',
            self::NOT_FOUND => 'Não foi encontrada nenhuma propriedade com este ID. Confirme que é o ID da propriedade (só números, em Administração, Detalhes da propriedade) e não o ID de medição "G-".',
            default => 'Não foi possível verificar o acesso neste momento. Tente novamente dentro de alguns minutos.',
        };
    }
}
