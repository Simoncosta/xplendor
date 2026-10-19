<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Finanças › Mês: o grupo de um gasto pela família (o caminho da família no PingWin,
 * "COMPRAS › BEBIDAS › CERVEJAS"), com as regras de config/finance_month.php (a primeira que bate).
 * Cauções e vasilhame (pela família ou pela descrição do artigo) ficam fora do resultado.
 */
final class FinanceClassifier
{
    public const UNCLASSIFIED = 'por_classificar';

    /** @var array<string, object> */
    private array $families = [];

    /** @var array<string, string> */
    private array $paths = [];

    public function __construct(private readonly int $companyId, private readonly array $cfg)
    {
        $this->families = DB::table('pingwin_families')->where('company_id', $companyId)
            ->get(['pingwin_id', 'description', 'parent_pingwin_id'])->keyBy('pingwin_id')->all();
    }

    /** O caminho da família, sem a raiz "Família": "COMPRAS › BEBIDAS › CERVEJAS" ('' = sem família). */
    public function path(?string $familyId): string
    {
        $familyId = (string) $familyId;
        if ($familyId === '') {
            return '';
        }
        if (isset($this->paths[$familyId])) {
            return $this->paths[$familyId];
        }
        $parts = [];
        $id = $familyId;
        for ($i = 0; $id !== '' && isset($this->families[$id]) && $i < 8; $i++) {
            array_unshift($parts, trim((string) $this->families[$id]->description));
            $id = (string) $this->families[$id]->parent_pingwin_id;
        }
        if ($parts && mb_strtolower($parts[0]) === 'família') {
            array_shift($parts);
        }

        return $this->paths[$familyId] = implode(' › ', $parts);
    }

    /** A família a mostrar: os três primeiros níveis do caminho. */
    public static function label(string $path): string
    {
        return $path === '' ? '' : implode(' › ', array_slice(explode(' › ', $path), 0, 3));
    }

    /**
     * O grupo de uma linha: pela descrição (depósitos) e depois pela família. null = sem família.
     *
     * @return array{group: string, reason: ?string, by?: string}|null
     */
    public function classify(string $path, ?string $description = null): ?array
    {
        $byFamily = $path === '' ? null : $this->byFamily($path);
        if ($byFamily !== null && $byFamily['group'] === 'fora') {
            return $byFamily;
        }
        if ($description !== null && preg_match((string) ($this->cfg['deposit_pattern'] ?? '/$^/'), $description) === 1) {
            return ['group' => 'fora', 'reason' => 'Caução ou vasilhame: é um depósito, não um gasto.', 'by' => 'descricao'];
        }

        return $byFamily;
    }

    /** @return array{group: string, reason: ?string} */
    private function byFamily(string $path): array
    {
        $low = mb_strtolower($path);
        foreach ((array) ($this->cfg['rules'] ?? []) as $r) {
            if (str_contains($low, mb_strtolower((string) $r['match']))) {
                return ['group' => (string) $r['group'], 'reason' => $r['reason'] ?? null];
            }
        }

        return ['group' => 'outros', 'reason' => null];
    }
}
