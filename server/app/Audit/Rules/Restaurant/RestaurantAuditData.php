<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditScope;
use App\Models\PingwinCatalogItem;
use App\Modules\ModuleRegistry;
use App\Services\CompanyModuleService;
use App\Services\Restaurant\RecipeCostService;
use App\Services\Restaurant\RecipeHints;
use App\Services\Restaurant\RecipeReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * XPLENDOR — Auditoria da restauração: os dados do espelho do PingWin, carregados uma vez por
 * corrida e partilhados pelas regras (catálogo, compras com a quantidade base, vendas, avisos das
 * fichas). Só lê; nunca chama o PingWin.
 */
final class RestaurantAuditData
{
    private ?array $catalog = null;
    private ?array $purchases = null;
    private ?array $sales90 = null;
    private ?array $recipeWarnings = null;
    private ?array $families = null;
    private RecipeCostService $cost;

    public function __construct(public readonly int $companyId)
    {
        $this->cost = new RecipeCostService($companyId);
    }

    public static function for(AuditScope $scope): self
    {
        return $scope->memo('restauracao.data', fn () => new self($scope->companyId));
    }

    /** A restauração está ligada (o módulo do PingWin). */
    public static function enabled(int $companyId): bool
    {
        return app(CompanyModuleService::class)->isEnabled($companyId, 'pingwin');
    }

    public function cost(): RecipeCostService
    {
        return $this->cost;
    }

    public function today(): string
    {
        return $this->cost->today()->toDateString();
    }

    public function daysAgo(int $days): string
    {
        return $this->cost->today()->subDays($days)->toDateString();
    }

    /** @return array<string, PingwinCatalogItem> o catálogo inteiro, por pingwin_id */
    public function catalog(): array
    {
        if ($this->catalog === null) {
            $this->catalog = PingwinCatalogItem::where('company_id', $this->companyId)->get()
                ->keyBy(fn ($i) => (string) $i->pingwin_id)->all();
        }

        return $this->catalog;
    }

    public function item(string $pid): ?PingwinCatalogItem
    {
        return $this->catalog()[$pid] ?? null;
    }

    /** O rótulo de um artigo: "código · descrição". */
    public function label(string $pid, ?string $fallback = null): string
    {
        $i = $this->item($pid);

        return $i ? trim(($i->code ? ltrim((string) $i->code, "\u{FEFF}") . ' · ' : '') . $i->description) : ($fallback ?? $pid);
    }

    /** A família do artigo, sem a raiz "Família": "COMPRAS › COMIDAS › FRUTA". */
    public static function familyPath(?string $path): string
    {
        $parts = array_values(array_filter(array_map(fn ($p) => trim(preg_replace('/\s+/', ' ', $p)), explode('\\', (string) $path))));
        if ($parts && mb_strtolower($parts[0]) === 'família') {
            array_shift($parts);
        }

        return implode(' › ', $parts);
    }

    /** A folha da família (o último nível). */
    public static function familyLeaf(?string $path): string
    {
        $parts = explode(' › ', self::familyPath($path));

        return (string) end($parts);
    }

    /** As famílias do PingWin por pingwin_id. */
    public function families(): array
    {
        if ($this->families === null) {
            $this->families = DB::table('pingwin_families')->where('company_id', $this->companyId)->get()
                ->keyBy(fn ($f) => (string) $f->pingwin_id)->all();
        }

        return $this->families;
    }

    /**
     * As linhas de compra (faturas fechadas) dos últimos 365 dias, por artigo, com a quantidade base
     * (a mesma lógica do custo das fichas).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function purchases(): array
    {
        if ($this->purchases === null) {
            $this->purchases = $this->cost->purchaseLines(null, $this->daysAgo(365));
            $this->cost->ensureItems(array_keys($this->purchases));
        }

        return $this->purchases;
    }

    /** As vendas por artigo nos últimos 90 dias: [qty, net_cents]. */
    public function sales90(): array
    {
        if ($this->sales90 === null) {
            $this->sales90 = [];
            foreach (DB::table('pingwin_item_sales_daily')->where('company_id', $this->companyId)
                ->whereBetween('business_date', [$this->daysAgo(RecipeCostService::WINDOW_DAYS), $this->today()])
                ->groupBy('product_pingwin_id')
                ->get(['product_pingwin_id', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(net_cents) as net')]) as $r) {
                $this->sales90[(string) $r->product_pingwin_id] = ['qty' => (float) $r->qty, 'net' => (int) $r->net];
            }
        }

        return $this->sales90;
    }

    /** Os avisos das fichas (o mesmo cálculo do detalhe da ficha técnica). */
    public function recipeWarnings(): array
    {
        if ($this->recipeWarnings === null) {
            $this->recipeWarnings = (new RecipeReportService($this->companyId))->auditWarnings();
        }

        return $this->recipeWarnings;
    }

    /**
     * O nome normalizado para comparar artigos (prováveis duplicados, famílias): sem acentos, sem os
     * prefixos de fornecedor (MC/PE), sem calibres nem tokens com números, sem classes I/II/III.
     *
     * @return list<string>
     */
    public static function nameKeyTokens(?string $description): array
    {
        $tokens = RecipeHints::nameTokens((string) $description);
        $out = [];
        foreach ($tokens as $t) {
            $t = Str::upper(Str::ascii($t));
            if (preg_match('/\d/', $t) || in_array($t, ['I', 'II', 'III', 'IV', 'EXTRA', 'CAT', 'CALIBRE'], true)) {
                continue;
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    public static function moduleKeys(): array
    {
        return ModuleRegistry::keys();
    }
}
