<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modelo por função da IA, escolhido SÓ pelo root (Administração › Modelos de IA): para cada
 * função (legenda, criativo, ideias, blog, perfil, descrição e análise de viaturas) o
 * fornecedor, o modelo e o esforço de raciocínio, com histórico de alterações. Sem linha
 * gravada, valem os valores iniciais de config/ai.php; nas funções do OCR (group "ocr"), a
 * reserva do .env (OCR_MODEL_TEXT, OCR_MODEL_IMAGE e OCR_REASONING_EFFORT).
 */
class AiFunctionSettings
{
    /** @return array{provider: string, model: string, effort: string} */
    public static function for(string $function): array
    {
        self::assertFunction($function);
        $row = DB::table('ai_function_settings')->where('function', $function)->first();
        if ($row) {
            return ['provider' => (string) $row->provider, 'model' => (string) $row->model, 'effort' => (string) $row->effort];
        }
        if ($reserve = self::reserve($function)) {
            return $reserve;
        }
        $model = (string) config('ai.default_model');

        return ['provider' => self::providerOf($model), 'model' => $model, 'effort' => (string) config("ai.functions.{$function}.default_effort", 'low')];
    }

    public static function set(string $function, string $model, string $effort, User $actor, bool $appliedToAll = false): void
    {
        self::assertFunction($function);
        self::assertChoice($model, $effort);
        $from = self::for($function);
        unset($from['reserve']);
        $to = ['provider' => self::providerOf($model), 'model' => $model, 'effort' => $effort];
        if ($from === $to) {
            return;
        }
        DB::transaction(function () use ($function, $from, $to, $actor, $appliedToAll) {
            DB::table('ai_function_settings')->updateOrInsert(['function' => $function],
                $to + ['updated_by_user_id' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);
            DB::table('ai_function_setting_changes')->insert([
                'function' => $function, 'from_provider' => $from['provider'], 'from_model' => $from['model'], 'from_effort' => $from['effort'],
                'to_provider' => $to['provider'], 'to_model' => $to['model'], 'to_effort' => $to['effort'],
                'applied_to_all' => $appliedToAll, 'user_id' => $actor->id, 'created_at' => now(),
            ]);
        });
    }

    /** "Aplicar a todas as funções": o mesmo modelo e esforço em todas, exceto as do OCR (escolhem-se à parte). */
    public static function applyAll(string $model, string $effort, User $actor): void
    {
        self::assertChoice($model, $effort);
        foreach ((array) config('ai.functions') as $function => $def) {
            if (($def['group'] ?? null) !== 'ocr') {
                self::set($function, $model, $effort, $actor, true);
            }
        }
    }

    /**
     * A reserva do .env das funções do OCR, quando nada está escolhido: o modelo de
     * OCR_MODEL_TEXT ou OCR_MODEL_IMAGE (o fornecedor pelo catálogo, ou pelo nome) e o esforço
     * de OCR_REASONING_EFFORT. Null nas outras funções.
     *
     * @return array{provider: string, model: string, effort: string, reserve: true}|null
     */
    public static function reserve(string $function): ?array
    {
        $key = config("ai.functions.{$function}.reserve_model");
        if (! is_string($key)) {
            return null;
        }
        $model = (string) config($key, '');
        $provider = self::providerOf($model) ?: ($model === '' ? '' : (str_starts_with($model, 'claude') ? 'anthropic' : 'openai'));

        return ['provider' => $provider, 'model' => $model, 'effort' => (string) config('services.openai.ocr.reasoning_effort', 'low'), 'reserve' => true];
    }

    /** Há uma escolha gravada (senão vale o valor inicial ou a reserva do .env). */
    public static function chosen(string $function): bool
    {
        return DB::table('ai_function_settings')->where('function', $function)->exists();
    }

    /** O ecrã do root: as funções, as escolhas possíveis e o histórico. */
    public static function overview(): array
    {
        $names = User::pluck('name', 'id');

        return [
            'functions' => collect((array) config('ai.functions'))->map(function ($f, $key) {
                $s = self::for($key);
                $reserve = (bool) ($s['reserve'] ?? false);
                unset($s['reserve']);

                return ['key' => $key, 'label' => $f['label'], 'hint' => $f['hint'] ?? null, 'group' => $f['group'] ?? null,
                    // Funções do OCR sem escolha: vale o modelo do .env (pode não estar no catálogo).
                    'reserve' => $reserve, 'reserve_env' => $reserve ? strtoupper(str_replace('services.openai.ocr.model_', 'OCR_MODEL_', (string) $f['reserve_model'])) : null] + $s;
            })->values()->all(),
            'models' => collect((array) config('ai.models'))->map(fn ($m, $key) => [
                'key' => $key, 'label' => $m['label'], 'provider' => $m['provider'], 'efforts' => $m['efforts'], 'prices' => $m['prices'],
            ])->values()->all(),
            'providers' => collect((array) config('ai.providers'))->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label'], 'configured' => (string) ($p['key'] ?? '') !== ''])->values()->all(),
            'history' => DB::table('ai_function_setting_changes')->orderByDesc('id')->limit(50)->get()->map(fn ($c) => [
                'function' => $c->function, 'from' => $c->from_model ? "{$c->from_model} ({$c->from_effort})" : null, 'to' => "{$c->to_model} ({$c->to_effort})",
                'applied_to_all' => (bool) $c->applied_to_all, 'user' => $names[$c->user_id] ?? null, 'at' => $c->created_at,
            ])->all(),
        ];
    }

    /** Os dados de um modelo (os nomes têm pontos, como "gpt-6.1-sol": nunca pela notação com pontos do config). */
    public static function model(string $model): ?array
    {
        $m = ((array) config('ai.models'))[$model] ?? null;

        return is_array($m) ? $m : null;
    }

    public static function providerOf(string $model): string
    {
        return (string) (self::model($model)['provider'] ?? '');
    }

    private static function assertFunction(string $function): void
    {
        if (! array_key_exists($function, (array) config('ai.functions'))) {
            throw ValidationException::withMessages(['function' => ["Função de IA desconhecida: {$function}."]]);
        }
    }

    private static function assertChoice(string $model, string $effort): void
    {
        $m = self::model($model);
        if (! is_array($m)) {
            throw ValidationException::withMessages(['model' => ["Modelo desconhecido: {$model}."]]);
        }
        if (! in_array($effort, (array) $m['efforts'], true)) {
            throw ValidationException::withMessages(['effort' => ["O modelo {$model} não aceita o esforço \"{$effort}\"."]]);
        }
    }
}
