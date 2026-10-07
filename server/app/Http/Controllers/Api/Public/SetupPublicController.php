<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\CompanySetupLink;
use App\Services\Setup\SetupPublicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Página pública do link de configuração (/configurar#<token>), sem conta. O token chega só
 * no cabeçalho X-Setup-Token (nunca no endereço). Todas as respostas: noindex, sem referrer
 * e sem cache. Limites de pedidos por IP e por link (AppServiceProvider).
 */
class SetupPublicController extends Controller
{
    public function __construct(private readonly SetupPublicService $setup) {}

    // GET /api/public/setup
    public function show(Request $request): JsonResponse
    {
        return $this->noindex(response()->json(['data' => $this->setup->present($this->link($request))]));
    }

    // POST /api/public/setup/open
    public function open(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $this->setup->open($request, $link);

        return $this->noindex(response()->json(['data' => ['ok' => true]]));
    }

    // GET /api/public/setup/social/auth-url
    public function socialAuthUrl(Request $request): JsonResponse
    {
        return $this->noindex(response()->json(['data' => ['url' => $this->setup->socialAuthUrl($this->link($request))]]));
    }

    // GET /api/public/setup/social/candidates
    public function socialCandidates(Request $request): JsonResponse
    {
        return $this->noindex(response()->json(['data' => $this->setup->socialCandidates($this->link($request))]));
    }

    // PUT /api/public/setup/social/accounts
    public function socialSave(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $data = $request->validate([
            'facebook' => ['present', 'array', 'max:50'],
            'facebook.*' => ['string', 'regex:/^\d{1,40}$/'],
            'instagram' => ['present', 'array', 'max:50'],
            'instagram.*' => ['string', 'regex:/^\d{1,40}$/'],
            'primary_facebook' => ['nullable', 'string'],
            'primary_instagram' => ['nullable', 'string'],
        ]);
        $data['facebook'] = array_values(array_unique($data['facebook']));
        $data['instagram'] = array_values(array_unique($data['instagram']));

        return $this->noindex(response()->json(['data' => $this->setup->socialSave($link, $data), 'message' => 'Contas guardadas.']));
    }

    // GET /api/public/setup/ads/auth-url
    public function adsAuthUrl(Request $request): JsonResponse
    {
        return $this->noindex(response()->json(['data' => ['url' => $this->setup->adsAuthUrl($this->link($request))]]));
    }

    // GET /api/public/setup/ads/accounts
    public function adsAccounts(Request $request): JsonResponse
    {
        return $this->noindex(response()->json(['data' => $this->setup->adsAccounts($this->link($request))]));
    }

    // PUT /api/public/setup/ads/account   { account_id }
    public function adsSave(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $data = $request->validate(['account_id' => ['required', 'string', 'max:40']], ['account_id.required' => 'Escolha a conta de anúncios.']);

        return $this->noindex(response()->json(['data' => $this->setup->adsSave($link, $data['account_id']), 'message' => 'Conta de anúncios guardada.']));
    }

    // POST /api/public/setup/ga4/verify   { property_id }
    public function ga4Verify(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $data = $request->validate(['property_id' => ['required', 'string', 'max:20']], ['property_id.required' => 'Indique o ID da propriedade.']);

        return $this->noindex(response()->json(['data' => $this->setup->ga4Verify($link, trim($data['property_id']))]));
    }

    private function link(Request $request): CompanySetupLink
    {
        $token = (string) $request->header('X-Setup-Token', '');
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            abort(404, 'Link não encontrado.');
        }

        return $this->setup->resolve($token);
    }

    private function noindex(JsonResponse $response): JsonResponse
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
