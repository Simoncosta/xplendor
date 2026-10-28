<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TicketTypeChangeNeedsConfirmation;
use App\Mail\SiteChangeDecisionMail;
use App\Mail\SiteChangeQuotedMail;
use App\Mail\SupportTicketCreatedMail;
use App\Mail\SupportTicketMessageMail;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketTypeChange;
use App\Models\User;
use App\Repositories\Contracts\SupportTicketRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    /**
     * Cria o ticket; para bug com print, re-encoda o screenshot (sem original cru). Criado pelo root
     * na sessão como cliente ($impersonatorId): continua a ser da empresa (um pedido em nome dela),
     * grava quem o escreveu e não envia ao Simon o e-mail de "novo pedido".
     */
    public function createTicket(int $companyId, int $userId, array $data, ?UploadedFile $screenshot, ?int $impersonatorId = null): SupportTicket
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
            'impersonator_user_id' => $impersonatorId,
        ]);

        if ($impersonatorId === null) {
            $this->notifyCreated($ticket);
        }

        return $ticket;
    }

    /**
     * EQUIPA XPLENDOR (root, fora de impersonation; validado no controller) muda o
     * TIPO de um ticket. Regras do orçamento (só site_change o tem):
     *  · sem orçamento ou a aguardar orçamento: mudança livre;
     *  · orçado ou rejeitado: só com $confirmReset (senão TicketTypeChangeNeedsConfirmation);
     *    estado, valor e horas do orçamento passam a null;
     *  · aprovado, pago ou concluído: BLOQUEADO (422), primeiro anula-se o orçamento;
     *  · para site_change: entra em "a aguardar orçamento".
     * Cada mudança fica em support_ticket_type_changes (com o orçamento anterior) e o
     * cliente recebe uma mensagem da equipa quando um orçamento é anulado ou quando o
     * pedido passa a ser pago. Corre com a linha bloqueada (concorrência com a
     * aprovação pelo cliente).
     */
    public function reclassifyType(SupportTicket $ticket, string $newType, User $actor, bool $confirmReset = false): SupportTicket
    {
        DB::transaction(function () use ($ticket, $newType, $actor, $confirmReset): void {
            $locked = $this->lockFresh($ticket);
            $fromType = $locked->type;
            if ($fromType === $newType) {
                return;
            }

            $previous = [
                'quote_status'    => $locked->quote_status,
                'quoted_amount'   => $locked->quoted_amount !== null ? (float) $locked->quoted_amount : null,
                'estimated_hours' => $locked->estimated_hours !== null ? (float) $locked->estimated_hours : null,
            ];
            $wasSiteChange = $fromType === 'site_change';
            $willBeSiteChange = $newType === 'site_change';
            $resetQuote = false;

            if ($wasSiteChange && ! $willBeSiteChange) {
                if (in_array($locked->quote_status, SupportTicket::QUOTE_LOCKED_STATUSES, true) || $locked->invoice_path !== null || $locked->paid_at !== null) {
                    $this->reject('Este pedido tem um orçamento aprovado, pago ou concluído, por isso o tipo não pode ser alterado. Anule primeiro o orçamento.');
                }
                if (in_array($locked->quote_status, SupportTicket::QUOTE_RESETTABLE_STATUSES, true) || $locked->quoted_amount !== null) {
                    if (! $confirmReset) {
                        throw new TicketTypeChangeNeedsConfirmation(
                            (string) $locked->quote_status, $previous['quoted_amount'], $previous['estimated_hours']
                        );
                    }
                    $resetQuote = true;
                }
                $locked->update(['type' => $newType, 'quote_status' => null, 'quoted_amount' => null, 'estimated_hours' => null]);
            } elseif ($willBeSiteChange) {
                $locked->update(['type' => $newType, 'quote_status' => 'awaiting_quote', 'quoted_amount' => null, 'estimated_hours' => null]);
            } else {
                $locked->update(['type' => $newType]);
            }

            SupportTicketTypeChange::create([
                'support_ticket_id'        => $locked->id,
                'company_id'               => $locked->company_id,
                'from_type'                => $fromType,
                'to_type'                  => $newType,
                'previous_quote_status'    => $previous['quote_status'],
                'previous_quoted_amount'   => $previous['quoted_amount'],
                'previous_estimated_hours' => $previous['estimated_hours'],
                'changed_by_user_id'       => $actor->id,
            ]);

            $message = match (true) {
                $resetQuote => $this->quoteResetMessage($fromType, $newType, $previous),
                ! $wasSiteChange && $willBeSiteChange => sprintf(
                    'A equipa XPLENDOR alterou o tipo deste pedido de «%s» para «%s». Este tipo de pedido é pago: vai receber um orçamento antes de qualquer custo e o trabalho só avança depois da sua aprovação.',
                    SupportTicket::typeLabel($fromType), SupportTicket::typeLabel($newType)
                ),
                default => null,
            };
            if ($message !== null) {
                $this->addMessage($locked, $actor->id, $message, true);
            }
        });

        return $ticket->fresh();
    }

    /** Mensagem ao cliente quando a mudança de tipo anula um orçamento orçado ou rejeitado. */
    private function quoteResetMessage(string $fromType, string $toType, array $previous): string
    {
        $value = $previous['quoted_amount'] !== null
            ? number_format($previous['quoted_amount'], 2, ',', '.') . ' €'
            : 'sem valor';
        $hours = $previous['estimated_hours'] !== null
            ? ', ' . rtrim(rtrim(number_format($previous['estimated_hours'], 2, ',', ''), '0'), ',') . ' h'
            : '';
        $tail = $previous['quote_status'] === 'quoted'
            ? 'foi anulado e deixou de estar pendente de aprovação.'
            : 'foi anulado.';

        return sprintf(
            'A equipa XPLENDOR alterou o tipo deste pedido de «%s» para «%s». O orçamento anterior (%s%s) %s',
            SupportTicket::typeLabel($fromType), SupportTicket::typeLabel($toType), $value, $hours, $tail
        );
    }

    /** Relê o ticket com a linha bloqueada até ao fim da transação (estado sempre atual). */
    private function lockFresh(SupportTicket $ticket): SupportTicket
    {
        return SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * ADMIN — regista o orçamento (horas × taxa). Só a partir de awaiting_quote
     * (ou re-orçar enquanto ainda 'quoted'). Muda quote_status→quoted e avisa
     * o stand que há valor para aprovar. Devolve o ticket fresco.
     */
    public function setQuote(SupportTicket $ticket, float $hours): SupportTicket
    {
        $rate   = (float) config('tickets.site_change_hourly_rate');
        $amount = round($hours * $rate, 2);

        $ticket = DB::transaction(function () use ($ticket, $hours, $amount) {
            $locked = $this->lockFresh($ticket);
            $this->assertSiteChange($locked);
            if (! in_array($locked->quote_status, ['awaiting_quote', 'quoted'], true)) {
                $this->reject('Só é possível orçar um pedido que aguarda orçamento.');
            }
            $locked->update([
                'estimated_hours' => $hours,
                'quoted_amount'   => $amount,
                'quote_status'    => 'quoted',
                'status'          => 'in_review',   // mantém coerência com o painel admin
            ]);

            return $locked;
        });

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

        // A fatura e o pagamento, à parte do trabalho (só os pedidos com o orçamento aprovado).
        $work = (clone $base)->whereIn('quote_status', SupportTicket::QUOTE_WORK_STATUSES);
        $payment = [
            'to_invoice'      => (clone $work)->whereNull('invoice_path')->whereNull('paid_at'),
            'invoiced_unpaid' => (clone $work)->whereNotNull('invoice_path')->whereNull('paid_at'),
            'paid'            => (clone $work)->whereNotNull('paid_at'),
        ];
        $payment = array_map(fn ($q) => [
            'count'  => (int) (clone $q)->count(),
            'amount' => (float) (clone $q)->sum('quoted_amount'),
        ], $payment);

        return [
            'by_status' => $byStatus,
            'payment'   => $payment,
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
        // Linha bloqueada e relida: se a equipa mudou o tipo entretanto, o estado já não é 'quoted'.
        $ticket = DB::transaction(function () use ($ticket) {
            $locked = $this->lockFresh($ticket);
            $this->assertSiteChange($locked);
            if ($locked->quote_status !== 'quoted') {
                $this->reject('Este orçamento não está pendente de aprovação.');
            }
            $locked->update(['quote_status' => 'approved']);

            return $locked;
        });
        $this->notifyDecision($ticket, approved: true);

        return $ticket->fresh();
    }

    /**
     * STAND — a Matilde REJEITA o orçamento (só a partir de 'quoted'). Decisão
     * do Simon: sem renegociação — o ticket FECHA. Avisa o Simon.
     */
    public function rejectQuote(SupportTicket $ticket): SupportTicket
    {
        $ticket = DB::transaction(function () use ($ticket) {
            $locked = $this->lockFresh($ticket);
            $this->assertSiteChange($locked);
            if ($locked->quote_status !== 'quoted') {
                $this->reject('Este orçamento não está pendente de aprovação.');
            }
            $locked->update([
                'quote_status' => 'rejected',
                'status'       => 'closed',   // fecha, sem renegociar para baixo
            ]);

            return $locked;
        });
        $this->notifyDecision($ticket, approved: false);

        return $ticket->fresh();
    }

    /**
     * ADMIN — anexa (ou troca) a fatura em PDF. A partir de "aprovado", não mexe no pagamento: o
     * Simon faz o trabalho, emite a fatura e só depois recebe. Trocar só a fatura que é dele; a
     * antiga deixa de ter endereço e o ficheiro apaga-se ao fim do prazo. Avisa por e-mail quem vê
     * a faturação da XPLENDOR na empresa (sem o PDF em anexo).
     */
    public function attachInvoice(SupportTicket $ticket, UploadedFile $invoice, User $actor): SupportTicket
    {
        $authorship = app(\App\Services\SupportTickets\TicketAuthorship::class);
        $authorship->assertTeam($actor);
        $this->assertSiteChange($ticket);
        $this->assertWorkApproved($ticket, 'Só se anexa a fatura de um orçamento aprovado.');
        abort_if($ticket->invoice_path !== null && ! $authorship->ownsInvoice($actor, $ticket), 403, 'Só pode trocar a fatura que anexou.');

        $this->storeInvoiceOn($ticket, $invoice, $actor);
        $this->notifyInvoice($ticket->fresh());

        return $ticket->fresh();
    }

    /** ADMIN — retira a fatura (só a que é dele) e avisa na thread. Não mexe no pagamento. */
    public function removeInvoice(SupportTicket $ticket, User $actor): SupportTicket
    {
        $authorship = app(\App\Services\SupportTickets\TicketAuthorship::class);
        $authorship->assertTeam($actor);
        if ($ticket->invoice_path === null) {
            return $ticket;
        }
        abort_unless($authorship->ownsInvoice($actor, $ticket), 403, 'Só pode retirar a fatura que anexou.');

        DB::transaction(function () use ($ticket, $actor) {
            app(\App\Services\SupportTickets\RemovedTicketFiles::class)->trashInvoice($ticket, $actor);
            $ticket->update(['invoice_path' => null, 'invoiced_at' => null, 'invoice_by_user_id' => null]);
            $ticket->forceFill(['invoice_size_bytes' => null])->saveQuietly();
        });
        $this->addMessage($ticket, $actor->id, 'A equipa XPLENDOR retirou a fatura anexada a este pedido.', true);

        return $ticket->fresh();
    }

    /**
     * ADMIN — marca PAGO (o pagamento acontece fora do software). A partir de "aprovado" ou
     * "concluído", com a fatura opcional (se vier, anexa-a como no attachInvoice).
     */
    public function markPaid(SupportTicket $ticket, ?UploadedFile $invoice, User $actor): SupportTicket
    {
        app(\App\Services\SupportTickets\TicketAuthorship::class)->assertTeam($actor);
        $this->assertSiteChange($ticket);
        $this->assertWorkApproved($ticket, 'Só se marca pago um orçamento aprovado.');
        if ($ticket->paid_at !== null) {
            $this->reject('Este pedido já está marcado como pago.');
        }
        if ($invoice) {
            $this->attachInvoice($ticket, $invoice, $actor);
        }
        $ticket->refresh()->update(['paid_at' => now(), 'paid_by_user_id' => $actor->id]);

        return $ticket->fresh();
    }

    /** ADMIN — anula o pagamento marcado por engano (fica na auditoria do ticket). */
    public function unmarkPaid(SupportTicket $ticket, User $actor): SupportTicket
    {
        app(\App\Services\SupportTickets\TicketAuthorship::class)->assertTeam($actor);
        $this->assertSiteChange($ticket);
        if ($ticket->paid_at === null) {
            $this->reject('Este pedido não está marcado como pago.');
        }
        $ticket->update(['paid_at' => null, 'paid_by_user_id' => null]);

        return $ticket->fresh();
    }

    /**
     * ADMIN — marca CONCLUÍDO quando o trabalho termina, antes ou depois do pagamento. Só a partir
     * de "aprovado". Espelha no status genérico como resolved (grava resolved_at).
     */
    public function markCompleted(SupportTicket $ticket): SupportTicket
    {
        $this->assertSiteChange($ticket);
        if (! in_array($ticket->quote_status, ['approved', SupportTicket::LEGACY_PAID], true)) {
            $this->reject('Só se conclui um pedido com o orçamento aprovado.');
        }

        $ticket->update([
            'quote_status' => 'completed',
            'status'       => 'resolved',
            'resolved_at'  => now(),
        ]);

        return $ticket->fresh();
    }

    private function assertWorkApproved(SupportTicket $ticket, string $message): void
    {
        if (! in_array($ticket->quote_status, SupportTicket::QUOTE_WORK_STATUSES, true)) {
            $this->reject($message);
        }
    }

    /** Grava a fatura nova no ticket; a anterior (se houver) vai para a reciclagem. */
    private function storeInvoiceOn(SupportTicket $ticket, UploadedFile $invoice, User $actor): void
    {
        $path = $this->storeInvoice($ticket->company_id, $invoice);
        DB::transaction(function () use ($ticket, $invoice, $actor, $path) {
            app(\App\Services\SupportTickets\RemovedTicketFiles::class)->trashInvoice($ticket, $actor);
            $ticket->update(['invoice_path' => $path, 'invoiced_at' => now(), 'invoice_by_user_id' => $actor->id]);
            $ticket->forceFill(['invoice_size_bytes' => (int) $invoice->getSize()])->saveQuietly(); // espaço por empresa
        });
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

    /**
     * Acrescenta uma mensagem à thread. is_staff deriva do role (root = staff). Escrita pelo root
     * na sessão como cliente ($impersonatorId): conta como da equipa e não envia ao Simon o e-mail
     * de "nova mensagem do stand".
     */
    public function addMessage(SupportTicket $ticket, int $userId, string $body, bool $isStaff, ?int $impersonatorId = null): SupportTicketMessage
    {
        $isStaff = $isStaff || $impersonatorId !== null;
        $message = SupportTicketMessage::create([
            'support_ticket_id'    => $ticket->id,
            'user_id'              => $userId,
            'body'                 => $body,
            'is_staff'             => $isStaff,
            'impersonator_user_id' => $impersonatorId,
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
            // O orçamento é da faturação da XPLENDOR: vai para quem a vê na empresa
            // (faturacao_xplendor.ver), como o e-mail da fatura.
            $to = $this->billingRecipients((int) $ticket->company_id);
            if ($to === []) {
                return; // sem administrador com email não há a quem notificar
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

    /** E-mail a quem vê a faturação da XPLENDOR: há fatura nova no pedido (via queue, fail-safe). */
    private function notifyInvoice(SupportTicket $ticket): void
    {
        try {
            $to = $this->billingRecipients((int) $ticket->company_id);
            if ($to === []) {
                return;
            }
            Mail::to($to)->queue(new \App\Mail\SupportInvoiceAvailableMail(
                ticketId: (int) $ticket->id,
                ticketTitle: $ticket->title,
                ticketUrl: rtrim((string) config('app.frontend_url'), '/') . "/support/{$ticket->id}",
            ));
        } catch (\Throwable $e) {
            Log::error('[Support] Falha ao enfileirar email de fatura', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }

    /** Os endereços de quem vê a faturação da XPLENDOR na empresa (ativos, com e-mail). */
    private function billingRecipients(int $companyId): array
    {
        $access = app(\App\Access\Access::class);

        return User::where('company_id', $companyId)->whereNull('deactivated_at')->whereNotNull('email')->get()
            ->filter(fn (User $u) => $access->can($u, $companyId, 'faturacao_xplendor.ver')->allowed)
            ->pluck('email')->unique()->values()->all();
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

    /**
     * Fatura em PDF (anexada pelo admin). Validada como pdf no controller. Fica no disco
     * PRIVADO e sai por URL assinado (PrivateFiles), nunca por /storage.
     */
    private function storeInvoice(int $companyId, UploadedFile $file): string
    {
        $diskPath = "support-invoices/company_{$companyId}/" . now()->format('YmdHisv') . Str::lower(Str::random(6)) . '.pdf';
        Storage::disk(\App\Support\Storage\PrivateFiles::disk())->put($diskPath, file_get_contents($file->getRealPath()));

        return $diskPath;
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

        return \App\Support\Storage\PublicUrl::path($diskPath);
    }
}
