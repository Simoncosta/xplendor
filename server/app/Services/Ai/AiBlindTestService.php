<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Jobs\RunAiBlindCaseJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teste às cegas (só o root): 10 casos por função gerados com os dois modelos (claude-opus-5-5 e
 * gpt-6.1-sol), com o mesmo esforço (o inicial da função). Os pares aparecem sem o nome do
 * modelo e com o lado sorteado; fica registada a escolha de quem avalia, o resultado do
 * verificador do português e o custo de cada resposta. O relatório por função só conta os
 * testes já avaliados por inteiro (antes disso, os modelos não são revelados).
 */
class AiBlindTestService
{
    public const MODEL_A = 'claude-opus-5-5';
    public const MODEL_B = 'gpt-6.1-sol';

    public function create(string $function, User $actor): int
    {
        if (! array_key_exists($function, (array) config('ai.functions'))) {
            throw ValidationException::withMessages(['function' => ['Escolha uma função válida.']]);
        }
        $testId = DB::transaction(function () use ($function, $actor) {
            $id = DB::table('ai_blind_tests')->insertGetId([
                'function' => $function, 'model_a' => self::MODEL_A, 'model_b' => self::MODEL_B, 'status' => 'generating',
                'created_by_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            for ($i = 0; $i < AiBlindTestCases::COUNT; $i++) {
                DB::table('ai_blind_cases')->insert([
                    'ai_blind_test_id' => $id, 'case_index' => $i, 'input' => json_encode(AiBlindTestCases::make($function, $i)['input'], JSON_UNESCAPED_UNICODE),
                    'a_on_left' => random_int(0, 1) === 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        });
        foreach (DB::table('ai_blind_cases')->where('ai_blind_test_id', $testId)->pluck('id') as $caseId) {
            RunAiBlindCaseJob::dispatch((int) $caseId);
        }

        return $testId;
    }

    /** Gera as duas respostas de um caso (um erro de um modelo fica registado e conta no relatório). */
    public function runCase(int $caseId): void
    {
        $case = DB::table('ai_blind_cases')->find($caseId);
        $test = $case ? DB::table('ai_blind_tests')->find($case->ai_blind_test_id) : null;
        if (! $test) {
            return;
        }
        $made = AiBlindTestCases::make($test->function, (int) $case->case_index);
        $effort = (string) config("ai.functions.{$test->function}.default_effort", 'low');
        $gateway = app(AiGateway::class);

        foreach (['a' => $test->model_a, 'b' => $test->model_b] as $side => $model) {
            if ($case->{"{$side}_text"} !== null || $case->{"{$side}_error"} !== null) {
                continue;
            }
            try {
                $r = $gateway->generateWith($test->function, $made['prompt'], $model, $effort);
                DB::table('ai_blind_cases')->where('id', $caseId)->update([
                    "{$side}_text" => $r->json !== null ? json_encode($r->json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $r->text,
                    "{$side}_pt_issues" => json_encode($r->ptIssues, JSON_UNESCAPED_UNICODE), "{$side}_cost_usd" => round($r->costUsd, 6),
                    "{$side}_ms" => $r->ms, 'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                DB::table('ai_blind_cases')->where('id', $caseId)->update([
                    "{$side}_error" => mb_substr(AiRequestLifecycle::failureMessage($e), 0, 500), 'updated_at' => now(),
                ]);
            }
        }
        $this->refreshStatus((int) $test->id);
    }

    public function refreshStatus(int $testId): void
    {
        $pending = DB::table('ai_blind_cases')->where('ai_blind_test_id', $testId)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('a_text')->whereNull('a_error'))->orWhere(fn ($w) => $w->whereNull('b_text')->whereNull('b_error')))
            ->exists();
        DB::table('ai_blind_tests')->where('id', $testId)->update(['status' => $pending ? 'generating' : 'ready', 'updated_at' => now()]);
    }

    /** "left" | "right" | "tie": o lado visto pelo avaliador passa a "a" ou "b". */
    public function choose(int $testId, int $caseId, string $choice, User $actor): void
    {
        $case = DB::table('ai_blind_cases')->where('ai_blind_test_id', $testId)->find($caseId);
        if (! $case) {
            throw ValidationException::withMessages(['case' => ['Caso não encontrado.']]);
        }
        if (($case->a_text === null && $case->a_error === null) || ($case->b_text === null && $case->b_error === null)) {
            throw ValidationException::withMessages(['case' => ['Este caso ainda está a ser gerado.']]);
        }
        $value = match ($choice) {
            'tie' => 'tie',
            'left' => $case->a_on_left ? 'a' : 'b',
            'right' => $case->a_on_left ? 'b' : 'a',
            default => throw ValidationException::withMessages(['choice' => ['Escolha a esquerda, a direita ou empate.']]),
        };
        DB::table('ai_blind_cases')->where('id', $caseId)->update(['choice' => $value, 'chosen_by_user_id' => $actor->id, 'chosen_at' => now(), 'updated_at' => now()]);
    }

    /** Os testes, com o progresso; os modelos só aparecem nos testes avaliados por inteiro. */
    public function list(): array
    {
        $counts = DB::table('ai_blind_cases')->selectRaw('ai_blind_test_id, count(*) as total, sum(case when choice is not null then 1 else 0 end) as chosen')
            ->groupBy('ai_blind_test_id')->get()->keyBy('ai_blind_test_id');
        $labels = (array) config('ai.functions');

        return DB::table('ai_blind_tests')->orderByDesc('id')->limit(50)->get()->map(function ($t) use ($counts, $labels) {
            $c = $counts->get($t->id);
            $complete = $c && (int) $c->chosen === (int) $c->total;

            return [
                'id' => $t->id, 'function' => $t->function, 'function_label' => $labels[$t->function]['label'] ?? $t->function, 'status' => $t->status,
                'total' => (int) ($c->total ?? 0), 'chosen' => (int) ($c->chosen ?? 0), 'complete' => $complete,
                'models' => $complete ? [$t->model_a, $t->model_b] : null, 'created_at' => $t->created_at,
            ];
        })->all();
    }

    /** Os pares de um teste, sem o nome do modelo (esquerda e direita como o avaliador as vê). */
    public function show(int $testId): array
    {
        $test = DB::table('ai_blind_tests')->find($testId);
        if (! $test) {
            throw ValidationException::withMessages(['test' => ['Teste não encontrado.']]);
        }
        $side = fn ($c, string $s) => [
            'text' => $c->{"{$s}_text"}, 'error' => $c->{"{$s}_error"},
            'ready' => $c->{"{$s}_text"} !== null || $c->{"{$s}_error"} !== null,
            'pt_issues' => json_decode((string) $c->{"{$s}_pt_issues"}, true) ?: [],
        ];
        $cases = DB::table('ai_blind_cases')->where('ai_blind_test_id', $testId)->orderBy('case_index')->get()->map(fn ($c) => [
            'id' => $c->id, 'index' => (int) $c->case_index + 1, 'input' => json_decode((string) $c->input, true),
            'left' => $side($c, $c->a_on_left ? 'a' : 'b'), 'right' => $side($c, $c->a_on_left ? 'b' : 'a'),
            'choice' => $c->choice === null ? null : ($c->choice === 'tie' ? 'tie' : ((($c->choice === 'a') === (bool) $c->a_on_left) ? 'left' : 'right')),
        ])->all();

        return ['id' => $test->id, 'function' => $test->function, 'function_label' => config("ai.functions.{$test->function}.label", $test->function), 'status' => $test->status, 'cases' => $cases];
    }

    /** Relatório final por função (só os testes avaliados por inteiro): vitórias, verificador do português, erros e custo médio. */
    public function report(): array
    {
        $complete = collect($this->list())->where('complete', true)->pluck('id')->all();
        if ($complete === []) {
            return [];
        }
        $rows = DB::table('ai_blind_cases as c')->join('ai_blind_tests as t', 't.id', '=', 'c.ai_blind_test_id')
            ->whereIn('t.id', $complete)->get(['t.function', 't.model_a', 't.model_b', 'c.*']);
        $labels = (array) config('ai.functions');

        return $rows->groupBy(fn ($r) => "{$r->function}|{$r->model_a}|{$r->model_b}")->map(function ($g) use ($labels) {
            $first = $g->first();
            $side = fn (string $s) => [
                'model' => $first->{"model_{$s}"},
                'wins' => $g->where('choice', $s)->count(),
                'pt_failures' => $g->filter(fn ($r) => ! empty(json_decode((string) $r->{"{$s}_pt_issues"}, true)))->count(),
                'errors' => $g->whereNotNull("{$s}_error")->count(),
                'avg_cost_usd' => round((float) $g->whereNotNull("{$s}_cost_usd")->avg("{$s}_cost_usd"), 6),
                'total_cost_usd' => round((float) $g->sum("{$s}_cost_usd"), 6),
                'avg_ms' => (int) round((float) $g->whereNotNull("{$s}_ms")->avg("{$s}_ms")),
            ];

            return [
                'function' => $first->function, 'function_label' => $labels[$first->function]['label'] ?? $first->function,
                'cases' => $g->count(), 'ties' => $g->where('choice', 'tie')->count(), 'a' => $side('a'), 'b' => $side('b'),
            ];
        })->values()->all();
    }
}
