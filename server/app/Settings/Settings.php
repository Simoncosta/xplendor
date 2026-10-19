<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\CompanySetting;
use App\Models\PlatformSetting;
use App\Models\SettingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Configurações: ler e gravar as definições do catálogo (SettingsCatalog).
 *
 * Ler: o valor na base de dados (por empresa ou da plataforma, em cache) > a variável antiga do .env
 * (pela chave de config) > o valor por defeito. Gravar: valida, guarda e regista o histórico (quem,
 * quando, antes e depois) e limpa a cache.
 */
final class Settings
{
    private const TTL = 3600;

    /** O valor de uma definição da empresa (níveis empresa e empresa_root). */
    public static function company(?int $companyId, string $key): mixed
    {
        $def = SettingsCatalog::get($key);
        if ($companyId) {
            $stored = self::companyValues($companyId);
            if (array_key_exists($key, $stored)) {
                return self::cast($def, $stored[$key]);
            }
        }

        return self::fallback($def);
    }

    /** Se a empresa tem a definição gravada na base de dados (sem contar o .env nem o defeito). */
    public static function companyHas(int $companyId, string $key): bool
    {
        return array_key_exists($key, self::companyValues($companyId));
    }

    /** O valor de uma definição da plataforma. */
    public static function platform(string $key): mixed
    {
        $def = SettingsCatalog::get($key);
        $stored = self::platformValues();

        return array_key_exists($key, $stored) ? self::cast($def, $stored[$key]) : self::fallback($def);
    }

    /** De onde vem o valor: "base de dados", ".env" ou "defeito". */
    public static function source(?int $companyId, string $key): string
    {
        $def = SettingsCatalog::get($key);
        $stored = $def['level'] === SettingsCatalog::PLATFORM ? self::platformValues() : ($companyId ? self::companyValues($companyId) : []);
        if (array_key_exists($key, $stored)) {
            return 'base_de_dados';
        }

        // Com a config em cache, env() devolve null: compara-se o valor da config com o defeito.
        return $def['config'] && self::cast($def, config($def['config'], $def['default'])) != self::cast($def, $def['default']) ? 'env' : 'defeito';
    }

    /** @return array<string, mixed> os valores gravados da empresa (cache) */
    public static function companyValues(int $companyId): array
    {
        return Cache::remember(self::companyCacheKey($companyId), self::TTL, fn () => CompanySetting::where('company_id', $companyId)->get()
            ->mapWithKeys(fn ($s) => [$s->key => $s->value])->all());
    }

    /** @return array<string, mixed> */
    public static function platformValues(): array
    {
        return Cache::remember('settings:platform', self::TTL, fn () => PlatformSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all());
    }

    /**
     * Grava várias definições da empresa de uma vez (só as que mudam), com o histórico.
     *
     * @param  array<string, mixed>  $values
     * @return list<string> as chaves que mudaram
     */
    public static function setCompany(int $companyId, array $values, ?User $user, ?string $reason = null): array
    {
        $changed = [];
        DB::transaction(function () use ($companyId, $values, $user, $reason, &$changed) {
            foreach ($values as $key => $value) {
                $def = SettingsCatalog::get((string) $key);
                if ($def['level'] === SettingsCatalog::PLATFORM) {
                    throw new \InvalidArgumentException("{$key} é uma definição da plataforma.");
                }
                $value = self::validate($def, $value);
                $before = self::company($companyId, $key);
                $row = CompanySetting::firstOrNew(['company_id' => $companyId, 'key' => $key]);
                // Só o que muda: igual ao valor em vigor (gravado, do .env ou o defeito) não grava nem fica no histórico.
                if ($before == $value) {
                    continue;
                }
                $row->value = $value;
                $row->updated_by = $user?->id;
                $row->save();
                SettingEvent::create(['scope' => $def['level'], 'company_id' => $companyId, 'key' => $key, 'before' => $before, 'after' => $value,
                    'user_id' => $user?->id, 'reason' => $reason]);
                $changed[] = (string) $key;
            }
        });
        Cache::forget(self::companyCacheKey($companyId));

        return $changed;
    }

    /** @param array<string, mixed> $values */
    public static function setPlatform(array $values, ?User $user, ?string $reason = null): array
    {
        $changed = [];
        DB::transaction(function () use ($values, $user, $reason, &$changed) {
            foreach ($values as $key => $value) {
                $def = SettingsCatalog::get((string) $key);
                if ($def['level'] !== SettingsCatalog::PLATFORM) {
                    throw new \InvalidArgumentException("{$key} não é uma definição da plataforma.");
                }
                $value = self::validate($def, $value);
                $before = self::platform($key);
                $row = PlatformSetting::firstOrNew(['key' => $key]);
                if ($before == $value) {
                    continue;
                }
                $row->value = $value;
                $row->updated_by = $user?->id;
                $row->save();
                SettingEvent::create(['scope' => SettingsCatalog::PLATFORM, 'company_id' => null, 'key' => $key, 'before' => $before, 'after' => $value,
                    'user_id' => $user?->id, 'reason' => $reason]);
                $changed[] = (string) $key;
            }
        });
        Cache::forget('settings:platform');

        return $changed;
    }

    /** O histórico (o mais recente primeiro). */
    public static function history(?int $companyId, array $levels, int $limit = 100): array
    {
        return SettingEvent::with('user:id,name')->whereIn('scope', $levels)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->orderByDesc('id')->limit($limit)->get()->map(fn ($e) => [
                'key' => $e->key, 'label' => SettingsCatalog::all()[$e->key]['label'] ?? $e->key, 'before' => $e->before, 'after' => $e->after,
                'user' => $e->user?->name, 'reason' => $e->reason, 'at' => $e->created_at?->toIso8601String(),
            ])->all();
    }

    /** As definições de um nível para o ecrã: valor, origem, tipo, secção, rótulo e InfoTip. */
    public static function describe(?int $companyId, string $level): array
    {
        $out = [];
        foreach (SettingsCatalog::keys($level) as $key) {
            $d = SettingsCatalog::get($key);
            $out[] = [
                'key' => $key, 'section' => $d['section'] ?? null, 'type' => $d['type'], 'label' => $d['label'], 'info' => $d['info'],
                'min' => $d['min'] ?? null, 'max' => $d['max'] ?? null, 'owner' => (bool) ($d['owner'] ?? false), 'consent' => $d['consent'] ?? null,
                'value' => $level === SettingsCatalog::PLATFORM ? self::platform($key) : self::company($companyId, $key),
                'default' => $d['default'], 'source' => self::source($companyId, $key),
            ];
        }

        return $out;
    }

    public static function flush(?int $companyId = null): void
    {
        Cache::forget('settings:platform');
        if ($companyId) {
            Cache::forget(self::companyCacheKey($companyId));
        }
    }

    private static function companyCacheKey(int $companyId): string
    {
        return "settings:company:{$companyId}";
    }

    private static function fallback(array $def): mixed
    {
        $v = $def['config'] ? config($def['config']) : null;

        return self::cast($def, $v ?? $def['default']);
    }

    private static function cast(array $def, mixed $v): mixed
    {
        return match ($def['type']) {
            'bool' => is_string($v) ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : (bool) $v,
            'pct', 'eur', 'number' => (float) $v,
            'family_pct' => array_map('floatval', (array) $v),
            default => (string) ($v ?? ''),
        };
    }

    /** Valida e normaliza um valor; erro de validação em português se não servir. */
    private static function validate(array $def, mixed $v): mixed
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages([$def['key'] => [$msg]]);
        switch ($def['type']) {
            case 'bool':
                if (! is_bool($v) && ! in_array($v, [0, 1, '0', '1', 'true', 'false'], true)) {
                    $fail("{$def['label']}: escolha ligado ou desligado.");
                }

                return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'pct':
            case 'eur':
            case 'number':
                if (! is_numeric($v) || (float) $v < (float) ($def['min'] ?? 0) || (float) $v > (float) ($def['max'] ?? PHP_FLOAT_MAX)) {
                    $fail(sprintf('%s: indique um número entre %s e %s.', $def['label'], $def['min'] ?? 0, $def['max'] ?? '∞'));
                }

                return round((float) $v, 4);
            case 'family_pct':
                $out = [];
                foreach ((array) $v as $family => $pct) {
                    $family = trim((string) $family);
                    if ($family === '' || mb_strlen($family) > 120 || ! is_numeric($pct) || (float) $pct < 1 || (float) $pct > 100) {
                        $fail("{$def['label']}: cada família precisa de um nome e de uma percentagem entre 1 e 100.");
                    }
                    $out[$family] = round((float) $pct, 2);
                }
                ksort($out);

                return $out;
            case 'month_day':
                $v = trim((string) $v);
                if ($v === '') {
                    return '';
                }
                if (! preg_match('/^(\d{1,2})[\/-](\d{1,2})$/', $v, $m) || ! checkdate((int) $m[2], (int) $m[1], 2024)) {
                    $fail("{$def['label']}: indique o dia e o mês, por exemplo 24/06.");
                }

                return sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);
            default:
                $v = trim((string) $v);
                if (mb_strlen($v) > 64) {
                    $fail("{$def['label']}: valor demasiado longo.");
                }

                return $v;
        }
    }
}
