<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FinancingLink;
use App\Services\Financing\FinancingPublicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A página de envio de documentos (sem conta) e a API que o site usa com o mesmo código: o pedido
 * de financiamento de um titular ou a retoma de uma lead. O código nunca vai no caminho: o link é
 * /enviar-documentos#<código> e a página (ou o site) envia-o no cabeçalho X-Financing-Token.
 * Não há rota para listar nem para descarregar ficheiros. Todas as respostas: noindex,
 * no-referrer e sem cache.
 *
 * Origem: a partir de um browser, só a página da XPLENDOR e os sites da empresa do código (o
 * endereço do site e o do modelo de endereço das viaturas). O pedido prévio (OPTIONS) segue a
 * lista de origens do CORS (config/cors.php), porque ainda não traz o código.
 */
class FinancingPublicController extends Controller
{
    public function __construct(private readonly FinancingPublicService $public) {}

    // GET /api/public/financing
    public function show(Request $request): JsonResponse
    {
        return $this->noindex(ApiResponse::success($this->public->payload($this->link($request)), 'Pedido de documentos.'));
    }

    // POST /api/public/financing/consent   { privacy: true, id_copy: true, name: "Nome completo" }
    public function consent(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $this->public->consent($link, $request->boolean('privacy'), $request->boolean('id_copy') || $request->boolean('cc_copy'), $request->input('name'));

        return $this->noindex(ApiResponse::success($this->public->payload($link->fresh(['company', 'documents', 'request'])), 'Obrigado. Já pode enviar.'));
    }

    // PUT /api/public/financing/details   { phone, email, housing, housing_other?, housing_monthly_eur? }
    public function details(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $this->public->details($link, $request->only(['phone', 'email', 'housing', 'housing_other', 'housing_monthly_eur']));

        return $this->noindex(ApiResponse::success($this->public->payload($link->fresh(['company', 'documents', 'request'])), 'Dados guardados.'));
    }

    // PUT /api/public/financing/notes   { notes }
    public function notes(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $this->public->notes($link, $request->input('notes'));

        return $this->noindex(ApiResponse::success($this->public->payload($link->fresh(['company', 'documents', 'request'])), 'Observações guardadas.'));
    }

    // POST /api/public/financing/second-holder   { relationship, relationship_other? }
    public function secondHolder(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $this->public->secondHolder($link, $request->input('relationship'), $request->input('relationship_other'));

        return $this->noindex(ApiResponse::success($this->public->payload($link->fresh(['company', 'documents', 'request'])), 'Segundo titular incluído: copie o link e envie-lho.'));
    }

    // POST /api/public/financing/documents   multipart: kind, file
    public function upload(Request $request): JsonResponse
    {
        $link = $this->link($request);
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            FinancingPublicService::fail(422, $file && $file->getError() === UPLOAD_ERR_INI_SIZE ? 'too_large' : 'file_required',
                $file && $file->getError() === UPLOAD_ERR_INI_SIZE ? 'O ficheiro é demasiado grande.' : 'Escolha um ficheiro.', 'file');
        }
        $doc = $this->public->upload($link, (string) $request->input('kind'), $file);

        return $this->noindex(ApiResponse::success(FinancingPublicService::file($doc), 'Ficheiro recebido.', 201));
    }

    /** O código vem do cabeçalho; mal formado, desconhecido, expirado, revogado ou apagado dá o mesmo 404. */
    private function link(Request $request): FinancingLink
    {
        $token = (string) $request->header('X-Financing-Token', '');
        if (! preg_match('/^[A-Za-z0-9]{' . FinancingLink::TOKEN_LENGTH . '}$/', $token)) {
            FinancingPublicService::fail(404, 'not_found', FinancingPublicService::GONE);
        }
        $link = $this->public->resolve($token);
        $this->assertOrigin($request, $link->company);

        return $link;
    }

    /**
     * A partir de um browser (com Origin): a página da XPLENDOR (com o esquema e a porta dela) ou
     * um site da empresa do código, só em https e na porta normal, com ou sem "www": o domínio do
     * endereço do site (companies.website) ou do modelo de endereço das viaturas
     * (companies.vehicle_url_template).
     */
    private function assertOrigin(Request $request, Company $company): void
    {
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '') {
            return;
        }
        $o = self::origin($origin);
        $platform = array_filter([self::origin((string) config('app.frontend_url')), self::origin((string) config('app.url'))]);
        foreach ($platform as $p) {
            if ($o && $o === $p) {
                return;
            }
        }
        $companyHosts = array_filter([self::origin((string) $company->website, true)['host'] ?? null, self::origin((string) $company->vehicle_url_template, true)['host'] ?? null]);
        if ($o && $o['scheme'] === 'https' && $o['port'] === 443 && in_array($o['host'], $companyHosts, true)) {
            return;
        }
        FinancingPublicService::fail(403, 'origin_not_allowed', 'Este site não pode usar este pedido.');
    }

    /** Esquema, domínio (sem "www.") e porta de um endereço; $assumeHttps para endereços sem esquema ("quebom.pt"). @return array{scheme: string, host: string, port: int}|null */
    private static function origin(string $url, bool $assumeHttps = false): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url(str_contains($url, '://') ? $url : ($assumeHttps ? "https://{$url}" : $url));
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }
        $scheme = mb_strtolower($parts['scheme']);

        return ['scheme' => $scheme, 'host' => preg_replace('/^www\./', '', mb_strtolower($parts['host'])),
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    private function noindex(Response $response): JsonResponse
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
