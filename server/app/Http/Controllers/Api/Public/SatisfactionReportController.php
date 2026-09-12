<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\SatisfactionReportPublicResource;
use App\Models\SatisfactionReport;
use App\Models\SatisfactionReportPhoto;
use App\Services\SatisfactionReportPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DMS — endpoint PÚBLICO do relatório de satisfação (Incremento 1, read-only).
 *
 * Gated pelo middleware resolve_report_token: o relatório já vem resolvido no
 * request pelo public_token. Marca a 1.ª abertura e devolve branding + fotos da
 * viatura. Sem login, sem PII do comprador.
 */
class SatisfactionReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var SatisfactionReport $report */
        $report = $request->input('satisfaction_report');

        // 1.ª abertura → carimba opened_at e passa a 'opened' (sem tocar em
        // 'submitted', reservado para os incrementos com estrelas).
        if ($report->status === 'pending') {
            $report->forceFill([
                'status'    => 'opened',
                'opened_at' => now(),
            ])->save();
        }

        $report->load(['company', 'carSale', 'car.brand', 'car.model', 'car.images', 'photos']);

        return ApiResponse::success(
            (new SatisfactionReportPublicResource($report))->resolve(),
            'Satisfaction report fetched successfully.'
        );
    }

    /**
     * Submissão PÚBLICA da avaliação (estrelas + comentário). UMA só vez: depois
     * de submetida, a avaliação FECHA e novas submissões são recusadas (não
     * baralhar métricas). Ramo ≥4 → comentário em public_message (copiável p/
     * Google); ramo <4 → comentário em internal_feedback (privado).
     */
    public function storeRating(Request $request): JsonResponse
    {
        /** @var SatisfactionReport $report */
        $report = $request->input('satisfaction_report');

        if ($report->status === 'submitted' || $report->submitted_at !== null) {
            return ApiResponse::error('Esta avaliação já foi submetida.', 422);
        }

        $data = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating  = (int) $data['rating'];
        $comment = $data['comment'] ?? null;

        $report->forceFill([
            'rating'            => $rating,
            'public_message'    => $rating >= 4 ? $comment : null,
            'internal_feedback' => $rating < 4 ? $comment : null,
            'status'            => 'submitted',
            'submitted_at'      => now(),
        ])->save();

        return ApiResponse::success([
            'status'         => 'submitted',
            'rating'         => $rating,
            'review_message' => $report->public_message,
        ], 'Avaliação submetida. Obrigado!');
    }

    /**
     * Upload PÚBLICO de uma foto do cliente. Validação estrita + re-encode +
     * teto de 3 no servidor. O acto de carregar É o consentimento (registado).
     */
    public function storePhoto(Request $request, SatisfactionReportPhotoService $service): JsonResponse
    {
        /** @var SatisfactionReport $report */
        $report = $request->input('satisfaction_report');

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:8192'], // 8 MB
        ]);

        if ($service->count($report) >= SatisfactionReportPhotoService::MAX_PHOTOS) {
            return ApiResponse::error('Já atingiu o máximo de 3 fotos.', 422);
        }

        $photo = $service->upload($report, $request->file('photo'));

        return ApiResponse::success(
            ['id' => $photo->id, 'url' => $photo->path],
            'Foto carregada com sucesso.',
            201
        );
    }

    /**
     * Apaga uma foto do próprio relatório (RGPD — remove do storage de verdade).
     */
    public function destroyPhoto(Request $request, SatisfactionReportPhotoService $service): JsonResponse
    {
        /** @var SatisfactionReport $report */
        $report  = $request->input('satisfaction_report');
        $photoId = (int) $request->route('photo');

        // A foto TEM de pertencer a este relatório (o token só manda no seu).
        $model = SatisfactionReportPhoto::where('id', $photoId)
            ->where('satisfaction_report_id', $report->id)
            ->first();

        if (! $model) {
            return ApiResponse::error('Foto não encontrada.', 404);
        }

        $service->delete($model);

        return ApiResponse::success(['id' => $photoId], 'Foto removida.');
    }
}
