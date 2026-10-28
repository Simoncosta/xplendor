<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Services\Mailbox\AttachmentInvoiceImport;
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
 *   GET  /companies/{id}/my-mailbox/thread?folder=&uids=1,2,3        (E-mail 4b: a conversa numa só ligação)
 *   POST /companies/{id}/my-mailbox/seen                            {folder, uids, seen} (E-mail 4b: várias de uma vez)
 *   POST /companies/{id}/my-mailbox/messages/{uid}/attachments/{index}/import-invoice  {folder} (E-mail 7: para as Faturas)
 * E-mail 10 (em JSON):
 *   GET  /companies/{id}/my-mailbox/messages?folder=INBOX&starred=1   (o filtro "Com estrela")
 *   POST /companies/{id}/my-mailbox/flag      {folder, uids, flagged}   (a estrela: \Flagged)
 *   POST /companies/{id}/my-mailbox/trash     {folder, uids, message_ids} (para o Lixo da caixa, nunca de vez)
 *   POST /companies/{id}/my-mailbox/restore   {folder, message_ids}     ("Desfazer": do Lixo para a pasta)
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
        $data = $request->validate(['folder' => ['required', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'starred' => ['nullable', 'boolean']]);
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return $this->noMailbox();
        }
        if (! $this->inbox->knows($mailbox, $data['folder'])) {
            return ApiResponse::error('Essa pasta não existe na caixa.', 422);
        }
        $result = $this->inbox->page($mailbox, $data['folder'], (int) ($data['page'] ?? 1), (bool) ($data['starred'] ?? false));
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

    public function thread(Request $request, int $companyId)
    {
        $uids = $this->uids($request->input('uids'));
        if ($uids === null) {
            return ApiResponse::error('Indique as mensagens da conversa.', 422);
        }
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->thread($mailbox, $folder, $uids);

        return $result['ok'] ? ApiResponse::success(['messages' => $result['messages']]) : ApiResponse::error($result['error'], 422);
    }

    public function seenMany(Request $request, int $companyId)
    {
        $request->validate(['seen' => ['required', Rule::in([true, false, 1, 0, '1', '0', 'true', 'false'])]], ['seen.in' => 'Indique se as mensagens ficam lidas ou por ler.']);
        $uids = $this->uids($request->input('uids'));
        if ($uids === null) {
            return ApiResponse::error('Indique as mensagens a marcar.', 422);
        }
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->seenMany($mailbox, $folder, $uids, $request->boolean('seen'));

        return $result['ok'] ? ApiResponse::success(['unread' => $result['unread']]) : ApiResponse::error($result['error'], 422);
    }

    /** E-mail 10 · A estrela (\Flagged) em várias de uma vez. */
    public function flag(Request $request, int $companyId)
    {
        $request->validate(['flagged' => ['required', 'boolean']], ['flagged.*' => 'Indique se as mensagens ficam com estrela ou sem.']);
        $uids = $this->uids($request->input('uids'));
        if ($uids === null) {
            return ApiResponse::error('Indique as mensagens.', 422);
        }
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->flagMany($mailbox, $folder, $uids, $request->boolean('flagged'));

        return $result['ok'] ? ApiResponse::success(['flagged' => $request->boolean('flagged')]) : ApiResponse::error($result['error'], 422);
    }

    /** E-mail 10 · Apagar: para o Lixo da caixa (nunca de vez), com o que o "Desfazer" precisa. */
    public function trash(Request $request, int $companyId)
    {
        $request->validate(['message_ids' => ['nullable', 'array', 'max:50'], 'message_ids.*' => ['nullable', 'string', 'max:500']]);
        $uids = $this->uids($request->input('uids'));
        if ($uids === null) {
            return ApiResponse::error('Indique as mensagens a apagar.', 422);
        }
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->trash($mailbox, $folder, $uids, array_values(array_filter((array) $request->input('message_ids', []), 'is_string')));
        if (! $result['ok']) {
            // Sem Lixo: o ecrã mostra o link para as definições da caixa (code: no_trash_folder).
            return ApiResponse::error($result['error'], 422, isset($result['code']) ? ['code' => $result['code']] : []);
        }
        unset($result['ok']);

        return ApiResponse::success($result, $result['moved'] === 1 ? 'Mensagem movida para o Lixo.' : "{$result['moved']} mensagens movidas para o Lixo.");
    }

    /**
     * E-mail 10b · A pasta do Lixo, nas definições da caixa: a que o servidor marca com \Trash (só para
     * informação) ou, sem marca, a escolhida à mão e as pastas que existem na caixa para escolher.
     */
    public function trashFolder(Request $request, int $companyId)
    {
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return $this->noMailbox();
        }
        $folders = $this->inbox->folders($mailbox);
        if (! $folders['ok']) {
            return ApiResponse::error($folders['error'], 422);
        }
        $marked = $this->inbox->markedTrash($mailbox);

        return ApiResponse::success([
            'marked' => $marked,
            'chosen' => $marked ? null : $mailbox->trash_folder,
            // Para escolher: as pastas da caixa, sem a Entrada (nunca é o Lixo).
            'folders' => $marked ? [] : array_values(array_map(fn ($f) => ['path' => $f['path'], 'name' => $f['name']],
                array_filter($folders['folders'], fn ($f) => $f['special'] !== 'inbox'))),
        ]);
    }

    /** E-mail 10b · Escolher a pasta do Lixo à mão (só sem pasta marcada com \Trash; sempre uma que já existe). */
    public function updateTrashFolder(Request $request, int $companyId)
    {
        $data = $request->validate(['folder' => ['nullable', 'string', 'max:255']]);
        $mailbox = $this->mailbox($request, $companyId);
        if (! $mailbox) {
            return $this->noMailbox();
        }
        $folder = $data['folder'] ?? null;
        if ($folder !== null) {
            if (($marked = $this->inbox->markedTrash($mailbox)) !== null) {
                return ApiResponse::error("O servidor já marca a pasta {$marked} como Lixo: é essa que a XPLENDOR usa.", 422);
            }
            if (strtoupper($folder) === 'INBOX' || ! $this->inbox->knows($mailbox, $folder)) {
                return ApiResponse::error('Essa pasta não existe na caixa. Escolha uma das pastas que já existem (a XPLENDOR não cria pastas).', 422);
            }
        }
        $mailbox->forceFill(['trash_folder' => $folder])->save();

        return ApiResponse::success(['chosen' => $folder], $folder ? "Pasta do Lixo: {$folder}." : 'Pasta do Lixo retirada.');
    }

    /** E-mail 10 · "Desfazer": do Lixo de volta para a pasta de onde saíram. */
    public function restore(Request $request, int $companyId)
    {
        $data = $request->validate(['message_ids' => ['required', 'array', 'min:1', 'max:50'], 'message_ids.*' => ['string', 'max:500']],
            ['message_ids.required' => 'Indique as mensagens a repor.']);
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $this->inbox->restore($mailbox, $folder, $data['message_ids']);
        if (! $result['ok']) {
            return ApiResponse::error($result['error'], 422);
        }
        unset($result['ok']);

        return ApiResponse::success($result, 'Desfeito: as mensagens voltaram.');
    }

    /** Os UID (lista ou "1,2,3"): só números, até 50. @return list<int>|null */
    private function uids(mixed $value): ?array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        $uids = array_values(array_unique(array_filter(array_map(fn ($u) => ctype_digit(trim((string) $u)) ? (int) trim((string) $u) : 0, $list))));

        return $uids && count($uids) <= 50 ? $uids : null;
    }

    public function seen(Request $request, int $companyId, int $uid)
    {
        // Também "true"/"false" em texto (um pedido multipart traz os booleanos assim).
        $request->validate(['seen' => ['required', Rule::in([true, false, 1, 0, '1', '0', 'true', 'false'])]], ['seen.in' => 'Indique se a mensagem fica lida ou por ler.']);
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

    /**
     * E-mail 7 · "Importar para as Faturas": só quem pode carregar faturas (restauracao.criar e o
     * módulo das Faturas, como o carregamento manual). O anexo passa pelo ClamAV e entra no fluxo do
     * carregamento manual; o duplicado devolve 409 com a fatura que já existe.
     */
    public function importInvoice(Request $request, int $companyId, int $uid, int $index, AttachmentInvoiceImport $import)
    {
        [$mailbox, $folder, $error] = $this->resolve($request, $companyId);
        if ($error) {
            return $error;
        }
        $result = $import->import($mailbox, $folder, $uid, $index);
        if ($result['status'] === 'refused') {
            return ApiResponse::error($result['error'], $result['code']);
        }
        if ($result['status'] === 'created') {
            return ApiResponse::success(['id' => $result['invoice']->id, 'status' => $result['invoice']->status], 'Fatura criada. A leitura corre agora; recebe um aviso quando terminar.');
        }

        return CompanyInvoiceOcrController::intakeResponse($result);
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
