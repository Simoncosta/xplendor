<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Services\Mailbox\MailboxInbox;
use App\Services\Mailbox\MailParser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * E-mail 4 · A minha caixa de entrada, lida ao vivo do servidor de e-mail. Só o próprio, na própria
 * empresa (RoutePermissions::OWN_ONLY; rotas sensíveis: nunca em impersonation).
 *   GET  /companies/{id}/my-mailbox/folders
 *   GET  /companies/{id}/my-mailbox/messages?folder=&page=
 *   GET  /companies/{id}/my-mailbox/messages/{uid}?folder=
 *   POST /companies/{id}/my-mailbox/messages/{uid}/seen            {folder, seen}
 *   GET  /companies/{id}/my-mailbox/messages/{uid}/attachments/{index}?folder=&inline=1
 *   POST /companies/{id}/my-mailbox/messages/{uid}/link            {folder, customer_id}
 */
class MyMailboxInboxController extends Controller
{
    public function __construct(private readonly MailboxInbox $inbox) {}

    public function folders(Request $request, int $companyId)
    {
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return $this->noMailbox();
        }
        $result = $this->inbox->folders($mailbox);

        return $result['ok'] ? ApiResponse::success(['folders' => $result['folders']]) : ApiResponse::error($result['error'], 422);
    }

    public function messages(Request $request, int $companyId)
    {
        $data = $request->validate(['folder' => ['required', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return $this->noMailbox();
        }
        if (! $this->inbox->knows($mailbox, $data['folder'])) {
            return ApiResponse::error('Essa pasta não existe na caixa.', 422);
        }
        $result = $this->inbox->page($mailbox, $data['folder'], (int) ($data['page'] ?? 1));
        unset($result['ok']);

        return isset($result['error']) ? ApiResponse::error($result['error'], 422) : ApiResponse::success($result);
    }

    public function show(Request $request, int $companyId, int $uid)
    {
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->message($mailbox, $folder, $uid);

        return $result['ok'] ? ApiResponse::success(['message' => $result['message']]) : ApiResponse::error($result['error'], 422);
    }

    public function seen(Request $request, int $companyId, int $uid)
    {
        $request->validate(['seen' => ['required', 'boolean']]);
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->seen($mailbox, $folder, $uid, $request->boolean('seen'));

        return $result['ok'] ? ApiResponse::success(['unread' => $result['unread']]) : ApiResponse::error($result['error'], 422);
    }

    /** O anexo, ao vivo da caixa: "Ver" (inline=1) só para PDF e imagens; o resto descarrega-se. */
    public function attachment(Request $request, int $companyId, int $uid, int $index)
    {
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->attachment($mailbox, $folder, $uid, $index);
        if (! $result['ok']) {
            return ApiResponse::error($result['error'], 422);
        }
        $file = $result['file'];
        $inline = $request->boolean('inline') && in_array($file['mime'], MailParser::PREVIEWABLE, true);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']) ?: 'anexo';

        return response($file['content'], 200, [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . "; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($file['name']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function link(Request $request, int $companyId, int $uid)
    {
        $data = $request->validate(['customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)]],
            ['customer_id.exists' => 'Esse cliente não existe nesta empresa.']);
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $customer = Customer::where('company_id', $companyId)->findOrFail($data['customer_id']);
        $result = $this->inbox->link($mailbox, $request->user(), $folder, $uid, $customer);

        return $result['ok'] ? ApiResponse::success(['customer' => $result['customer']], "Mensagem ligada a {$customer->name}.") : ApiResponse::error($result['error'], 422);
    }

    private function mailbox(Request $request, int $companyId): ?Mailbox
    {
        return Mailbox::personal($companyId, (int) $request->user()->id)->first();
    }

    /** @return array{0: ?Mailbox, 1: string, 2: mixed} */
    private function resolve(Request $request, int $companyId): array
    {
        $folder = (string) $request->input('folder', '');
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return [null, $folder, $this->noMailbox()];
        }
        if ($folder === '' || mb_strlen($folder) > 255 || ! $this->inbox->knows($mailbox, $folder)) {
            return [$mailbox, $folder, ApiResponse::error('Essa pasta não existe na caixa.', 422)];
        }

        return [$mailbox, $folder, null];
    }

    private function noMailbox()
    {
        return ApiResponse::error('Ligue primeiro a sua caixa de correio no Perfil.', 422);
    }
}
