<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\SatisfactionReportPhoto;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ficheiros que deixaram de ser públicos (pré-deploy, ponto 5): as faturas dos pedidos de
 * suporte e as fotografias dos relatórios de satisfação. Ficam no disco privado
 * (config storage_targets.private_disk: o local ou o R2) e saem por endereços assinados de
 * curta duração, gerados pelo backend só nas respostas que já verificaram a empresa e o ACL
 * (ou, nas páginas públicas do relatório, o token do cliente).
 *
 * Na base de dados guarda-se o caminho no disco privado. Um valor antigo "/storage/..."
 * ainda é do disco público (até correr php artisan files:make-private).
 */
final class PrivateFiles
{
    public const MINUTES = 30;

    public static function disk(): string
    {
        return (string) config('storage_targets.private_disk', 'local');
    }

    public static function isLegacyPublic(?string $path): bool
    {
        return $path !== null && str_starts_with($path, '/storage/');
    }

    public static function ticketInvoiceUrl(SupportTicket $ticket): ?string
    {
        if (! $ticket->invoice_path) {
            return null;
        }
        if (self::isLegacyPublic($ticket->invoice_path)) {
            return $ticket->invoice_path;
        }

        return URL::temporarySignedRoute('files.ticket-invoice', now()->addMinutes(self::MINUTES), ['ticket' => $ticket->id], absolute: false);
    }

    public static function reportPhotoUrl(SatisfactionReportPhoto $photo): ?string
    {
        if (! $photo->path) {
            return null;
        }
        if (self::isLegacyPublic($photo->path)) {
            return $photo->path;
        }

        return URL::temporarySignedRoute('files.report-photo', now()->addMinutes(self::MINUTES), ['photo' => $photo->id], absolute: false);
    }

    /** Serve um ficheiro do disco privado (no R2, por um endereço assinado do próprio R2). */
    public static function serve(string $path, string $mime, ?string $downloadName = null): Response
    {
        $disk = self::disk();
        abort_unless(Storage::disk($disk)->exists($path), 404);
        if (SignedFileUrl::supports($disk)) {
            return redirect()->away(SignedFileUrl::for($disk, $path, 300, $downloadName), 302, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        return response(Storage::disk($disk)->get($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline' . ($downloadName ? '; filename="' . addslashes($downloadName) . '"' : ''),
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Apaga o ficheiro, esteja ainda no disco público (valor antigo) ou no privado. */
    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        if (self::isLegacyPublic($path)) {
            Storage::disk('public')->delete(ltrim(substr($path, strlen('/storage')), '/'));

            return;
        }
        Storage::disk(self::disk())->delete($path);
    }
}
