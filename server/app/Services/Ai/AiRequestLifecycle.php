<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRequest;
use App\Services\AlertService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Ciclo de vida comum dos pedidos à IA (blog, perfil da marca, criativo, ideias), para o
 * ecrã nunca ficar preso:
 *  · complete() / fail(): grava o resultado ou o erro (motivo em linguagem simples) e
 *    avisa no sino, com a ligação para a página onde o resultado está à espera;
 *  · interrupted(): o trabalho morreu na fila (tempo esgotado, worker parado) sem gravar;
 *  · stalled(): 3 minutos sem resposta; o caso é registado uma vez (stalled_logged_at);
 *  · present(): o mesmo formato de resposta em todos os ecrãs, por modo.
 */
class AiRequestLifecycle
{
    public const STALL_SECONDS = 180;

    public const MSG_STALLED = 'Está a demorar mais do que o normal. O processamento pode estar parado.';
    public const MSG_INTERRUPTED = 'O processamento foi interrompido antes de terminar. Tente novamente.';

    public function __construct(private readonly AlertService $alerts) {}

    public function complete(AiRequest $request, array $fields): void
    {
        $request->update($fields + ['status' => AiRequest::DONE, 'error_message' => null]);
        $this->notify($request);
    }

    public function fail(AiRequest $request, \Throwable|string $reason): void
    {
        $message = is_string($reason) ? $reason : self::failureMessage($reason);
        // O estado HTTP quando o fornecedor respondeu: estes erros contam no limite mensal.
        $providerStatus = $reason instanceof AiProviderException ? $reason->status : null;
        $request->update(['status' => AiRequest::ERROR, 'error_message' => $message, 'provider_status' => $providerStatus]);
        $this->notify($request);
    }

    /** Chamado pelo failed() dos jobs: só fecha o pedido se ainda estiver pendente. */
    public function interrupted(int $requestId, ?\Throwable $e = null): void
    {
        $request = AiRequest::find($requestId);
        if (! $request || ! $request->isPending()) {
            return;
        }
        Log::warning('[IA] Pedido interrompido na fila', ['request_id' => $requestId, 'mode' => $request->mode, 'error' => mb_substr((string) $e?->getMessage(), 0, 300)]);
        $this->fail($request, self::MSG_INTERRUPTED);
    }

    /** Motivo da falha em linguagem simples (o detalhe técnico fica só no registo). */
    public static function failureMessage(\Throwable $e): string
    {
        $status = $e instanceof RequestException ? $e->response->status() : ($e instanceof AiProviderException ? $e->status : null);
        $text = $e->getMessage();

        return match (true) {
            str_contains($text, 'recusou') => 'O modelo de IA recusou este pedido. Reveja o pedido e tente de novo.',
            str_contains($text, 'OPENAI_KEY'), str_contains($text, 'ANTHROPIC_API_KEY'), str_contains($text, 'não configurada'), $status === 401, $status === 403
                => 'O serviço de IA não está configurado corretamente. Contacte o suporte da XPLENDOR.',
            $status === 429
                => 'O serviço de IA recebeu demasiados pedidos. Tente novamente dentro de alguns minutos.',
            $status !== null && $status >= 500, $e instanceof ConnectionException, str_contains($text, 'indisponível'), str_contains($text, 'não respondeu')
                => 'O serviço de IA não respondeu a tempo. Tente novamente dentro de alguns minutos.',
            str_contains($text, 'JSON'), str_contains($text, 'não devolveu'), str_contains($text, 'vazio'), str_contains($text, 'incompleta')
                => 'A resposta da IA veio incompleta. Tente novamente.',
            default => 'Não foi possível concluir o pedido. Tente novamente dentro de alguns minutos.',
        };
    }

    public function stalled(AiRequest $request): bool
    {
        return $request->isPending() && $request->created_at !== null
            && $request->created_at->lte(now()->subSeconds(self::STALL_SECONDS));
    }

    /** Regista (uma vez) um pedido parado há mais de 3 minutos. */
    public function logIfStalled(AiRequest $request): bool
    {
        if (! $this->stalled($request)) {
            return false;
        }
        if ($request->stalled_logged_at === null) {
            Log::warning('[IA] Pedido sem resposta há mais de 3 minutos', [
                'request_id' => $request->id, 'company_id' => $request->company_id, 'mode' => $request->mode,
                'status' => $request->status, 'created_at' => $request->created_at?->toIso8601String(),
            ]);
            $request->forceFill(['stalled_logged_at' => now()])->save();
        }

        return true;
    }

    // ── aviso no sino ─────────────────────────────────────────────────────────

    private function notify(AiRequest $r): void
    {
        $done = $r->status === AiRequest::DONE;
        [$doneTitle, $errorTitle, $path] = match ($r->mode) {
            AiRequest::MODE_BRAND_PROFILE => ['A sugestão do Perfil da Marca está pronta', 'Não foi possível gerar a sugestão do Perfil da Marca', '/brand-profile?suggestion=' . $r->id],
            AiRequest::MODE_CREATIVE      => ['A sugestão de criativo está pronta', 'Não foi possível gerar a sugestão de criativo', '/editorial?creative=' . $r->editorial_post_id],
            AiRequest::MODE_CAPTION       => ['As propostas de legenda estão prontas', 'Não foi possível gerar as propostas de legenda', $r->editorial_post_id ? '/editorial?publicacao=' . $r->editorial_post_id : '/marketing/bussola'],
            AiRequest::MODE_IDEAS         => ['As ideias do mês estão prontas', 'Não foi possível gerar as ideias do mês', sprintf('/editorial?ideas=%04d-%02d', (int) ($r->input['year'] ?? 0), (int) ($r->input['month'] ?? 0))],
            default                       => ['O rascunho do artigo está pronto', 'Não foi possível gerar o rascunho do artigo', $r->blog_id ? "/blogs/{$r->blog_id}?ai=1" : '/blogs/create?ai_request=' . $r->id],
        };

        try {
            $this->alerts->createSystemAlert(
                companyId: (int) $r->company_id,
                type: $done ? 'opportunity' : 'warning',
                title: $done ? $doneTitle : $errorTitle,
                message: $done ? 'O resultado está à espera na página. Reveja antes de usar.' : (string) $r->error_message,
                severity: $done ? 'low' : 'medium',
                detailPath: $path,
            );
        } catch (\Throwable $e) {
            Log::warning('[IA] Falha ao criar o aviso no sino', ['request_id' => $r->id, 'error' => $e->getMessage()]);
        }
    }

    // ── apresentação comum ────────────────────────────────────────────────────

    public function present(AiRequest $r): array
    {
        $stalled = $this->logIfStalled($r);
        $base = [
            'id'            => $r->id,
            'status'        => $r->status,
            'stalled'       => $stalled,
            'stalled_message' => $stalled ? self::MSG_STALLED : null,
            'created_at'    => optional($r->created_at)->toIso8601String(),
            'dismissed'     => $r->dismissed_at !== null,
            'result'        => $r->status === AiRequest::DONE ? $r->result : null,
            'error_message' => $r->error_message,
            // O verificador do português de Portugal: marcas que persistiram depois de pedir de novo (o ecrã mostra "Rever o português").
            'pt_review'     => $r->status === AiRequest::DONE && ! empty($r->pt_issues),
            'pt_issues'     => $r->status === AiRequest::DONE ? ($r->pt_issues ?? []) : [],
            'used'          => AiRequestQuota::used((int) $r->company_id, $r->mode),
            'cap'           => AiRequestQuota::cap($r->mode),
        ];

        return $base + match ($r->mode) {
            AiRequest::MODE_BRAND_PROFILE => [
                'template' => $r->input['template'] ?? null,
                'audience_warning' => $r->context['audience']['warning'] ?? null,
            ],
            AiRequest::MODE_CREATIVE => ['post_id' => $r->editorial_post_id],
            AiRequest::MODE_CAPTION => ['post_id' => $r->editorial_post_id, 'images_sent' => $r->context['images_sent'] ?? null],
            AiRequest::MODE_IDEAS => ['year' => (int) ($r->input['year'] ?? 0), 'month' => (int) ($r->input['month'] ?? 0)],
            // A API do blog chama "mode" ao subtipo (topic | from_post).
            default => [
                'mode' => $r->variant,
                'blog_id' => $r->blog_id,
                'input' => array_intersect_key((array) $r->input, array_flip(['topic', 'source_text', 'keyword', 'secondary_keywords', 'notes'])),
                'audience_warning' => $r->context['audience']['warning'] ?? null,
            ],
        };
    }
}
