<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Mail\SatisfactionReportMail;
use App\Models\Car;
use App\Models\CarSale;
use App\Models\ImpersonationSession;
use App\Models\Mailbox;
use App\Models\SatisfactionReport;
use App\Services\Mailbox\MailboxSender;
use App\Support\SatisfactionMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * DMS — Relatório de Satisfação: criação INTERNA (autenticada).
 *
 * Nesta fase (Incremento 1) só é preciso conseguir CRIAR um relatório para uma
 * venda, para gerar o link público de teste. O botão de "enviar" (email/WhatsApp)
 * é o Incremento 5. Idempotente por venda: chamar duas vezes devolve o mesmo
 * relatório/token (não multiplica links).
 */
class SatisfactionReportController extends Controller
{
    private function authorizeCompanyAccess(int $companyId): bool
    {
        return $this->authorizeCompany($companyId);
    }

    /**
     * Contacto do cliente da venda. Preferência ao Customer ligado, com fallback
     * aos buyer_* da venda. phone/email só existem se houve consentimento (o gate
     * RGPD anula-os na gravação) — logo a sua presença já reflecte o consentimento.
     *
     * @return array{name: ?string, phone: ?string, email: ?string, consent: bool}
     */
    private function resolveContact(CarSale $sale): array
    {
        return [
            'name'    => $sale->customer?->name ?? $sale->buyer_name,
            'phone'   => $sale->customer?->phone ?? $sale->buyer_phone,
            'email'   => $sale->customer?->email ?? $sale->buyer_email,
            'consent' => (bool) $sale->contact_consent,
        ];
    }

    public function store(int $companyId, int $carId): JsonResponse
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $car = Car::with('sale.customer')
            ->where('company_id', $companyId)
            ->find($carId);

        if (! $car) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        if (! $car->sale) {
            return ApiResponse::error('A viatura ainda não tem uma venda registada.', 422);
        }

        // Idempotente por venda — cria na 1.ª vez (vendas antigas), devolve o
        // existente nas seguintes. Token estável, nunca regenerado.
        $report = SatisfactionReport::ensureForSale($car->sale);

        $contact = $this->resolveContact($car->sale);
        $link    = SatisfactionMessage::linkFor($report->public_token);

        return ApiResponse::success([
            'id'               => $report->id,
            'public_token'     => $report->public_token,
            'path'             => "/r/{$report->public_token}",
            'status'           => $report->status,
            'expires_at'       => optional($report->expires_at)->toIso8601String(),
            'customer'         => [
                'name'  => $contact['name'],
                'phone' => $contact['phone'],
                'email' => $contact['email'],
            ],
            // Texto pronto para o WhatsApp (fonte única — SatisfactionMessage).
            'whatsapp_message' => SatisfactionMessage::build($contact['name'], $link),
            'sent_at'          => optional($report->sent_at)->toIso8601String(),
            'sent_channel'     => $report->sent_channel,
        ], 'Satisfaction report ready.');
    }

    /**
     * Envia o link do relatório ao CLIENTE por email (via queue). Feedback
     * síncrono cobre validação/consentimento/enfileiramento; o envio SMTP em si
     * corre no worker (retries da fila) — o endpoint confirma que foi posto a
     * caminho, não bloqueia a interface.
     */
    /**
     * Envia o link ao cliente por email. Com via=mailbox (E-mail 2), sai pela caixa de correio de quem
     * envia (a sua, nesta empresa, já testada), com o Message-ID gravado em mail_messages; sem isso,
     * pelo servidor da plataforma, como até aqui. O consentimento de contacto conta nos dois.
     */
    public function sendEmail(Request $request, int $companyId, int $carId, MailboxSender $sender): JsonResponse
    {
        $viaMailbox = $request->input('via') === 'mailbox';
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $car = Car::with(['sale.customer', 'company'])
            ->where('company_id', $companyId)
            ->find($carId);

        if (! $car || ! $car->sale) {
            return ApiResponse::error('Venda não encontrada.', 404);
        }

        $contact = $this->resolveContact($car->sale);

        if (empty($contact['email'])) {
            return ApiResponse::error('Esta venda não tem email do cliente.', 422);
        }
        if (! $contact['consent']) {
            return ApiResponse::error('O cliente não deu consentimento para contacto (RGPD).', 422);
        }

        $report = SatisfactionReport::ensureForSale($car->sale);
        $link   = SatisfactionMessage::linkFor($report->public_token);

        $companyName = $car->company?->fiscal_name ?? 'O seu stand';
        $logoUrl = $car->company?->logo_path
            ? rtrim((string) config('app.url'), '/') . $car->company->logo_path
            : null;

        if ($viaMailbox) {
            $user = $request->user();
            if (ImpersonationSession::activeFor($user)) {
                return ApiResponse::error('Durante a impersonation não se envia pela caixa de correio da pessoa. Envie pela XPLENDOR.', 422);
            }
            $mailbox = Mailbox::personal($companyId, (int) $user->id)->first();
            if (! $mailbox) {
                return ApiResponse::error('Ligue primeiro a sua caixa de correio no Perfil.', 422);
            }
            if ($reason = $sender->blockedReason($mailbox)) {
                return ApiResponse::error($reason, 422);
            }
            $message = SatisfactionMessage::build($contact['name'], $link);
            $sender->queue($mailbox, $user, $contact['email'], 'A sua nova viatura — ' . $companyName,
                $message . "\n\n" . $link,
                view('emails.satisfaction.report', ['customerName' => $contact['name'], 'companyName' => $companyName, 'message' => $message,
                    'link' => $link, 'logoUrl' => $logoUrl])->render(),
                ['customer_id' => $car->sale->customer_id, 'car_sale_id' => $car->sale->id, 'car_lead_id' => $car->sale->lead_id, 'satisfaction_report_id' => $report->id]);
            $report->forceFill(['sent_at' => now(), 'sent_channel' => 'email'])->save();

            return ApiResponse::success([
                'sent_at' => optional($report->sent_at)->toIso8601String(),
                'sent_channel' => 'email',
                'via' => 'mailbox',
            ], \App\Services\Mailbox\DevRecipientGuard::active()
                ? "Em dev, a mensagem foi só para a sua própria caixa ({$mailbox->email}), com o cliente no assunto."
                : "Email enviado pela sua caixa ({$mailbox->email}).");
        }

        try {
            Mail::to($contact['email'])->queue(new SatisfactionReportMail(
                customerName: $contact['name'],
                companyName: $companyName,
                message: SatisfactionMessage::build($contact['name'], $link),
                link: $link,
                logoUrl: $logoUrl,
            ));
        } catch (\Throwable $e) {
            Log::error('[Satisfaction] Falha ao enfileirar email', ['car_id' => $carId, 'error' => $e->getMessage()]);
            return ApiResponse::error('Não foi possível enviar o email. Tente novamente.', 500);
        }

        $report->forceFill(['sent_at' => now(), 'sent_channel' => 'email'])->save();

        return ApiResponse::success([
            'sent_at'      => optional($report->sent_at)->toIso8601String(),
            'sent_channel' => 'email',
        ], 'Email enviado ao cliente.');
    }

    /**
     * Regista que o link foi enviado (usado pelo botão WhatsApp, que abre o
     * wa.me no cliente do stand — o envio é manual, aqui só marcamos o registo).
     */
    public function markSent(Request $request, int $companyId, int $carId): JsonResponse
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'channel' => ['required', 'in:whatsapp,email'],
        ]);

        $car = Car::with('sale')
            ->where('company_id', $companyId)
            ->find($carId);

        if (! $car || ! $car->sale) {
            return ApiResponse::error('Venda não encontrada.', 404);
        }

        $report = SatisfactionReport::ensureForSale($car->sale);
        $report->forceFill(['sent_at' => now(), 'sent_channel' => $data['channel']])->save();

        return ApiResponse::success([
            'sent_at'      => optional($report->sent_at)->toIso8601String(),
            'sent_channel' => $data['channel'],
        ], 'Envio registado.');
    }

    /**
     * Fotos que o cliente carregou — vistas pelo stand na Ficha. Tenant-scoped;
     * só fotos existentes (as apagadas foram removidas de verdade). Sem relatório
     * ainda → lista vazia.
     */
    public function photos(int $companyId, int $carId): JsonResponse
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $car = Car::with('sale')
            ->where('company_id', $companyId)
            ->find($carId);

        if (! $car) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        $report = $car->sale
            ? SatisfactionReport::with('photos')->where('car_sale_id', $car->sale->id)->first()
            : null;

        $photos = $report
            ? $report->photos->map(fn ($p) => [
                'id'         => $p->id,
                'url'        => \App\Support\Storage\PrivateFiles::reportPhotoUrl($p),
                'created_at' => optional($p->created_at)->toIso8601String(),
            ])->values()
            : [];

        return ApiResponse::success(['photos' => $photos], 'Client photos fetched.');
    }

    /**
     * Avaliação recebida (estrelas + comentário) — vista pelo stand na Ficha.
     * Tenant-scoped. O comentário serve os dois ramos (public_message no ≥4,
     * internal_feedback no <4). Sem avaliação ainda → null.
     */
    public function review(int $companyId, int $carId): JsonResponse
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $car = Car::with('sale')
            ->where('company_id', $companyId)
            ->find($carId);

        if (! $car) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        $report = $car->sale
            ? SatisfactionReport::where('car_sale_id', $car->sale->id)->first()
            : null;

        $review = ($report && $report->rating)
            ? [
                'rating'       => $report->rating,
                'comment'      => $report->public_message ?? $report->internal_feedback,
                'submitted_at' => optional($report->submitted_at)->toIso8601String(),
            ]
            : null;

        return ApiResponse::success(['review' => $review], 'Client review fetched.');
    }
}
