<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SatisfactionReportPhoto;
use App\Models\SupportTicket;
use App\Support\Storage\PrivateFiles;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ficheiros privados por URL assinado de curta duração (middleware signed:relative), sem
 * sessão: as ligações e as etiquetas <img> não enviam o token. O URL só é gerado pelo
 * backend nas respostas que já verificaram a empresa e o ACL (ou o token do relatório).
 */
class PrivateFileController extends Controller
{
    public function ticketInvoice(int $ticket): Response
    {
        $t = SupportTicket::find($ticket);
        abort_if(! $t || ! $t->invoice_path || PrivateFiles::isLegacyPublic($t->invoice_path), 404);

        return PrivateFiles::serve($t->invoice_path, 'application/pdf', "fatura-pedido-{$t->id}.pdf");
    }

    public function reportPhoto(int $photo): Response
    {
        $p = SatisfactionReportPhoto::find($photo);
        abort_if(! $p || ! $p->path || PrivateFiles::isLegacyPublic($p->path), 404);

        return PrivateFiles::serve($p->path, 'image/webp');
    }
}
