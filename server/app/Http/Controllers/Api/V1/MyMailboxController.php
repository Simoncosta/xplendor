<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\MyMailboxRequest;
use App\Models\Mailbox;
use App\Services\Mailbox\DevRecipientGuard;
use App\Services\Mailbox\MailboxClient;
use App\Services\Mailbox\MailboxImap;
use App\Services\Mailbox\OutgoingMail;
use App\Services\Mailbox\SentCopy;
use Illuminate\Http\Request;

/**
 * E-mail 1 · A minha caixa de correio (no Perfil do Utilizador). Só o próprio, na própria empresa
 * (o root também, só na sua): noutras empresas, o root e a agência recebem 403 do Access
 * (RoutePermissions::OWN_ONLY), e a impersonation também (rotas sensíveis). A palavra-passe nunca sai: a resposta diz só se existe.
 *   GET    /companies/{id}/my-mailbox
 *   PUT    /companies/{id}/my-mailbox
 *   DELETE /companies/{id}/my-mailbox
 *   POST   /companies/{id}/my-mailbox/test        (mensagem de teste para o próprio endereço)
 *   POST   /companies/{id}/my-mailbox/test-imap   (E-mail 3: liga, lista as pastas e conta os não lidos)
 *   GET    /companies/{id}/my-mailbox/unread      (E-mail 3: o número para o cabeçalho e o menu)
 */
class MyMailboxController extends Controller
{
    public function show(Request $request, int $companyId)
    {
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();

        return ApiResponse::success(['mailbox' => $mailbox?->toApiArray(), 'dev' => DevRecipientGuard::active()]);
    }

    public function update(MyMailboxRequest $request, int $companyId)
    {
        $data = $request->validated();
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first()
            ?? new Mailbox(['company_id' => $companyId, 'user_id' => (int) $request->user()->id]);
        if (($data['password'] ?? '') === '') {
            unset($data['password']); // vazia: fica a gravada
        }
        // O Gmail já guarda a cópia em Enviados quando se envia pelo smtp.gmail.com.
        $data['save_to_sent'] = strtolower($data['smtp_host']) === 'smtp.gmail.com' ? false : (bool) ($data['save_to_sent'] ?? true);
        // E-mail 4: só o Gmail escolhe se abrir na XPLENDOR marca como lida no servidor; nos outros, marca sempre.
        $data['mark_seen_on_server'] = $data['account_type'] === 'gmail' ? (bool) ($data['mark_seen_on_server'] ?? true) : true;
        // E-mail 5: os endereços de teste só existem em dev; em produção não se gravam nem têm efeito.
        if (! DevRecipientGuard::active()) {
            unset($data['dev_test_addresses']);
        } elseif (array_key_exists('dev_test_addresses', $data)) {
            $list = array_values(array_unique(array_map('strtolower', OutgoingMail::split((string) $data['dev_test_addresses']))));
            $data['dev_test_addresses'] = $list ? implode(', ', $list) : null;
        }
        if (array_key_exists('signature', $data)) {
            $data['signature'] = trim((string) $data['signature']) === '' ? null : rtrim((string) $data['signature']);
        }
        $mailbox->fill($data);
        // Só a ligação obriga a testar de novo (a assinatura e as opções de leitura não).
        if ($mailbox->isDirty(['account_type', 'email', 'smtp_host', 'smtp_port', 'smtp_encryption', 'imap_host', 'imap_port', 'imap_encryption', 'username', 'password'])) {
            // Mudou: tem de voltar a ser testada (o envio e o IMAP; a pasta de Enviados volta a ser procurada).
            $mailbox->fill(['status' => Mailbox::STATUS_PENDING, 'last_error' => null]);
            if ($mailbox->isDirty(['imap_host', 'imap_port', 'imap_encryption', 'username', 'password', 'email'])) {
                $mailbox->fill(['imap_status' => null, 'imap_error' => null, 'sent_folder' => null, 'trash_folder' => null, 'unread_count' => null, 'unread_checked_at' => null, 'uidvalidity' => null, 'last_uid' => null]);
            }
        }
        $mailbox->save();

        return ApiResponse::success(['mailbox' => $mailbox->fresh()->toApiArray(), 'dev' => DevRecipientGuard::active()], 'Caixa de correio guardada.');
    }

    public function destroy(Request $request, int $companyId)
    {
        Mailbox::personal($companyId, (int) $request->user()->id)->delete();

        return ApiResponse::success(['mailbox' => null], 'Caixa de correio removida.');
    }

    public function test(Request $request, int $companyId, MailboxClient $client, SentCopy $sentCopy)
    {
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();
        if (! $mailbox) {
            return ApiResponse::error('Guarde primeiro a caixa de correio.', 422);
        }
        $result = $client->send($mailbox, new OutgoingMail(
            to: $mailbox->email,
            subject: 'Teste da caixa de correio na XPLENDOR',
            text: "Esta mensagem confirma que a XPLENDOR consegue enviar pela sua caixa de correio ({$mailbox->email}).\n\nPode apagá-la.",
            fromName: (string) $request->user()->name,
        ));
        $mailbox->forceFill([
            'status' => $result->ok ? Mailbox::STATUS_ACTIVE : Mailbox::STATUS_ERROR,
            'last_error' => $result->error,
            'last_tested_at' => now(),
        ])->save();

        if (! $result->ok) {
            return ApiResponse::error($result->error, 422, ['mailbox' => $mailbox->toApiArray()]);
        }
        $copyError = $sentCopy->store($mailbox, $result);
        $message = "Mensagem de teste enviada para {$mailbox->email}." . ($copyError ? " A cópia em Enviados falhou: {$copyError}" : '');

        return ApiResponse::success(['mailbox' => $mailbox->fresh()->toApiArray(), 'sent_copy_error' => $copyError], $message);
    }

    public function testImap(Request $request, int $companyId, MailboxImap $imap, \App\Services\Mailbox\UnreadCounter $counter)
    {
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();
        if (! $mailbox) {
            return ApiResponse::error('Guarde primeiro a caixa de correio.', 422);
        }
        $check = $imap->check($mailbox);
        if ($check->sentFolder) {
            $mailbox->sent_folder = $check->sentFolder;
        }
        $error = $check->ok ? null : $check->error;
        $mailbox->forceFill(['imap_tested_at' => now()])->save();
        // O número de não lidos (E-mail 4: no Gmail com a opção desligada, sem as já abertas na XPLENDOR).
        $error ??= $counter->refresh($mailbox);
        if ($error !== null) {
            $mailbox->forceFill(['imap_status' => Mailbox::STATUS_ERROR, 'imap_error' => $error])->save();

            return ApiResponse::error((string) $error, 422, ['mailbox' => $mailbox->toApiArray()]);
        }
        $mailbox->refresh();
        $sent = $mailbox->keepsSentCopyItself() ? 'O Gmail guarda sozinho a cópia em Enviados.'
            : ($mailbox->sent_folder ? "Pasta de Enviados: {$mailbox->sent_folder}." : 'Não foi encontrada a pasta de Enviados.');

        return ApiResponse::success(['mailbox' => $mailbox->toApiArray()], "IMAP ligado: {$mailbox->unread_count} por ler na Entrada. {$sent}");
    }

    /** O número de não lidos (null sem caixa ou ainda sem contagem): o cabeçalho e o menu mostram-no acima de 0. */
    public function unread(Request $request, int $companyId)
    {
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();

        return ApiResponse::success([
            'has_mailbox' => $mailbox !== null,
            'unread_count' => $mailbox?->unread_count,
            'checked_at' => $mailbox?->unread_checked_at?->toIso8601String(),
        ]);
    }
}
