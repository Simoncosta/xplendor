<?php

declare(strict_types=1);

namespace App\Services\SupportTickets;

use App\Models\SupportTicket;
use App\Models\SupportTicketRemovedFile;
use App\Models\User;
use App\Support\Storage\PrivateFiles;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * As faturas retiradas ou trocadas: deixam logo de ter endereço assinado (o ticket já não as
 * aponta), o ficheiro fica no disco privado e o comando support:purge-removed-files apaga-o ao fim
 * do prazo (config tickets.removed_files_days, 30 por omissão). Até lá conta no espaço da empresa.
 */
class RemovedTicketFiles
{
    public function trashInvoice(SupportTicket $ticket, User $actor): void
    {
        if ($ticket->invoice_path === null) {
            return;
        }
        SupportTicketRemovedFile::create([
            'company_id'         => $ticket->company_id,
            'support_ticket_id'  => $ticket->id,
            'kind'               => SupportTicketRemovedFile::KIND_INVOICE,
            'path'               => $ticket->invoice_path,
            'size_bytes'         => $ticket->invoice_size_bytes,
            'removed_by_user_id' => $actor->id,
            'removed_at'         => now(),
        ]);
    }

    /** Apaga os ficheiros com o prazo cumprido; devolve quantos. */
    public function purgeExpired(): int
    {
        $days = max(1, (int) config('tickets.removed_files_days', 30));
        $count = 0;
        SupportTicketRemovedFile::whereNull('purged_at')->where('removed_at', '<=', now()->subDays($days))
            ->orderBy('id')->chunkById(200, function ($files) use (&$count) {
                foreach ($files as $file) {
                    try {
                        // As faturas antigas guardadas no disco público (/storage/…) saem de lá.
                        PrivateFiles::isLegacyPublic($file->path)
                            ? Storage::disk('public')->delete(substr($file->path, strlen('/storage/')))
                            : Storage::disk(PrivateFiles::disk())->delete($file->path);
                        $file->update(['purged_at' => now()]);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('[Suporte] Fatura retirada não apagada', ['id' => $file->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                    }
                }
            });

        return $count;
    }
}
