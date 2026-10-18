<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinUnitWrite;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\Menu\RecipeSubstituteService;
use App\Services\Restaurant\Units\UnitProfileService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Auditoria v2: o Guião de unidades (Restauração › Auditoria › Guião de unidades). A fila
 * pelo Pareto, a vista de um artigo e as decisões (confirmar, ajustar, saltar, não se aplica, dar
 * mais informação, perguntar à IA, enviar ao administrador), o "Aplicar no PingWin" (um passo de
 * cada vez, com releitura) e o substituto do M2. Ver com restauracao.ver; decidir com
 * restauracao.editar (a ACL das rotas). Os euros só com financas.ver.
 */
class CompanyUnitGuideController extends Controller
{
    public function queue(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate(['fila' => ['nullable', Rule::in(['todos', 'por_identificar', 'administrador'])]]);
        $data = (new UnitProfileService($companyId))->queue($v['fila'] ?? 'todos');

        return ApiResponse::success($this->redact($request, $companyId, $data), 'Guião de unidades.');
    }

    public function show(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return $this->viewResponse($request, $companyId, (new UnitProfileService($companyId))->view($productId));
    }

    /** As respostas às perguntas, sem gravar: a proposta e o "O que muda" refazem-se. */
    public function preview(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return $this->viewResponse($request, $companyId, (new UnitProfileService($companyId))->view($productId, $this->answers($request)));
    }

    public function confirm(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $view = (new UnitProfileService($companyId))->confirm($productId, $this->answers($request), $request->user(), $reason);

        return $this->viewResponse($request, $companyId, $view, 'Confirmado na XPLENDOR. A engenharia de menu e os achados vão ser recalculados.');
    }

    /** "Confirmar todos os que estão certos" (só na XPLENDOR; pode desfazer-se). */
    public function confirmAll(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate(['product_ids' => ['required', 'array', 'min:1', 'max:500'], 'product_ids.*' => ['string', 'max:32'], 'confirm' => ['required', 'accepted']]);
        $r = (new UnitProfileService($companyId))->confirmAll($v['product_ids'], $request->user());

        return ApiResponse::success($r, sprintf('%d %s confirmados na XPLENDOR.', count($r['confirmed']), count($r['confirmed']) === 1 ? 'artigo' : 'artigos'));
    }

    public function undoBatch(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $batch = (string) $request->validate(['batch' => ['required', 'string', 'max:40']])['batch'];
        $r = (new UnitProfileService($companyId))->undoBatch($batch, $request->user());

        return ApiResponse::success($r, 'Confirmação desfeita.');
    }

    public function adjust(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return $this->viewResponse($request, $companyId, (new UnitProfileService($companyId))->adjust($productId, $this->answers($request), $request->user()), 'Respostas guardadas.');
    }

    public function skip(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        (new UnitProfileService($companyId))->skip($productId, $request->user());

        return ApiResponse::success(['skipped' => true], 'Saltado: volta ao fim da fila.');
    }

    public function notApplicable(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return $this->viewResponse($request, $companyId, (new UnitProfileService($companyId))->notApplicable($productId, $reason, $request->user()), 'Marcado como «Não se aplica».');
    }

    /** "Dar mais informação": texto, foto opcional (privada) e respostas; reavalia logo. */
    public function info(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate([
            'text' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:12288'],
        ]);
        if (trim((string) ($v['text'] ?? '')) === '' && ! $request->hasFile('photo') && ! $this->answers($request)) {
            return ApiResponse::error('Escreva o que sabe do artigo ou junte uma foto.', 422);
        }
        $view = (new UnitProfileService($companyId))->moreInfo($productId, $v['text'] ?? null, $request->file('photo'), $this->answers($request), $request->user());

        return $this->viewResponse($request, $companyId, $view, 'Informação guardada: o artigo foi reavaliado.');
    }

    public function ai(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        try {
            $view = (new UnitProfileService($companyId))->askAi($productId, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return ApiResponse::error('A IA não respondeu: ' . mb_substr($e->getMessage(), 0, 200), 502);
        }

        return $this->viewResponse($request, $companyId, $view, 'Sugestão da IA, a confirmar.');
    }

    public function sendAdmin(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $this->viewResponse($request, $companyId, (new UnitProfileService($companyId))->sendToAdmin($productId, $request->user(), $note), 'Enviado ao administrador.');
    }

    /** "Aplicar no PingWin": um passo do plano (o ecrã já pediu a confirmação). */
    public function apply(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate(['step' => ['required', 'integer', 'min:0', 'max:50'], 'confirm' => ['required', 'accepted']]);
        $w = (new UnitProfileService($companyId))->applyStep($productId, (int) $v['step'], $request->user());

        return ApiResponse::success(['write' => $w->toApi()], 'A gravar no PingWin…');
    }

    public function write(Request $request, int $companyId, int $writeId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $w = PingwinUnitWrite::where('company_id', $companyId)->find($writeId);

        return $w ? ApiResponse::success(['write' => $w->toApi()], 'Escrita.') : ApiResponse::error('Escrita não encontrada.', 404);
    }

    /** U9: a unidade global incoerente com o nome: a unidade certa e os artigos, "1 de N". */
    public function globalUnit(Request $request, int $companyId, string $unitId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success($this->redact($request, $companyId, (new UnitProfileService($companyId))->globalUnitView($unitId)), 'Unidade global.');
    }

    /** U9: criar a unidade certa, uma vez (o ecrã já pediu a confirmação). */
    public function applyGlobalUnit(Request $request, int $companyId, string $unitId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $request->validate(['confirm' => ['required', 'accepted']]);
        $w = (new UnitProfileService($companyId))->applyGlobalUnit($unitId, $request->user());

        return ApiResponse::success(['write' => $w->toApi()], 'A gravar no PingWin…');
    }

    /** M2: o substituto de um ingrediente sem compras, com o custo antes e depois. */
    public function substitute(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $component = (string) $request->validate(['component' => ['required', 'string', 'max:32']])['component'];
        $data = (new RecipeSubstituteService($companyId))->for($productId, $component);

        return ApiResponse::success($this->redact($request, $companyId, $data), 'Substituto.');
    }

    private function answers(Request $request): array
    {
        $raw = $request->input('answers', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        $out = [];
        foreach ((array) $raw as $k => $v) {
            if (is_string($k) && strlen($k) <= 120 && (is_scalar($v) || $v === null)) {
                $out[$k] = $v === null ? null : mb_substr((string) $v, 0, 120);
            }
        }

        return $out;
    }

    private function viewResponse(Request $request, int $companyId, array $view, string $message = 'Guião de unidades.')
    {
        return ApiResponse::success($this->redact($request, $companyId, $view), $message);
    }

    /** Sem financas.ver: tira os euros (as chaves *_eur e *_cents), como no resto da restauração. */
    private function redact(Request $request, int $companyId, array $data): array
    {
        if (CompassFinancials::visibleTo($request->user(), $companyId)) {
            return $data + ['financial' => ['visible' => true]];
        }
        $walk = function ($v) use (&$walk) {
            if (! is_array($v)) {
                return $v;
            }
            foreach ($v as $k => $x) {
                if (is_string($k) && (str_ends_with($k, '_eur') || str_ends_with($k, '_cents') || in_array($k, ['price_per_unit', 'price_per_base', 'family_median', 'pcm'], true))) {
                    $v[$k] = null;
                } else {
                    $v[$k] = $walk($x);
                }
            }

            return $v;
        };

        return $walk($data) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }
}
