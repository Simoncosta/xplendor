<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ExpenseCharge;
use App\Models\ExpenseChargeOpen;
use App\Services\Billing\ChargeService;
use App\Services\PublicLinks\PublicLinkOpens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Link seguro da cobrança (sem conta e sem o módulo de Finanças): ver os dados, descarregar
 * a fatura, o sinal de abertura e "Já paguei" com comprovativo opcional.
 *
 * O token nunca vai no caminho: o link é /cobranca#<token> (o fragmento não chega ao
 * servidor nem aos registos de acesso) e a página envia-o no cabeçalho X-Charge-Token.
 * Todas as respostas levam X-Robots-Tag: noindex e Referrer-Policy: no-referrer.
 * Limites de pedidos nas rotas. As aberturas usam o serviço partilhado (robôs,
 * verificadores de links dos emails e a equipa não contam).
 */
class ChargePublicController extends Controller
{
    public function __construct(
        private readonly ChargeService $charges,
        private readonly PublicLinkOpens $opens,
    ) {}

    // GET /api/public/charge
    public function show(Request $request): JsonResponse
    {
        return $this->noindex(ApiResponse::success(ChargeService::presentForClient($this->charge($request)), 'Cobrança.'));
    }

    // GET /api/public/charge/pdf
    public function pdf(Request $request): Response
    {
        $charge = $this->charge($request);

        return $this->noindex(response($this->charges->invoice($charge), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="fatura-xplendor-' . $charge->id . '.pdf"',
            'Cache-Control' => 'private, no-store',
        ]));
    }

    // POST /api/public/charge/open   { visitor_id, team_marker? }
    public function open(Request $request): JsonResponse
    {
        $charge = $this->charge($request);
        $open = $this->opens->record($request, 'charge:' . $charge->id, $charge, ExpenseChargeOpen::class,
            ['expense_charge_id' => $charge->id], [], 24);

        return $this->noindex(ApiResponse::success(['counted' => $open['counted'], 'reason' => $open['reason']], 'Registado.'));
    }

    // POST /api/public/charge/paid   multipart { note?, proof? }
    public function paid(Request $request): JsonResponse
    {
        $charge = $this->charge($request);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimetypes:' . implode(',', ChargeService::PROOF_MIMES), 'max:' . ChargeService::PROOF_MAX_KB],
        ], ['proof.mimetypes' => 'O comprovativo tem de ser PDF ou imagem (JPG, PNG ou WEBP).', 'proof.max' => 'O comprovativo não pode ter mais de 10 MB.']);

        $charge = $this->charges->indicatePayment($charge, 'link', null, $data['note'] ?? null, $request->file('proof'));

        return $this->noindex(ApiResponse::success(ChargeService::presentForClient($charge), 'Obrigado. A XPLENDOR vai confirmar o pagamento.'));
    }

    /** O token vem do cabeçalho; mal formado, desconhecido, revogado ou expirado dá 404, sem distinguir. */
    private function charge(Request $request): ExpenseCharge
    {
        $token = (string) $request->header('X-Charge-Token', '');
        $charge = preg_match('/^[A-Za-z0-9]{64}$/', $token) ? ExpenseCharge::findByToken($token) : null;
        abort_unless($charge !== null, 404, 'Cobrança não encontrada.');

        return $charge;
    }

    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
