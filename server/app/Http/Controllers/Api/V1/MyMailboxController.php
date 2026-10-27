<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\MyMailboxRequest;
use App\Models\Mailbox;
use App\Services\Mailbox\MailboxClient;
use App\Services\Mailbox\OutgoingMail;
use Illuminate\Http\Request;

/**
 * E-mail 1 · A minha caixa de correio (no Perfil do Utilizador). Só o próprio, na própria empresa
 * (o root também, só na sua): noutras empresas, o root e a agência recebem 403 do Access
 * (RoutePermissions::OWN_ONLY), e a impersonation também (rotas sensíveis). A palavra-passe nunca sai: a resposta diz só se existe.
 *   GET    /companies/{id}/my-mailbox
 *   PUT    /companies/{id}/my-mailbox
 *   DELETE /companies/{id}/my-mailbox
 *   POST   /companies/{id}/my-mailbox/test   (mensagem de teste para o próprio endereço)
 */
class MyMailboxController extends Controller
{
    public function show(Request $request, int $companyId)
    {
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();

        return ApiResponse::success(['mailbox' => $mailbox?->toApiArray()]);
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
        $mailbox->fill($data);
        if ($mailbox->isDirty()) {
            $mailbox->fill(['status' => Mailbox::STATUS_PENDING, 'last_error' => null]); // mudou: tem de voltar a ser testada
        }
        $mailbox->save();

        return ApiResponse::success(['mailbox' => $mailbox->fresh()->toApiArray()], 'Caixa de correio guardada.');
    }

    public function destroy(Request $request, int $companyId)
    {
        Mailbox::personal($companyId, (int) $request->user()->id)->delete();

        return ApiResponse::success(['mailbox' => null], 'Caixa de correio removida.');
    }

    public function test(Request $request, int $companyId, MailboxClient $client)
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
        $message = $result->delivered
            ? "Mensagem de teste enviada para {$mailbox->email}."
            : 'Mensagem de teste enviada para o Mailpit (em dev, o envio nunca sai para fora).';

        return ApiResponse::success(['mailbox' => $mailbox->toApiArray(), 'delivered' => $result->delivered], $message);
    }
}
