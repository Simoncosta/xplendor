<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EditorialPost;
use App\Services\CompanyModuleService;
use App\Services\Social\FollowerSnapshotService;
use Carbon\CarbonImmutable;

/**
 * Dashboard base (empresas sem os módulos de viaturas nem de restauração): a Linha
 * Editorial do mês e os seguidores. Cada bloco só aparece com o seu módulo ativo
 * (o cartão das faturas da XPLENDOR tem o seu próprio pedido).
 */
class CompanyBaseDashboardController extends Controller
{
    public function __construct(
        private readonly CompanyModuleService $modules,
        private readonly FollowerSnapshotService $followers,
    ) {}

    public function show(int $companyId)
    {
        $enabled = $this->modules->enabledKeys($companyId);
        $data = ['modules' => $enabled, 'editorial' => null, 'followers' => null];

        if (in_array('linha_editorial', $enabled, true)) {
            $now = CarbonImmutable::now(EditorialPost::TIMEZONE);
            $month = $now->format('Y-m');
            $byStage = EditorialPost::where('company_id', $companyId)
                ->whereBetween('publish_date', [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()])
                ->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage')->map(fn ($n) => (int) $n);
            $scheduled = EditorialPost::where('company_id', $companyId)->where('stage', EditorialPost::STAGE_SCHEDULED)
                ->where('channel', '!=', EditorialPost::CHANNEL_SITE)->whereDate('publish_date', '<=', $now->toDateString())->with('networks')->get();
            $data['editorial'] = [
                'month' => $month,
                'by_stage' => (object) $byStage->all(),
                'total' => (int) $byStage->sum(),
                'today' => $scheduled->filter(fn ($p) => $p->publish_date->toDateString() === $now->toDateString())->count(),
                'overdue' => $scheduled->filter(fn ($p) => $p->isOverdue())->count(),
                'awaiting' => EditorialPost::where('company_id', $companyId)->where('stage', EditorialPost::STAGE_CLIENT_REVIEW)->count(),
            ];
        }

        // Seguidores: do Perfil da Marca (Linha Editorial ou Marketing).
        if (array_intersect(['linha_editorial', 'marketing_analytics'], $enabled)) {
            $overview = $this->followers->overview($companyId, 30);
            $data['followers'] = collect($overview['platforms'])->map(function (array $p) {
                $first = collect($p['series'])->first(fn ($d) => $d['count'] !== null);

                return [
                    'current' => $p['current'],
                    'growth_30d' => $p['current'] && $first ? $p['current']['count'] - $first['count'] : null,
                    'since' => $first['date'] ?? null,
                ];
            })->all();
        }

        return ApiResponse::success($data, 'Dashboard base.');
    }
}
