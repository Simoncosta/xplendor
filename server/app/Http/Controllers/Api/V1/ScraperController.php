<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ScraperExecution;
use App\Services\ScraperService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ScraperController extends Controller
{
    public function __construct(
        protected ScraperService $scraperService,
    ) {}

    /**
     * POST /api/v1/companies/{id}/scraper/run
     */
    public function run(Request $request, int $id)
    {
        $user = Auth::user();

        if ($user->company_id !== $id && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado.', 403);
        }

        $validated = $request->validate([
            'source'  => ['required', 'string'],
            'mode'    => ['required', 'string', 'in:preview,run'],
            'filters' => ['sometimes', 'array'],
        ]);

        try {
            $execution = $this->scraperService->run(
                $validated['source'],
                $validated['mode'],
                $validated['filters'] ?? [],
                $id,
            );
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }

        return ApiResponse::success([
            'run_id' => $execution->id,
            'status' => $execution->status,
            'source' => $execution->source,
            'mode'   => $execution->mode,
        ], 'Scraping iniciado.', 202);
    }

    /**
     * GET /api/v1/companies/{id}/scraper/executions
     */
    public function executions(Request $request, int $id)
    {
        $user = Auth::user();

        if ($user->company_id !== $id && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado.', 403);
        }

        $perPage = (int) $request->input('per_page', 20);

        $executions = ScraperExecution::where('company_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $executions->getCollection()->transform(function ($execution) {
            $duration = ($execution->started_at && $execution->finished_at)
                ? (int) $execution->started_at->diffInSeconds($execution->finished_at)
                : null;

            return [
                'id'               => $execution->id,
                'source'           => $execution->source,
                'mode'             => $execution->mode,
                'status'           => $execution->status,
                'total_raw'        => $execution->total_raw,
                'total_normalized' => $execution->total_normalized,
                'total_sent'       => $execution->total_sent,
                'total_failed'     => $execution->total_failed,
                'duration_seconds' => $duration,
                'created_at'       => $execution->created_at->toIso8601String(),
            ];
        });

        return ApiResponse::success($executions);
    }

    /**
     * GET /api/v1/companies/{id}/scraper/executions/{runId}
     */
    public function show(Request $request, int $id, int $runId)
    {
        $user = Auth::user();

        if ($user->company_id !== $id && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado.', 403);
        }

        $execution = ScraperExecution::where('id', $runId)
            ->where('company_id', $id)
            ->first();

        if (!$execution) {
            return ApiResponse::error('Execucao nao encontrada.', 404);
        }

        $duration = ($execution->started_at && $execution->finished_at)
            ? (int) $execution->started_at->diffInSeconds($execution->finished_at)
            : null;

        return ApiResponse::success([
            'id'               => $execution->id,
            'status'           => $execution->status,
            'source'           => $execution->source,
            'mode'             => $execution->mode,
            'total_raw'        => $execution->total_raw,
            'total_normalized' => $execution->total_normalized,
            'total_sent'       => $execution->total_sent,
            'total_failed'     => $execution->total_failed,
            'error_message'    => $execution->error,
            'logs_excerpt'     => $execution->logs_excerpt,
            'started_at'       => $execution->started_at?->toIso8601String(),
            'finished_at'      => $execution->finished_at?->toIso8601String(),
            'duration_seconds' => $duration,
        ]);
    }
}
