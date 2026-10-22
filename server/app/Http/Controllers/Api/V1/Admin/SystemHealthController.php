<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Health\SystemHealth;
use Illuminate\Support\Facades\DB;

/** /admin: a saúde da XPLENDOR com o detalhe de cada verificação. Só o root (grupo /admin). */
class SystemHealthController extends Controller
{
    // GET /admin/health
    public function index(SystemHealth $health)
    {
        $results = $health->run();
        $rows = DB::table(SystemHealth::TABLE)->get()->keyBy('name');
        $checks = [];
        foreach ($results as $name => $r) {
            $row = $rows[$name] ?? null;
            $checks[] = ['name' => $name, 'label' => SystemHealth::LABELS[$name]] + $r + [
                'last_ok_at' => $row?->last_ok_at, 'failing_since' => $row?->failing_since, 'alerted_at' => $row?->alerted_at,
            ];
        }

        return ApiResponse::success(['healthy' => SystemHealth::healthy($results), 'checks' => $checks], 'Saúde do sistema.');
    }
}
