<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Services\Files\FileAccess;
use App\Services\Files\FilesService;
use App\Services\Financing\FinancingFiles;
use App\Services\Mailbox\MailAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * E-mail 5b · Os anexos de uma mensagem a enviar. Só o próprio, na própria empresa
 * (RoutePermissions::OWN_ONLY; rotas sensíveis: nunca em impersonation). O carregamento vai em
 * multipart (tem o ficheiro); o resto em JSON. Os dos Ficheiros pedem ficheiros.ver (e o módulo) e só
 * se anexa o que a pessoa pode ver; os documentos de financiamento e as fotografias das retomas não saem.
 *   POST   /companies/{id}/my-mailbox/attachments             (multipart: file)
 *   POST   /companies/{id}/my-mailbox/attachments/from-files  {file_id} (JSON)
 *   DELETE /companies/{id}/my-mailbox/attachments/{uuid}
 */
class MyMailboxAttachmentController extends Controller
{
    public function __construct(private readonly MailAttachments $attachments) {}

    /** O maior ficheiro que o servidor aceita num carregamento (o PHP pode limitar abaixo dos 20 MB por mensagem). */
    public static function maxFileBytes(): int
    {
        $ini = (string) ini_get('upload_max_filesize');
        $bytes = (int) $ini * match (strtoupper(substr($ini, -1))) { 'G' => 1073741824, 'M' => 1048576, 'K' => 1024, default => 1 };

        return $bytes > 0 ? min($bytes, MailAttachments::maxBytes()) : MailAttachments::maxBytes();
    }

    public function store(Request $request, int $companyId)
    {
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return ApiResponse::error('Ligue primeiro a sua caixa de correio no Perfil.', 422);
        }
        $max = (int) round(self::maxFileBytes() / 1048576);
        $request->validate(['file' => ['required', 'file', 'max:' . (int) (self::maxFileBytes() / 1024)]], [
            'file.required' => 'Escolha um ficheiro.',
            'file.file' => "O ficheiro não chegou ao servidor (o máximo por ficheiro é {$max} MB).",
            'file.uploaded' => "O ficheiro não chegou ao servidor (o máximo por ficheiro é {$max} MB).",
            'file.max' => "O ficheiro tem mais de {$max} MB.",
        ]);
        $file = $request->file('file');
        $result = $this->attachments->store($mailbox, $request->user(), (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());

        return $result['ok'] ? ApiResponse::success(['attachment' => $result['upload']->toApiArray()], 'Anexo carregado.') : ApiResponse::error($result['error'], $result['code']);
    }

    public function fromFiles(Request $request, int $companyId, FilesService $files, FileAccess $access)
    {
        $data = $request->validate(['file_id' => ['required', 'integer']]);
        $this->authorizePermission($companyId, 'ficheiros.ver', ['modules' => ['ficheiros']]);
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return ApiResponse::error('Ligue primeiro a sua caixa de correio no Perfil.', 422);
        }
        // Só da empresa e só o que a pessoa pode ver (senão, como nos Ficheiros: não existe).
        $file = $files->file($companyId, (int) $data['file_id']);
        if (! $access->canSeeFile($request->user(), $file)) {
            return ApiResponse::error('Ficheiro não encontrado.', 404);
        }
        if (in_array($file->source, [FinancingFiles::SOURCE, FinancingFiles::SOURCE_TRADE_IN], true)) {
            return ApiResponse::error('Os documentos de financiamento e as fotografias das retomas não se enviam por e-mail.', 422);
        }
        if ((int) $file->size_bytes > MailAttachments::maxBytes()) {
            return ApiResponse::error("O ficheiro \"{$file->name}\" tem mais de " . round(MailAttachments::maxBytes() / 1048576) . ' MB (o máximo por mensagem).', 422);
        }
        $content = Storage::disk($file->disk)->get($file->path);
        if ($content === null) {
            return ApiResponse::error('O ficheiro já não está disponível nos Ficheiros.', 422);
        }
        $result = $this->attachments->store($mailbox, $request->user(), $content, (string) $file->name, 'files', (int) $file->id);

        return $result['ok'] ? ApiResponse::success(['attachment' => $result['upload']->toApiArray()], 'Anexo carregado.') : ApiResponse::error($result['error'], $result['code']);
    }

    public function destroy(Request $request, int $companyId, string $uuid)
    {
        $mailbox = $this->mailbox($request, $companyId);
        if ($mailbox) {
            $this->attachments->remove($mailbox, $request->user(), $uuid);
        }

        return ApiResponse::success(null, 'Anexo removido.');
    }

    private function mailbox(Request $request, int $companyId): ?Mailbox
    {
        return Mailbox::personal($companyId, (int) $request->user()->id)->first();
    }
}
