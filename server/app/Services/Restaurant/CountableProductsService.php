<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinCountableProduct;
use App\Services\PingwinService;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 7b: os artigos que a Contagem (1106) do PingWin aceita (os de venda com ficha não
 * têm stock e não aparecem). Lidos no sync do stock das 04:15 e a pedido (SÓ LEITURA: NEW → lista →
 * CANCEL, nada se grava). Substitui a lista inteira.
 */
final class CountableProductsService
{
    public function __construct(private PingwinService $pingwin) {}

    public function refresh(int $companyId): int
    {
        $res = $this->pingwin->stockDocument($companyId, 'countable_products', [], 300);
        if (! ($res['ok'] ?? false)) {
            throw new \RuntimeException('Leitura dos artigos contáveis falhou: ' . ($res['error'] ?? 'erro desconhecido'));
        }
        $rows = (array) ($res['products'] ?? []);
        $now = now();
        DB::transaction(function () use ($companyId, $rows, $now) {
            $ids = [];
            foreach (array_chunk($rows, 500) as $chunk) {
                PingwinCountableProduct::upsert(array_map(fn ($r) => [
                    'company_id' => $companyId, 'product_id' => (string) $r['id'], 'code' => $r['code'] ?? null, 'description' => $r['description'] ?? null,
                    'family' => $r['family'] ?? null, 'refreshed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk), ['company_id', 'product_id'], ['code', 'description', 'family', 'refreshed_at', 'updated_at']);
                $ids = array_merge($ids, array_map(fn ($r) => (string) $r['id'], $chunk));
            }
            if ($rows) {
                PingwinCountableProduct::where('company_id', $companyId)->whereNotIn('product_id', $ids)->delete();
            }
        });

        return count($rows);
    }
}
