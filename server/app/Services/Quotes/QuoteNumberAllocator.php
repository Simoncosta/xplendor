<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use Illuminate\Support\Facades\DB;

/**
 * Número sequencial por ano (ORC-2026-001), atribuído no PRIMEIRO envio: rascunhos
 * apagados não deixam buracos. A linha do ano fica bloqueada até ao fim da
 * transação de quem chama, por isso dois envios ao mesmo tempo nunca repetem número.
 * Tem de ser chamado dentro de uma transação.
 *
 * @return array{number: string, year: int, seq: int}
 */
class QuoteNumberAllocator
{
    public function next(int $year): array
    {
        DB::table('quote_number_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('quote_number_sequences')->where('year', $year)->lockForUpdate()->first();
        $seq = (int) $row->last_number + 1;
        DB::table('quote_number_sequences')->where('year', $year)->update(['last_number' => $seq, 'updated_at' => now()]);

        return ['number' => sprintf('ORC-%d-%03d', $year, $seq), 'year' => $year, 'seq' => $seq];
    }
}
