<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Jobs\WritePingwinStockJob;
use App\Models\PingwinStock;
use App\Models\PingwinStockWrite;
use App\Models\PingwinWarehouse;
use App\Services\PingwinService;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Fatia 7a: as escritas no PingWin do stock e dos armazéns. Uma de cada vez, iniciada por
 * uma pessoa, confirmada por releitura (no WritePingwinStockJob). Aqui só se valida e se põe na fila:
 *  · mínimo, máximo e "Não usa genéricos" de um artigo num armazém onde o artigo tem linha de stock;
 *  · criar, editar (nome, lojas servidas, valorização) e apagar um armazém (só sem stock, com o nome
 *    escrito outra vez como segunda confirmação).
 * A guarda do dev (listas brancas; "0" = nenhuma escrita; vazias em produção) vale aqui e no Python.
 */
final class StockWriteService
{
    /** Escrita do mínimo/máximo de um artigo num armazém. */
    public function requestLimits(int $companyId, ?int $userId, string $productId, string $warehouseId, array $changes): PingwinStockWrite
    {
        if ($guard = PingwinService::stockWriteGuard($productId)) {
            throw ValidationException::withMessages(['pingwin' => [$guard['reason']]]);
        }
        $row = PingwinStock::where('company_id', $companyId)->where('product_pingwin_id', $productId)->where('warehouse_pingwin_id', $warehouseId)->first();
        if (! $row || ! PingwinWarehouse::where('company_id', $companyId)->where('pingwin_id', $warehouseId)->where('deleted', false)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => ['Este artigo não tem linha de stock neste armazém no PingWin.']]);
        }
        $fields = array_intersect_key($changes, array_flip(['min_stock', 'max_stock', 'disable_generic']));
        $current = ['min_stock' => $row->min_stock, 'max_stock' => $row->max_stock, 'disable_generic' => $row->disable_generic ? 1 : 0];
        $next = array_merge($current, $fields);
        if (array_key_exists('disable_generic', $fields)) {
            $fields['disable_generic'] = $next['disable_generic'] = $fields['disable_generic'] ? 1 : 0;
        }
        foreach (['min_stock', 'max_stock'] as $k) {
            if (array_key_exists($k, $fields) && $fields[$k] !== null) {
                $fields[$k] = $next[$k] = (float) $fields[$k];
            }
        }
        if ($next['min_stock'] !== null && $next['max_stock'] !== null && (float) $next['max_stock'] > 0 && (float) $next['min_stock'] > (float) $next['max_stock']) {
            throw ValidationException::withMessages(['min_stock' => ['O mínimo não pode ser maior do que o máximo.']]);
        }
        $changed = array_filter($fields, fn ($v, $k) => ! self::same($v, $current[$k]), ARRAY_FILTER_USE_BOTH);
        if (! $changed) {
            throw ValidationException::withMessages(['min_stock' => ['Não há nada a mudar.']]);
        }
        $this->assertFree($companyId, fn ($q) => $q->where('product_pingwin_id', $productId));

        return $this->queue($companyId, $userId, 'limites', $productId, $warehouseId, $changed, $current);
    }

    /** Criar um armazém. */
    public function requestCreate(int $companyId, ?int $userId, array $fields): PingwinStockWrite
    {
        $fields = $this->warehouseFields($companyId, $fields, null);
        if ($guard = PingwinService::warehouseWriteGuard($fields['description'])) {
            throw ValidationException::withMessages(['pingwin' => [$guard['reason']]]);
        }
        $this->assertFree($companyId, fn ($q) => $q->where('kind', 'like', 'armazem_%'));

        return $this->queue($companyId, $userId, 'armazem_criar', null, null, $fields, null);
    }

    /** Editar um armazém (nome, lojas servidas, valorização). */
    public function requestUpdate(int $companyId, ?int $userId, string $warehouseId, array $fields): PingwinStockWrite
    {
        $w = $this->activeWarehouse($companyId, $warehouseId);
        if ($guard = PingwinService::warehouseWriteGuard($w->description)) {
            throw ValidationException::withMessages(['pingwin' => [$guard['reason']]]);
        }
        $fields = $this->warehouseFields($companyId, $fields, $w);
        if (isset($fields['description']) && ($guard = PingwinService::warehouseWriteGuard($fields['description']))) {
            throw ValidationException::withMessages(['pingwin' => [$guard['reason']]]);
        }
        $expected = ['description' => $w->description, 'inv_costmethod' => $w->inv_costmethod, 'exit_costmethod' => $w->exit_costmethod,
            'store_ids' => $w->servedStoreIds()];
        $changed = [];
        foreach ($fields as $k => $v) {
            $cur = $expected[$k] ?? null;
            if ($k === 'store_ids' ? self::sorted($v) !== self::sorted((array) $cur) : $v !== $cur) {
                $changed[$k] = $v;
            }
        }
        if (! $changed) {
            throw ValidationException::withMessages(['description' => ['Não há nada a mudar.']]);
        }
        $this->assertFree($companyId, fn ($q) => $q->where('kind', 'like', 'armazem_%'));
        $expected['store_ids'] = self::sorted($expected['store_ids']);

        return $this->queue($companyId, $userId, 'armazem_editar', null, $warehouseId, $changed, $expected);
    }

    /** Apagar um armazém: só sem stock, e com o nome escrito outra vez. */
    public function requestDelete(int $companyId, ?int $userId, string $warehouseId, ?string $confirmName): PingwinStockWrite
    {
        $w = $this->activeWarehouse($companyId, $warehouseId);
        if ($guard = PingwinService::warehouseWriteGuard($w->description)) {
            throw ValidationException::withMessages(['pingwin' => [$guard['reason']]]);
        }
        $withStock = PingwinStock::where('company_id', $companyId)->where('warehouse_pingwin_id', $warehouseId)->where('qnt', '!=', 0)->count();
        if ($withStock > 0) {
            throw ValidationException::withMessages(['warehouse_id' => [sprintf('Só se apaga um armazém sem stock. Este tem stock em %d %s.', $withStock, $withStock === 1 ? 'artigo' : 'artigos')]]);
        }
        if (trim((string) $confirmName) !== trim((string) $w->description)) {
            throw ValidationException::withMessages(['confirm_name' => ['Para confirmar, escreva o nome do armazém exatamente como aparece.']]);
        }
        $this->assertFree($companyId, fn ($q) => $q->where('kind', 'like', 'armazem_%'));

        return $this->queue($companyId, $userId, 'armazem_apagar', null, $warehouseId, ['description' => $w->description], ['description' => $w->description]);
    }

    private function queue(int $companyId, ?int $userId, string $kind, ?string $productId, ?string $warehouseId, array $payload, ?array $expected): PingwinStockWrite
    {
        $write = PingwinStockWrite::create([
            'company_id' => $companyId, 'user_id' => $userId, 'kind' => $kind, 'product_pingwin_id' => $productId,
            'warehouse_pingwin_id' => $warehouseId, 'payload' => $payload, 'expected' => $expected, 'status' => 'queued',
        ]);
        WritePingwinStockJob::dispatch($write->id);

        return $write;
    }

    /** Uma escrita de cada vez (no artigo, ou nos armazéns da empresa). */
    private function assertFree(int $companyId, \Closure $scope): void
    {
        $busy = PingwinStockWrite::where('company_id', $companyId)->whereIn('status', ['queued', 'running'])->where($scope)
            ->where('created_at', '>=', now()->subMinutes(15))->exists();
        if ($busy) {
            throw ValidationException::withMessages(['pingwin' => ['Já há uma gravação destas a correr: aguarde que termine.']]);
        }
    }

    private function activeWarehouse(int $companyId, string $warehouseId): PingwinWarehouse
    {
        $w = PingwinWarehouse::where('company_id', $companyId)->where('pingwin_id', $warehouseId)->where('deleted', false)->first();
        if (! $w) {
            throw ValidationException::withMessages(['warehouse_id' => ['Armazém não encontrado (ou já anulado).']]);
        }

        return $w;
    }

    /** Valida e normaliza os campos de um armazém (null em $current = criar). */
    private function warehouseFields(int $companyId, array $in, ?PingwinWarehouse $current): array
    {
        $out = [];
        if ($current === null || array_key_exists('description', $in)) {
            $name = trim((string) ($in['description'] ?? ''));
            if ($name === '' || mb_strlen($name) > 50) {
                throw ValidationException::withMessages(['description' => ['O nome do armazém é obrigatório (até 50 caracteres).']]);
            }
            $taken = PingwinWarehouse::where('company_id', $companyId)->where('deleted', false)
                ->when($current, fn ($q) => $q->where('pingwin_id', '!=', $current->pingwin_id))->get()
                ->contains(fn ($w) => mb_strtolower(trim((string) $w->description)) === mb_strtolower($name));
            if ($taken) {
                throw ValidationException::withMessages(['description' => ['Já existe um armazém ativo com este nome.']]);
            }
            $out['description'] = $name;
        }
        foreach (['inv_costmethod', 'exit_costmethod'] as $k) {
            if (array_key_exists($k, $in) && $in[$k] !== null && $in[$k] !== '') {
                if (! array_key_exists((int) $in[$k], PingwinWarehouse::COSTMETHODS)) {
                    throw ValidationException::withMessages([$k => ['Método de valorização desconhecido.']]);
                }
                $out[$k] = (int) $in[$k];
            } elseif ($current === null) {
                $out[$k] = 0;
            }
        }
        if (array_key_exists('store_ids', $in) && $in['store_ids'] !== null) {
            $known = $this->knownStores($companyId);
            $ids = array_values(array_unique(array_map('strval', (array) $in['store_ids'])));
            $unknown = array_diff($ids, array_keys($known));
            if ($unknown) {
                throw ValidationException::withMessages(['store_ids' => ['Loja desconhecida.']]);
            }
            $out['store_ids'] = $ids;
        }

        return $out;
    }

    /** @return array<string, string> as lojas conhecidas (das lojas que os armazéns listam), id → nome */
    public function knownStores(int $companyId): array
    {
        $out = [];
        foreach (PingwinWarehouse::where('company_id', $companyId)->get() as $w) {
            foreach ((array) $w->stores as $s) {
                if (! empty($s['store_id'])) {
                    $out[(string) $s['store_id']] = (string) ($s['name'] ?? $s['code'] ?? $s['store_id']);
                }
            }
        }

        return $out;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        return abs((float) $a - (float) $b) < 1e-9;
    }

    private static function sorted(array $ids): array
    {
        $ids = array_values(array_map('strval', $ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
