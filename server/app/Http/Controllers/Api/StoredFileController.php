<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Support\Storage\ContentDisposition;
use App\Support\Storage\InlinePdf;
use App\Support\Storage\SignedFileUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ficheiros: abrir ou descarregar um ficheiro do catálogo por endereço assinado (30 minutos),
 * gerado só depois da empresa, do ACL e da regra da pasta (FilesController). Os ficheiros ficam
 * onde estão (disco privado, R2 ou disco público). Só as imagens (sem o SVG), os vídeos e os PDF
 * abrem no browser; o resto descarrega. O SVG descarrega sempre, com CSP "sandbox": nunca abre
 * como página. O endereço acaba no nome do ficheiro e o Content-Disposition leva o nome; um PDF
 * aberto a partir do R2 passa pela aplicação (InlinePdf), para o leitor mostrar o nome certo.
 */
class StoredFileController extends Controller
{
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'application/pdf'];

    public function show(Request $request, int $file)
    {
        $f = StoredFile::whereNull('deleted_at')->whereNull('missing_at')->find($file);
        abort_if(! $f, 404, 'Ficheiro não encontrado.');
        $disk = Storage::disk($f->disk);
        abort_unless($disk->exists($f->path), 404, 'Ficheiro não encontrado.');
        $mime = (string) ($f->mime ?: 'application/octet-stream');
        $attachment = $request->boolean('download') || ! in_array($mime, self::INLINE, true);
        $isSvg = $mime === 'image/svg+xml' || $f->extension === 'svg';
        if ($isSvg) {
            $attachment = true;
        }
        if (SignedFileUrl::supports($f->disk) && ! $attachment && $mime === 'application/pdf') {
            return InlinePdf::stream($f->disk, $f->path, $f->name);
        }
        if (SignedFileUrl::supports($f->disk)) {
            return redirect()->away(SignedFileUrl::for($f->disk, $f->path, 300, $f->name, $attachment, $isSvg ? 'application/octet-stream' : $mime), 302,
                ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        $headers = [
            'Content-Type' => $isSvg ? 'image/svg+xml' : $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=1800',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ];
        // "sandbox" em tudo, menos nos PDF: o leitor de PDF do browser não abre numa caixa de areia
        // (e já isola o documento).
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        $headers['Content-Disposition'] = ContentDisposition::make($attachment ? 'attachment' : 'inline', $f->name);

        return $disk->response($f->path, $f->name, $headers, $attachment ? 'attachment' : 'inline');
    }
}
