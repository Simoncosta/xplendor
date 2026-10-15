<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinLocation;
use App\Models\PingwinProductBomStore;
use App\Models\RecipeWrite;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — Fatia 4: criar, editar e apagar fichas técnicas no PingWin, uma de cada vez.
 *  · retrato do espelho (o "antes", o que a pessoa viu) e a sua assinatura: se o espelho mudou desde
 *    que se abriu o editor, recusa-se e pede-se para recarregar; o Python volta a comparar com a
 *    PingWin ao vivo antes de escrever;
 *  · o plano (as operações do protocolo) por modo: criar, nova versão (por omissão, com a data de
 *    hoje ou futura), corrigir esta versão, apagar uma versão, apagar a ficha inteira;
 *  · o efeito em cada loja (que versão fica em vigor; loja sem ficha);
 *  · a comparação da releitura com o pedido (versão, linhas, lojas): se não bate, fica "mismatch".
 */
final class RecipeWriteService
{
    public const MODES = ['criar', 'nova_versao', 'corrigir'];
    public const MAX_LINES = 60;

    public function __construct(private int $companyId) {}

    /** As versões do artigo no espelho, na forma do "expected" do Python. */
    public function snapshot(string $productId): array
    {
        $cost = (new RecipeCostService($this->companyId))->load([$productId]);
        $versions = array_map(fn ($v) => [
            'id' => $v['bom_id'], 'key' => $v['key'], 'valid_from' => $v['valid_from'], 'deleted' => $v['deleted'],
            'stores' => array_map(fn ($s) => ['store_id' => $s['store_id'], 'name' => $s['name'], 'active' => $s['active']], $v['stores']),
            'lines' => array_map(fn ($l) => [
                'key' => $l['key'], 'component_id' => $l['component_id'], 'description' => $l['description'],
                'unit_id' => $l['unit_id'], 'qnt' => $l['qnt'], 'waste' => $l['waste'], 'recovery' => $l['recovery'],
            ], $v['lines']),
        ], array_values(array_filter($cost->versionsOf($productId), fn ($v) => ! $v['deleted'])));
        usort($versions, fn ($a, $b) => [$a['valid_from'], $a['key']] <=> [$b['valid_from'], $b['key']]);

        return ['versions' => $versions];
    }

    public static function signature(array $snapshot): string
    {
        return sha1(json_encode($snapshot['versions'] ?? []));
    }

    /** As lojas conhecidas: as das versões deste artigo; sem ficha, as de qualquer ficha da empresa. */
    public function storeUniverse(array $snapshot): array
    {
        $stores = [];
        foreach ($snapshot['versions'] as $v) {
            foreach ($v['stores'] as $s) {
                $stores[$s['store_id']] = $s['name'];
            }
        }
        if (! $stores) {
            foreach (PingwinProductBomStore::where('company_id', $this->companyId)->select('store_pingwin_id', 'store_name')->distinct()->get() as $s) {
                $stores[(string) $s->store_pingwin_id] ??= $s->store_name;
            }
        }
        $names = PingwinLocation::where('company_id', $this->companyId)->get()->mapWithKeys(fn ($l) => [(string) $l->winrest_store_id => $l->display_name ?: $l->winrest_name])->all();

        return array_map(fn ($id) => ['store_id' => (string) $id, 'name' => $names[$id] ?? $stores[$id] ?? $id], array_keys($stores));
    }

    /**
     * O plano para gravar um rascunho. $draft: {mode, bom_id?, valid_from?, stores: {id: bool}, lines:
     * [{key?, component_id, description, unit_id, qnt, waste, recovery}]}.
     *
     * @return array{ops: list<array>, target: ?string}
     */
    public function plan(array $snapshot, array $draft): array
    {
        $mode = $draft['mode'];
        $stores = array_map('boolval', $draft['stores'] ?? []);
        $lines = array_values($draft['lines'] ?? []);
        $ops = [];

        if ($mode === 'criar' || $mode === 'nova_versao') {
            $ops[] = ['op' => 'new_version', 'valid_from' => $draft['valid_from'], 'ref' => 'new'];
            foreach ($lines as $l) {
                $ops[] = ['op' => 'add_line', 'version' => 'ref:new'] + self::lineFields($l);
            }
            // A versão nova vem com todas as lojas ativas: só se envia se alguma ficar de fora.
            if ($stores && in_array(false, $stores, true)) {
                $ops[] = ['op' => 'set_stores', 'version' => 'ref:new', 'stores' => $stores];
            }

            return ['ops' => $ops, 'target' => null];
        }

        // Corrigir esta versão: editar as linhas mudadas, juntar as novas, apagar as que saíram (da
        // última para a primeira, para as keys das outras não mudarem) e as lojas, se mudaram.
        $version = collect($snapshot['versions'])->firstWhere('id', $draft['bom_id']);
        if (! $version) {
            throw new \InvalidArgumentException('Versão não encontrada.');
        }
        $base = collect($version['lines'])->keyBy('key');
        $kept = [];
        foreach ($lines as $l) {
            $key = $l['key'] ?? null;
            if ($key !== null && $base->has($key)) {
                $kept[$key] = true;
                if (! self::sameLine($base[$key], $l)) {
                    $ops[] = ['op' => 'edit_line', 'version' => $version['id'], 'key' => $key] + self::lineFields($l);
                }
            }
        }
        foreach ($lines as $l) {
            if (($l['key'] ?? null) === null || ! $base->has($l['key'])) {
                $ops[] = ['op' => 'add_line', 'version' => $version['id']] + self::lineFields($l);
            }
        }
        $gone = $base->keys()->reject(fn ($k) => isset($kept[$k]))->sortDesc()->values();
        foreach ($gone as $key) {
            $ops[] = ['op' => 'delete_line', 'version' => $version['id'], 'key' => $key];
        }
        $active = collect($version['stores'])->mapWithKeys(fn ($s) => [$s['store_id'] => $s['active']])->all();
        $changed = array_filter($stores, fn ($v, $id) => array_key_exists($id, $active) && $active[$id] !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            $ops[] = ['op' => 'set_stores', 'version' => $version['id'], 'stores' => array_intersect_key($stores, $active) + $active];
        }

        return ['ops' => $ops, 'target' => $version['id']];
    }

    public static function lineFields(array $l): array
    {
        return [
            'component_id' => (string) $l['component_id'], 'description' => (string) ($l['description'] ?? ''),
            'unit_id' => (string) $l['unit_id'], 'qnt' => (float) $l['qnt'],
            'waste' => (float) ($l['waste'] ?? 0), 'recovery' => (float) ($l['recovery'] ?? 0),
        ];
    }

    private static function sameLine(array $a, array $b): bool
    {
        return (string) $a['component_id'] === (string) $b['component_id'] && (string) $a['unit_id'] === (string) $b['unit_id']
            && abs((float) $a['qnt'] - (float) $b['qnt']) < 1e-6 && abs((float) $a['waste'] - (float) ($b['waste'] ?? 0)) < 1e-6
            && abs((float) $a['recovery'] - (float) ($b['recovery'] ?? 0)) < 1e-6;
    }

    /**
     * O efeito em cada loja: a versão em vigor antes e depois (na data em que a mudança vale). Uma
     * loja que fica sem nenhuma versão em vigor vai marcada.
     *
     * @param  array  $after  as versões depois da mudança (mesma forma do snapshot)
     */
    public function storeEffects(array $snapshot, array $after, string $from): array
    {
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();
        $at = max($today, $from);
        $toCost = fn ($versions) => array_map(fn ($v) => ['bom_id' => $v['id'], 'key' => $v['key'], 'valid_from' => $v['valid_from'],
            'deleted' => (bool) ($v['deleted'] ?? false), 'stores' => $v['stores'], 'lines' => $v['lines'] ?? []], $versions);
        $before = $toCost($snapshot['versions']);
        $afterV = $toCost($after);
        $out = [];
        foreach ($this->storeUniverse($snapshot) as $s) {
            $b = RecipeCostService::versionInForce($before, $at, $s['store_id']);
            $a = RecipeCostService::versionInForce($afterV, $at, $s['store_id']);
            $out[] = [
                'store_id' => $s['store_id'], 'name' => $s['name'], 'from' => $at,
                'before' => $b['valid_from'] ?? null, 'before_key' => $b['key'] ?? null,
                'after' => $a['valid_from'] ?? null, 'after_key' => $a['key'] ?? null,
                'changes' => ($b['bom_id'] ?? null) !== ($a['bom_id'] ?? null),
                'loses_bom' => $b !== null && $a === null,
                'without_bom' => $a === null,
            ];
        }

        return $out;
    }

    /** As versões depois de um rascunho (para o efeito nas lojas). */
    public static function versionsAfterDraft(array $snapshot, array $draft): array
    {
        $versions = $snapshot['versions'];
        $stores = $draft['stores'] ?? [];
        if (in_array($draft['mode'], ['criar', 'nova_versao'], true)) {
            $versions[] = ['id' => 'nova', 'key' => '99999999', 'valid_from' => $draft['valid_from'], 'deleted' => false, 'lines' => $draft['lines'] ?? [],
                'stores' => array_map(fn ($id, $active) => ['store_id' => (string) $id, 'name' => null, 'active' => (bool) $active], array_keys($stores), array_values($stores))];

            return $versions;
        }

        return array_map(function ($v) use ($draft, $stores) {
            if ($v['id'] === ($draft['bom_id'] ?? null)) {
                $v['stores'] = array_map(fn ($s) => ['active' => array_key_exists($s['store_id'], $stores) ? (bool) $stores[$s['store_id']] : $s['active']] + $s, $v['stores']);
            }

            return $v;
        }, $versions);
    }

    /**
     * Compara a releitura com o pedido. Devolve as diferenças (vazio = bate).
     *
     * @param  array  $reread  a ficha relida (forma do read_bom do Python)
     */
    public static function compare(RecipeWrite $write, array $reread, array $refs): array
    {
        $after = $write->after ?? [];
        $versions = collect($reread['versions'] ?? [])->filter(fn ($v) => (int) ($v['deleted'] ?? 0) === 0)->keyBy(fn ($v) => (string) $v['id']);
        $diff = [];

        if (in_array($write->operation, ['apagar_versao', 'apagar_ficha'], true)) {
            foreach ($after['deleted_versions'] ?? [] as $id) {
                if ($versions->has((string) $id)) {
                    $diff[] = "A versão {$id} continua no PingWin.";
                }
            }

            return $diff;
        }

        $id = $write->operation === 'corrigir' ? (string) $write->bom_pingwin_id : (string) ($refs['new']['id'] ?? '');
        $v = $versions->get($id);
        if (! $v) {
            return ["A versão {$id} não aparece na releitura."];
        }
        if ($write->operation !== 'corrigir' && ($v['valid_from'] ?? null) !== ($after['valid_from'] ?? null)) {
            $diff[] = "A data da versão é {$v['valid_from']} (pedida {$after['valid_from']}).";
        }
        $want = collect($after['lines'] ?? [])->map(fn ($l) => self::lineSig($l['component_id'], $l['unit_id'], $l['qnt'], $l['waste'] ?? 0, $l['recovery'] ?? 0))->sort()->values()->all();
        $got = collect($v['lines'][''] ?? [])->map(fn ($l) => self::lineSig($l['component_id'], $l['unit_id'], $l['qnt'], $l['waste'], $l['recovery']))->sort()->values()->all();
        foreach (array_diff($want, $got) as $x) {
            $diff[] = "Linha pedida que não está no PingWin: {$x}.";
        }
        foreach (array_diff($got, $want) as $x) {
            $diff[] = "Linha no PingWin que não foi pedida: {$x}.";
        }
        $stores = collect($v['stores'] ?? [])->mapWithKeys(fn ($s) => [(string) $s['store_id'] => (bool) $s['active']]);
        foreach ($after['stores'] ?? [] as $sid => $active) {
            if ($stores->has((string) $sid) && $stores[(string) $sid] !== (bool) $active) {
                $diff[] = sprintf('A loja %s está %s no PingWin (pedido: %s).', $sid, $stores[(string) $sid] ? 'ativa' : 'inativa', $active ? 'ativa' : 'inativa');
            }
        }

        return $diff;
    }

    private static function lineSig($component, $unit, $qnt, $waste, $recovery): string
    {
        return sprintf('%s × %s %s (desperdício %s%%, recuperação %s%%)', $component, rtrim(rtrim(number_format((float) $qnt, 6, '.', ''), '0'), '.'),
            $unit, rtrim(rtrim(number_format((float) $waste, 4, '.', ''), '0'), '.'), rtrim(rtrim(number_format((float) $recovery, 4, '.', ''), '0'), '.'));
    }
}
