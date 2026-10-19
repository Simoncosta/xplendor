<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * XPLENDOR — Auditoria: o âmbito de uma corrida (a área, o ecrã de origem e as regras) e uma
 * memória partilhada pelas regras da mesma corrida (os dados do espelho carregam-se uma vez).
 */
final class AuditScope
{
    /** @var array<string, mixed> */
    private array $memo = [];

    /** A empresa da corrida em curso: as regras leem as definições dela (severidades, alvos). */
    private static ?int $current = null;

    /** @param list<string> $rules */
    public function __construct(
        public readonly int $companyId,
        public readonly string $area,
        public readonly string $origin,
        public readonly array $rules = [],
    ) {
        self::$current = $companyId;
    }

    public static function currentCompany(): ?int
    {
        return self::$current;
    }

    /** Severidade pelo € em jogo com os limites da empresa da corrida (Configurações › Restauração e Auditoria). */
    public static function severityFor(float $eur, string $area): string
    {
        $c = self::$current;
        $high = (float) \App\Settings\Settings::company($c, "audit.{$area}_severity_high");
        $medium = (float) \App\Settings\Settings::company($c, "audit.{$area}_severity_medium");

        return $eur >= $high ? 'alta' : ($eur >= $medium ? 'media' : 'baixa');
    }

    public function key(): string
    {
        return "{$this->area}:{$this->origin}";
    }

    /**
     * @template T
     * @param  callable(): T  $make
     * @return T
     */
    public function memo(string $key, callable $make): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $make();
        }

        return $this->memo[$key];
    }
}
