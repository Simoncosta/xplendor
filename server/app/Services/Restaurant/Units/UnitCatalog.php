<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

use App\Models\PingwinUnit;
use Illuminate\Support\Str;

/**
 * XPLENDOR — Auditoria v2.1: o catálogo das unidades GLOBAIS do PingWin (partilhadas por todos os
 * artigos; o PingWin não deixa mudar a quantidade de uma unidade que já existe).
 *  · Antes de criar, procura uma unidade global ativa com a mesma unidade base e a mesma quantidade
 *    (e um nome que não diga outra coisa): se existir, reutiliza-a.
 *  · Só cria se não existir, com o nome "<Embalagem> <N> <base>" ("Caixa 6 un", "Grade 24 un",
 *    "Caixa 180 un", "Saco 5 kg", "Bidon 30 L"). Nunca cria duplicados: se o nome já estiver tomado
 *    por uma unidade com outra quantidade, usa "<Embalagem> de <N> <base>".
 *  · Nunca anula nem altera uma unidade global.
 */
final class UnitCatalog
{
    private const CONTAINER = [
        'caixa' => 'Caixa', 'grade' => 'Grade', 'saco' => 'Saco', 'bidon' => 'Bidon', 'barril' => 'Barril', 'pack' => 'Pack',
        'balde' => 'Balde', 'pacote' => 'Pacote', 'frasco' => 'Frasco', 'garrafa' => 'Garrafão', 'lata' => 'Caixa',
    ];

    /** @param array<string, PingwinUnit> $units as unidades do espelho, por pingwin_id */
    public function __construct(private array $units) {}

    public static function forCompany(int $companyId): self
    {
        return new self(PingwinUnit::where('company_id', $companyId)->get()->keyBy(fn ($u) => (string) $u->pingwin_id)->all());
    }

    /** "un" | "kg" | "L": o sufixo do nome pela família da unidade base. */
    public static function baseSuffix(?string $family): string
    {
        return ['kg' => 'kg', 'L' => 'L'][$family] ?? 'un';
    }

    /** O nome de uma unidade nova: "Caixa 24 un", "Saco 5 kg", "Bidon 30 L". */
    public static function name(?string $container, float $n, ?string $family): string
    {
        $c = self::CONTAINER[$container ?? ''] ?? 'Caixa';
        $qty = rtrim(rtrim(number_format($n, 3, ',', ''), '0'), ',');

        return "{$c} {$qty} " . self::baseSuffix($family);
    }

    /** A quantidade de uma unidade em unidades da base (unit_value ao longo dos pais). */
    public function factor(string $unitId): ?float
    {
        $f = 1.0;
        $id = $unitId;
        for ($i = 0; $i < 6 && $id !== ''; $i++) {
            $u = $this->units[$id] ?? null;
            if (! $u) {
                return null;
            }
            if (! $u->parent_pingwin_id) {
                return $f;
            }
            $f *= (float) ($u->unit_value ?: 1);
            $id = (string) $u->parent_pingwin_id;
        }

        return null;
    }

    /** A unidade de topo de uma unidade (a base: Unidade, Quilograma, Litro). */
    public function root(string $unitId): ?string
    {
        $id = $unitId;
        for ($i = 0; $i < 6 && $id !== ''; $i++) {
            $u = $this->units[$id] ?? null;
            if (! $u) {
                return null;
            }
            if (! $u->parent_pingwin_id) {
                return $id;
            }
            $id = (string) $u->parent_pingwin_id;
        }

        return null;
    }

    public static function isGlobal(PingwinUnit $u): bool
    {
        return (string) ($u->product_pingwin_id ?? '') === '';
    }

    /**
     * A quantidade que o nome da unidade diz, na família da base ("Caixa 24 Unidades" → 24; "Saco 5kg"
     * → 5; "Saco 250gr" → 0,25), ou null se o nome não diz quantidade.
     */
    public static function nameQuantity(string $name, ?string $family): ?float
    {
        $p = PackagingParser::parse($name);
        if (! $p || $p['quantity'] <= 0) {
            return null;
        }
        if ($family === null || $p['family'] === $family) {
            return (float) $p['quantity'];
        }
        // Uma caixa de N peças numa base contável ("Caixa 24 × 0,33 L" numa base em unidades = 24).
        return $family === 'un' && $p['count'] > 1 ? (float) $p['count'] : null;
    }

    /** A unidade global que se pode reutilizar: ativa, filha desta base, com a quantidade certa e um nome que não diga outra. */
    public function find(string $baseId, float $n, ?string $family): ?PingwinUnit
    {
        $best = null;
        foreach ($this->units as $id => $u) {
            if (! $u->is_active || ! self::isGlobal($u) || (string) $u->parent_pingwin_id !== $baseId) {
                continue;
            }
            $f = $this->factor((string) $id);
            if ($f === null || abs($f - $n) > 1e-6 * max(1, $n)) {
                continue;
            }
            $says = self::nameQuantity((string) $u->description, $family);
            if ($says !== null && abs($says - $n) > 1e-6 * max(1, $n)) {
                continue; // o nome diz outra quantidade: não se reutiliza (seria trocar um erro por outro)
            }
            // Entre várias, a que tem o nome a dizer a quantidade (mais clara); depois a mais antiga.
            if (! $best || ($says !== null && self::nameQuantity((string) $best->description, $family) === null)) {
                $best = $u;
            }
        }

        return $best;
    }

    /**
     * Reutilizar ou criar a unidade certa: ['reuse' => PingwinUnit] ou ['create' => ['description', 'parent_qnt']].
     *
     * @return array{reuse?: PingwinUnit, create?: array{description: string, parent_qnt: float}, name: string}
     */
    public function resolve(string $baseId, float $n, ?string $family, ?string $container): array
    {
        if ($u = $this->find($baseId, $n, $family)) {
            return ['reuse' => $u, 'name' => (string) $u->description];
        }
        $name = self::name($container, $n, $family);
        if ($this->nameTaken($name)) {
            $name = preg_replace('/^(\S+) /u', '$1 de ', $name, 1) ?? $name;
        }

        return ['create' => ['description' => mb_substr($name, 0, 40), 'parent_qnt' => $n], 'name' => $name];
    }

    public function nameTaken(string $name): bool
    {
        $k = self::key($name);
        foreach ($this->units as $u) {
            if (self::key((string) $u->description) === $k) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $s): string
    {
        return preg_replace('/\s+/', ' ', Str::lower(Str::ascii(trim($s)))) ?? '';
    }
}
