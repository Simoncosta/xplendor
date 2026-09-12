<?php

declare(strict_types=1);

namespace App\Services;

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

        $ticket = $this->supportTicketRepository->store([
            'company_id'      => $companyId,
            'user_id'         => $userId,
            'type'            => $data['type'],
            'title'           => $data['title'],
            'description'     => $data['description'],
            'status'          => 'open',
            'screenshot_path' => $screenshotPath,
        ]);

        $this->notifyCreated($ticket);

        return $ticket;
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
