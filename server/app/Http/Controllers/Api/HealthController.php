<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Health\SystemHealth;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/health (público, para o serviço externo de monitorização): 200 quando tudo está bem,
 * 503 quando alguma verificação falha. Sem detalhes (os detalhes estão em /admin/health, só root).
 */
class HealthController extends Controller
{
    public function __invoke(SystemHealth $health): JsonResponse
    {
        $ok = SystemHealth::healthy($health->run());

        return response()->json(['status' => $ok ? 'ok' : 'falha'], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
