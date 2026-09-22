<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\SiteChangeDecisionMail;
use App\Mail\SiteChangeQuotedMail;
use App\Mail\SupportTicketCreatedMail;
use App\Mail\SupportTicketMessageMail;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Repositories\Contracts\SupportTicketRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class SupportTicketService extends BaseService
{
    public function __construct(protected SupportTicketRepositoryInterface $supportTicketRepository)
    {
        parent::__construct($supportTicketRepository);
    }

    private const MAX_EDGE = 1600;

    // Mesmo destino do email de venda (CarSoldNotificationMail) — o dono da
    // plataforma. Hardcoded para seguir o padrão existente.
    private const NOTIFY_RECIPIENT = 'simonfrtd@gmail.com';

    /** Cria o ticket; para bug com print, re-encoda o screenshot (sem original cru). */
    public function createTicket(int $companyId, int $userId, array $data, ?UploadedFile $screenshot): SupportTicket
    {
        $screenshotPath = null;
        if ($data['type'] === 'bug' && $screenshot) {
            $screenshotPath = $this->storeScreenshot($companyId, $screenshot);
        }

        // Tipo pago "site_change" arranca na camada de orçamento (a aguardar
        // orçamento). Os tipos grátis deixam quote_status null.
        $isSiteChange = $data['type'] === 'site_change';

        $ticket = $this->supportTicketRepository->store([
            'company_id'      => $companyId,
            'user_id'         => $userId,
            'type'            => $data['type'],
            'title'           => $data['title'],
            'description'     => $data['description'],
            'status'          => 'open',
            'screenshot_path' => $screenshotPath,
            'quote_status'    => $isSiteChange ? 'awaiting_quote' : null,
        ]);

        $this->notifyCreated($ticket);

        return $ticket;
    }

    /**
     * ADMIN — reclassifica o TIPO de um ticket (o cliente classificou mal).
     *
     * Ligação à camada de orçamento:
     *  · → site_change: ATIVA a camada (quote_status 'awaiting_quote' se ainda
     *    não tiver), passando o ticket a poder ser orçado.
     *  · site_change → outro tipo: SÓ é permitido se NÃO houver dados de
     *    orçamento (option (a) — nunca apagar dados de dinheiro em silêncio).
     *    Se já há valor/fatura/orçamento em curso, BLOQUEIA (422) e pede para
     *    resolver o orçamento primeiro. Sem dados (só 'awaiting_quote'), desativa
     *    a camada limpando quote_status. Nunca deixa dados órfãos.
     */
    public function reclassifyType(SupportTicket $ticket, string $newType): SupportTicket
    {
        if ($ticket->type === $newType) {
            return $ticket->fresh();
        }

        $wasSiteChange  = $ticket->type === 'site_change';
        $willBeSiteChange = $newType === 'site_change';

        if ($wasSiteChange && ! $willBeSiteChange) {
            // Há dados de orçamento? (qualquer valor/fatura, ou fluxo já iniciado)
            $hasQuoteData = $ticket->quoted_amount !== null
                || $ticket->invoice_path !== null
                || in_array($ticket->quote_status, ['quoted', 'approved', 'paid', 'completed', 'rejected'], true);

            if ($hasQuoteData) {
                $this->reject('Este ticket tem um orçamento associado. Rejeita ou conclui o orçamento antes de reclassificar o tipo.');
            }

            // Só 'awaiting_quote' (ou null) — sem dados a perder. Desativa a camada.
            $ticket->update(['type' => $newType, 'quote_status' => null]);

            return $ticket->fresh();
        }

        if (! $wasSiteChange && $willBeSiteChange) {
            // Ativa a camada de orçamento (a aguardar orçamento) se ainda não a tem.
            $ticket->update([
                'type'         => $newType,
                'quote_status' => $ticket->quote_status ?? 'awaiting_quote',
            ]);

            return $ticket->fresh();
        }

        // Entre tipos grátis (ex.: bug ↔ melhoria) — só muda o tipo.
        $ticket->update(['type' => $newType]);

        return $ticket->fresh();
    }

    /**
     * ADMIN — regista o orçamento (horas × taxa). Só a partir de awaiting_quote
     * (ou re-orçar enquanto ainda 'quoted'). Muda quote_status→quoted e avisa
     * o stand que há valor para aprovar. Devolve o ticket fresco.
     */
    public function setQuote(SupportTicket $ticket, float $hours): SupportTicket
    {
        $this->assertSiteChange($ticket);
        if (! in_array($ticket->quote_status, ['awaiting_quote', 'quoted'], true)) {
            $this->reject('Só é possível orçar um pedido que aguarda orçamento.');
        }

        $rate   = (float) config('tickets.site_change_hourly_rate');
        $amount = round($hours * $rate, 2);

        $ticket->update([
            'estimated_hours' => $hours,
            'quoted_amount'   => $amount,
            'quote_status'    => 'quoted',
            'status'          => 'in_review',   // mantém coerência com o painel admin
        ]);

        $this->notifyQuoted($ticket);

        return $ticket->fresh();
    }

    /**
     * PIPELINE de orçamentos-em-tickets (só 'site_change'): por quote_status →
     * {count, amount (Σ quoted_amount), hours (Σ estimated_hours)} + total. Valores
     * SEM IVA (somados tal como inseridos). `companyId` opcional (null = todas, p/ o
     * admin; um id = só desse stand, para o cliente/tenancy). Reaproveita o padrão
     * cards+SUM do AdminQuoteController::summary.
     */
    public function quotePipeline(?int $companyId = null): array
    {
        $base = SupportTicket::where('type', 'site_change');
        if ($companyId !== null) {
            $base->where('company_id', $companyId);
        }

        $rows = (clone $base)
            ->selectRaw('quote_status, COUNT(*) as c, COALESCE(SUM(quoted_amount),0) as amount, COALESCE(SUM(estimated_hours),0) as hours')
            ->whereNotNull('quote_status')
            ->groupBy('quote_status')
            ->get()->keyBy('quote_status');

        $byStatus = [];
        foreach (SupportTicket::QUOTE_STATUSES as $s) {
            $r = $rows[$s] ?? null;
            $byStatus[$s] = [
                'count'  => (int) ($r->c ?? 0),
                'amount' => (float) ($r->amount ?? 0),
                'hours'  => (float) ($r->hours ?? 0),
            ];
        }

        return [
            'by_status' => $byStatus,
            'total'     => [
                'count'  => array_sum(array_column($byStatus, 'count')),
                'amount' => array_sum(array_column($byStatus, 'amount')),
                'hours'  => array_sum(array_column($byStatus, 'hours')),
            ],
        ];
    }

    /**
     * STAND — a Matilde APROVA o orçamento (só a partir de 'quoted').
     * Fica a aguardar pagamento; avisa o Simon para faturar.
     */
    public function approveQuote(SupportTicket $ticket): SupportTicket
    {
        $this->assertSiteChange($ticket);
        if ($ticket->quote_status !== 'quoted') {
            $this->reject('Este orçamento não está pendente de aprovação.');
        }

        $ticket->update(['quote_status' => 'approved']);
        $this->notifyDecision($ticket, approved: true);

        return $ticket->fresh();
    }

    /**
     * STAND — a Matilde REJEITA o orçamento (só a partir de 'quoted'). Decisão
     * do Simon: sem renegociação — o ticket FECHA. Avisa o Simon.
     */
    public function rejectQuote(SupportTicket $ticket): SupportTicket
    {
        $this->assertSiteChange($ticket);
        if ($ticket->quote_status !== 'quoted') {
            $this->reject('Este orçamento não está pendente de aprovação.');
        }

        $ticket->update([
            'quote_status' => 'rejected',
            'status'       => 'closed',   // fecha, sem renegociar para baixo
        ]);
        $this->notifyDecision($ticket, approved: false);

        return $ticket->fresh();
    }

    /**
     * ADMIN — marca PAGO (pagamento acontece fora do software) e anexa o PDF da
     * fatura. Só a partir de 'approved'. Passa a "em execução".
     */
    public function markPaid(SupportTicket $ticket, ?UploadedFile $invoice): SupportTicket
    {
        $this->assertSiteChange($ticket);
        if ($ticket->quote_status !== 'approved') {
            $this->reject('Só se marca pago um orçamento aprovado.');
        }

        $invoicePath = $invoice ? $this->storeInvoice($ticket->company_id, $invoice) : $ticket->invoice_path;

        $ticket->update([
            'quote_status' => 'paid',
            'invoice_path' => $invoicePath,
        ]);

        return $ticket->fresh();
    }

    /**
     * ADMIN — marca CONCLUÍDO quando o trabalho termina. Só a partir de 'paid'.
     * Espelha no status genérico como resolved (grava resolved_at).
     */
    public function markCompleted(SupportTicket $ticket): SupportTicket
    {
        $this->assertSiteChange($ticket);
        if ($ticket->quote_status !== 'paid') {
            $this->reject('Só se conclui um pedido pago/em execução.');
        }

        $ticket->update([
            'quote_status' => 'completed',
            'status'       => 'resolved',
            'resolved_at'  => now(),
        ]);

        return $ticket->fresh();
    }

    private function assertSiteChange(SupportTicket $ticket): void
    {
        if (! $ticket->isSiteChange()) {
            $this->reject('Esta ação só se aplica a pedidos de "Alteração ao site".');
        }
    }

    /** Erro de transição inválida → 422 (mensagem em pt-PT). */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['quote_status' => [$message]]);
    }

    /** Acrescenta uma mensagem à thread. is_staff deriva do role (root = staff). */
    public function addMessage(SupportTicket $ticket, int $userId, string $body, bool $isStaff): SupportTicketMessage
    {
        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id'           => $userId,
            'body'              => $body,
            'is_staff'          => $isStaff,
        ]);

        // Só as interações DOS STANDS notificam o super-admin (nunca as do staff).
        if (! $isStaff) {
            $this->notifyMessage($ticket, $userId, $body);
        }

        return $message;
    }

    /**
     * Muda o estado do ticket (só o super-admin, lado /admin). Grava resolved_at
     * quando passa a resolved; limpa-o nos outros estados.
     */
    public function updateStatus(SupportTicket $ticket, string $status): SupportTicket
    {
        $ticket->update([
            'status'      => $status,
            'resolved_at' => $status === 'resolved' ? now() : null,
        ]);

        return $ticket->fresh();
    }

    /** Email de "ticket novo" (via queue, fail-safe). */
    private function notifyCreated(SupportTicket $ticket): void
    {
        try {
            $ticket->loadMissing(['company', 'user']);
            Mail::to(self::NOTIFY_RECIPIENT)->queue(new SupportTicketCreatedMail(
                ticketId: (int) $ticket->id,
                companyName: $ticket->company?->fiscal_name ?? '—',
                authorName: $ticket->user?->name ?? '—',
                type: $ticket->type,
                title: $ticket->title,
                description: $ticket->description,
                hasScreenshot: ! empty($ticket->screenshot_path),
            ));
        } catch (\Throwable $e) {
            // Email é secundário — nunca impede a criação do ticket.
            Log::error('[Support] Falha ao enfileirar email de ticket novo', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }

    /** Email de "nova mensagem do stand" (via queue, fail-safe). */
    private function notifyMessage(SupportTicket $ticket, int $userId, string $body): void
    {
        try {
            $ticket->loadMissing('company');
            $authorName = User::find($userId)?->name ?? '—';
            Mail::to(self::NOTIFY_RECIPIENT)->queue(new SupportTicketMessageMail(
                ticketId: (int) $ticket->id,
                ticketTitle: $ticket->title,
                companyName: $ticket->company?->fiscal_name ?? '—',
                authorName: $authorName,
                body: $body,
            ));
        } catch (\Throwable $e) {
            Log::error('[Support] Falha ao enfileirar email de mensagem', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }

    /** Email ao STAND: há orçamento novo para aprovar (via queue, fail-safe). */
    private function notifyQuoted(SupportTicket $ticket): void
    {
        try {
            $ticket->loadMissing(['company', 'user']);
            $to = $ticket->user?->email;
            if (! $to) {
                return; // sem email do autor não há a quem notificar
            }
            Mail::to($to)->queue(new SiteChangeQuotedMail(
                ticketId: (int) $ticket->id,
                ticketTitle: $ticket->title,
                estimatedHours: (float) $ticket->estimated_hours,
                quotedAmount: (float) $ticket->quoted_amount,
                hourlyRate: (float) config('tickets.site_change_hourly_rate'),
            ));
        } catch (\Throwable $e) {
            Log::error('[Support] Falha ao enfileirar email de orçamento', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }

    /** Email ao SIMON: o stand aprovou (para faturar) ou rejeitou (fechou). */
    private function notifyDecision(SupportTicket $ticket, bool $approved): void
    {
        try {
            $ticket->loadMissing(['company', 'user']);
            Mail::to(self::NOTIFY_RECIPIENT)->queue(new SiteChangeDecisionMail(
                ticketId: (int) $ticket->id,
                ticketTitle: $ticket->title,
                companyName: $ticket->company?->fiscal_name ?? '—',
                authorName: $ticket->user?->name ?? '—',
                approved: $approved,
                quotedAmount: (float) $ticket->quoted_amount,
            ));
        } catch (\Throwable $e) {
            Log::error('[Support] Falha ao enfileirar email de decisão de orçamento', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }

    /** Fatura em PDF (anexada pelo admin). Validada como pdf no controller. */
    private function storeInvoice(int $companyId, UploadedFile $file): string
    {
        $folder = "company_{$companyId}/ticket-invoices";
        Storage::disk('public')->makeDirectory($folder);

        $diskPath = "{$folder}/" . now()->format('YmdHisv') . Str::lower(Str::random(6)) . '.pdf';
        Storage::disk('public')->put($diskPath, file_get_contents($file->getRealPath()));

        return Storage::url($diskPath);
    }

    /** Upload não-confiável: re-encode via Intervention (descarta EXIF/payloads). */
    private function storeScreenshot(int $companyId, UploadedFile $file): string
    {
        $folder = "company_{$companyId}/tickets";
        Storage::disk('public')->makeDirectory($folder);

        $diskPath = "{$folder}/" . now()->format('YmdHisv') . Str::lower(Str::random(6)) . '.webp';

        $binary = (new ImageManager(new Driver()))
            ->read($file->getRealPath())
            ->scaleDown(self::MAX_EDGE, self::MAX_EDGE)
            ->toWebp(82)
            ->toString();

        Storage::disk('public')->put($diskPath, $binary);

        return Storage::url($diskPath);
    }
}
