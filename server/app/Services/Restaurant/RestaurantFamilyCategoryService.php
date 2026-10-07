<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\PingwinItemSale;
use App\Models\RestaurantFamilyCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — F1-3: categorias de marketing das famílias do PingWin.
 *  · as famílias vêm das vendas por artigo (as que tiveram vendas);
 *  · primeiro sugerem as regras (FamilyCategoryRules); a IA só nas que sobrarem;
 *  · a categoria só fica confirmada por uma pessoa da equipa (nunca automaticamente).
 */
class RestaurantFamilyCategoryService
{
    /** Janela do peso de cada família na faturação. */
    public const REVENUE_DAYS = 90;

    public function __construct(private readonly FamilyCategoryAiSuggester $ai) {}

    /**
     * Garante uma linha por família com vendas e (re)aplica as regras às sugestões que
     * não vieram da IA. Nunca mexe na categoria confirmada.
     */
    public function refresh(int $companyId): void
    {
        $families = PingwinItemSale::where('company_id', $companyId)
            ->whereNotNull('family_pingwin_id')
            ->select('family_pingwin_id', DB::raw('MAX(family_path) as family_path'))
            ->groupBy('family_pingwin_id')
            ->get();

        foreach ($families as $f) {
            $row = RestaurantFamilyCategory::firstOrNew(['company_id' => $companyId, 'family_pingwin_id' => (string) $f->family_pingwin_id]);
            $row->family_path = $f->family_path;
            if ($row->suggested_by !== 'ai') {
                $suggested = FamilyCategoryRules::suggest($f->family_path);
                $row->suggested_category = $suggested;
                $row->suggested_by = $suggested ? 'rules' : null;
            }
            $row->save();
        }
    }

    /**
     * Pede à IA sugestões para as famílias sem sugestão e sem categoria confirmada. Só
     * aceita chaves de categoria válidas. Devolve o número de sugestões novas.
     */
    public function suggestWithAi(int $companyId, ?int $userId = null): int
    {
        $this->refresh($companyId);
        $pending = RestaurantFamilyCategory::where('company_id', $companyId)
            ->whereNull('category')->whereNull('suggested_category')
            ->orderBy('family_path')->get();
        if ($pending->isEmpty()) {
            return 0;
        }

        $answers = $this->ai->suggest($companyId, $userId, $pending->pluck('family_path')->filter()->values()->all());
        $count = 0;
        foreach ($pending as $row) {
            $key = $answers[$row->family_path] ?? null;
            if (FamilyCategoryRules::isValid($key)) {
                $row->forceFill(['suggested_category' => $key, 'suggested_by' => 'ai'])->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * Lista para o ecrã: família, peso na faturação dos últimos 90 dias, sugestão e
     * categoria confirmada. Ordenada pelo peso.
     */
    public function list(int $companyId): array
    {
        $this->refresh($companyId);
        $from = CarbonImmutable::today()->subDays(self::REVENUE_DAYS)->toDateString();
        $revenue = PingwinItemSale::where('company_id', $companyId)->where('business_date', '>=', $from)
            ->whereNotNull('family_pingwin_id')
            ->select('family_pingwin_id', DB::raw('SUM(net_cents) as net'))
            ->groupBy('family_pingwin_id')
            ->pluck('net', 'family_pingwin_id');
        $total = max(0, (int) $revenue->sum());

        $users = User::whereIn('id', RestaurantFamilyCategory::where('company_id', $companyId)->whereNotNull('confirmed_by_user_id')->pluck('confirmed_by_user_id'))
            ->pluck('name', 'id');

        $rows = RestaurantFamilyCategory::where('company_id', $companyId)->get()->map(function (RestaurantFamilyCategory $r) use ($revenue, $total, $users) {
            $net = (int) ($revenue[$r->family_pingwin_id] ?? 0);

            return [
                'family_pingwin_id' => $r->family_pingwin_id,
                'family_path' => $r->family_path,
                'family' => FamilyCategoryRules::leaf($r->family_path),
                'net_cents_90d' => $net,
                'share_pct' => $total > 0 ? round($net / $total * 100, 1) : 0.0,
                'suggested_category' => $r->suggested_category,
                'suggested_by' => $r->suggested_by,
                'category' => $r->category,
                'confirmed_by' => $r->confirmed_by_user_id ? ($users[$r->confirmed_by_user_id] ?? null) : null,
                'confirmed_at' => $r->confirmed_at?->toIso8601String(),
            ];
        })->sortByDesc('net_cents_90d')->values()->all();

        return [
            'categories' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(FamilyCategoryRules::CATEGORIES), FamilyCategoryRules::CATEGORIES),
            'families' => $rows,
            'total_net_cents_90d' => $total,
            'pending' => count(array_filter($rows, fn ($r) => $r['category'] === null)),
        ];
    }

    /**
     * Confirma (ou muda) a categoria de uma ou mais famílias. Ação de uma pessoa da equipa:
     * fica registado quem e quando.
     *
     * @param  array<int, array{family_pingwin_id: string, category: string}>  $items
     */
    public function confirm(int $companyId, array $items, User $user): int
    {
        $count = 0;
        DB::transaction(function () use ($companyId, $items, $user, &$count) {
            foreach ($items as $item) {
                if (! FamilyCategoryRules::isValid($item['category'] ?? null)) {
                    throw ValidationException::withMessages(['category' => ['Categoria inválida.']]);
                }
                $row = RestaurantFamilyCategory::where('company_id', $companyId)
                    ->where('family_pingwin_id', (string) $item['family_pingwin_id'])->first();
                if (! $row) {
                    throw ValidationException::withMessages(['family_pingwin_id' => ['Família desconhecida.']]);
                }
                $row->forceFill(['category' => $item['category'], 'confirmed_by_user_id' => $user->id, 'confirmed_at' => now()])->save();
                $count++;
            }
        });

        return $count;
    }
}
