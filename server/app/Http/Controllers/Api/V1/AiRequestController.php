<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Services\Ai\AiRequestLifecycle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pedidos à IA "à espera": ao voltar à página, o ecrã pede o último pedido daquele contexto
 * (últimas 24 horas, ainda não usado nem descartado) e retoma-o: continua a consultar se
 * estiver pendente, mostra o resultado se estiver pronto ou o motivo se falhou.
 * O middleware tenant garante a empresa da rota; tudo é procurado dentro dela.
 */
class AiRequestController extends Controller
{
    private const WINDOW_HOURS = 24;

    public function __construct(private readonly AiRequestLifecycle $lifecycle) {}

    public function latest(Request $request, int $companyId)
    {
        $data = $request->validate([
            'mode'              => ['required', Rule::in(AiRequest::MODES)],
            'editorial_post_id' => ['nullable', 'integer'],
            'blog_id'           => ['nullable', 'integer'],
            'year'              => ['nullable', 'integer'],
            'month'             => ['nullable', 'integer'],
        ]);

        $query = AiRequest::where('company_id', $companyId)
            ->where('mode', $data['mode'])
            ->whereNull('dismissed_at')
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderByDesc('id');

        match ($data['mode']) {
            AiRequest::MODE_CREATIVE, AiRequest::MODE_CAPTION => $query->where('editorial_post_id', (int) ($data['editorial_post_id'] ?? 0)),
            // Artigo ainda por gravar: só os pedidos do próprio utilizador, sem artigo.
            AiRequest::MODE_BLOG => ! empty($data['blog_id'])
                ? $query->where('blog_id', (int) $data['blog_id'])
                : $query->whereNull('blog_id')->where('user_id', $request->user()->id),
            default => null,
        };

        $found = $query->limit(20)->get()->first(function (AiRequest $r) use ($data) {
            return $data['mode'] !== AiRequest::MODE_IDEAS
                || ((int) ($r->input['year'] ?? 0) === (int) ($data['year'] ?? -1) && (int) ($r->input['month'] ?? 0) === (int) ($data['month'] ?? -1));
        });

        return ApiResponse::success($found ? $this->lifecycle->present($found) : null, $found ? 'Pedido encontrado.' : 'Sem pedidos à espera.');
    }

    /** O resultado foi usado ou descartado: deixa de ficar à espera. */
    public function dismiss(int $companyId, int $requestId)
    {
        $r = AiRequest::where('company_id', $companyId)->find($requestId);
        if (! $r) {
            return ApiResponse::error('Pedido não encontrado.', 404);
        }
        $r->forceFill(['dismissed_at' => now()])->save();

        return ApiResponse::success($this->lifecycle->present($r), 'Pedido arquivado.');
    }
}
