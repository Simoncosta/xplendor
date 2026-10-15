<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCatalogItem;
use App\Services\PingwinService;
use Illuminate\Support\Facades\Cache;

/**
 * XPLENDOR — Fatia 7b: os Movimentos de um artigo (relatório 838630403295 do PingWin, SÓ LEITURA), a
 * pedido e com cache de 10 minutos. A quantidade com sinal é |qnt| × stk_signal; stk_signal 0 (as
 * encomendas) "não mexe no stock". A quantidade vem na unidade do documento (por exemplo, Grade 24).
 * O código do artigo vem em bom_code (o product_code é o código do fornecedor).
 */
final class StockMovementsService
{
    public function __construct(private PingwinService $pingwin) {}

    public static function key(int $companyId, string $from, string $to, string $codeFrom, string $codeTo): string
    {
        return "stock-movements:{$companyId}:{$from}:{$to}:{$codeFrom}:{$codeTo}";
    }

    /**
     * O que já está em cache; senão põe a leitura na fila do worker (uma de cada vez por intervalo).
     * Devolve ['ready' => bool, 'rows' => list|null, 'error' => ?string].
     */
    public function cachedOrQueue(int $companyId, string $from, string $to, string $codeFrom, string $codeTo): array
    {
        $key = self::key($companyId, $from, $to, $codeFrom, $codeTo);
        if (Cache::has($key)) {
            return ['ready' => true, 'rows' => Cache::get($key), 'error' => null];
        }
        if ($error = Cache::pull($key . ':error')) {
            return ['ready' => true, 'rows' => null, 'error' => (string) $error];
        }
        if (Cache::add($key . ':pending', 1, now()->addMinutes(6))) {
            \App\Jobs\FetchStockMovementsJob::dispatch($companyId, $from, $to, $codeFrom, $codeTo);
        }

        return ['ready' => false, 'rows' => null, 'error' => null];
    }

    /** @return list<array<string, mixed>> */
    public function fetch(int $companyId, string $from, string $to, string $codeFrom, string $codeTo): array
    {
        $key = self::key($companyId, $from, $to, $codeFrom, $codeTo);

        return Cache::remember($key, now()->addMinutes(10), function () use ($companyId, $from, $to, $codeFrom, $codeTo) {
            $res = $this->pingwin->stockDocument($companyId, 'movements', ['start' => $from, 'end' => $to, 'code_from' => $codeFrom, 'code_to' => $codeTo], 300);
            if (! ($res['ok'] ?? false)) {
                throw new \RuntimeException('Leitura dos movimentos falhou: ' . ($res['error'] ?? 'erro desconhecido'));
            }

            return array_map([self::class, 'shape'], (array) ($res['movements'] ?? []));
        });
    }

    public static function shape(array $m): array
    {
        $signal = (int) ($m['stk_signal'] ?? 0);
        $qnt = (float) ($m['qnt'] ?? 0);

        return $m + [
            'article_code' => (string) (($m['bom_code'] ?? '') ?: ($m['product_code'] ?? '')),
            // O relatório já traz as saídas negativas (GA −12 com sinal −1; Fatia 7c, passo 0-D): |qnt| × sinal.
            'signed_qty' => abs($qnt) * $signal,
            'moves_stock' => $signal !== 0 && ! in_array(mb_strtolower((string) ($m['docstatus'] ?? '')), ['anulado', 'aberto'], true),
        ];
    }

    /** O código do artigo (catálogo; senão a lista dos contáveis). */
    public static function codeOf(int $companyId, string $productId): string
    {
        $code = (string) PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->value('code');

        return $code !== '' ? $code : (string) \App\Models\PingwinCountableProduct::where('company_id', $companyId)->where('product_id', $productId)->value('code');
    }

    /** Filtra e ordena os movimentos de UM código (e armazém). */
    public static function onlyProduct(array $rows, string $code, ?string $warehouseId = null): array
    {
        $rows = array_values(array_filter($rows, fn ($m) => $m['article_code'] === $code && (! $warehouseId || (string) $m['warehouse_id'] === $warehouseId)));
        usort($rows, fn ($a, $b) => [$b['doc_date'], $b['document']] <=> [$a['doc_date'], $a['document']]);

        return $rows;
    }

    /** Os movimentos de UM artigo (pelo código), com filtro de armazém. */
    public function forProduct(int $companyId, string $productId, string $from, string $to, ?string $warehouseId = null): array
    {
        $code = (string) PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->value('code');
        if ($code === '') {
            $code = (string) \App\Models\PingwinCountableProduct::where('company_id', $companyId)->where('product_id', $productId)->value('code');
        }
        if ($code === '') {
            return [];
        }
        $rows = array_values(array_filter($this->fetch($companyId, $from, $to, $code, $code),
            fn ($m) => $m['article_code'] === $code && (! $warehouseId || (string) $m['warehouse_id'] === $warehouseId)));
        usort($rows, fn ($a, $b) => [$b['doc_date'], $b['document']] <=> [$a['doc_date'], $a['document']]);

        return $rows;
    }
}
