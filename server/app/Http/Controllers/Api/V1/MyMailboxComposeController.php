<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Services\Mailbox\MailboxInbox;
use App\Services\Mailbox\MailboxSender;
use App\Services\Mailbox\LinkMail;
use App\Services\Mailbox\MailComposer;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

/**
 * E-mail 5 · Escrever e responder pela minha caixa de correio. Só o próprio, na própria empresa
 * (RoutePermissions::OWN_ONLY; rotas sensíveis: nunca em impersonation). A partir da ficha do cliente
 * pede-se também a permissão de ver o cliente (financas.ver), e a partir da venda a de ver a viatura
 * (automovel.ver), as mesmas da secção "Conversa".
 *   GET  /companies/{id}/my-mailbox/compose-context?customer_id=|car_id=
 *   POST /companies/{id}/my-mailbox/send     {to, cc, subject, body, reply_to: {folder, uid}, customer_id, car_id, link: {kind, id}} (JSON)
 *   GET  /companies/{id}/my-mailbox/link-context?kind=financing|trade_in&link_id=   (E-mail 9)
 * E-mail 9: o link de financiamento pede financiamento.criar (e o módulo stock) e o de retoma
 * automovel.editar (e o módulo commercial_crm), as mesmas permissões do "Copiar link".
 */
class MyMailboxComposeController extends Controller
{
    public function __construct(private readonly MailComposer $composer, private readonly MailboxSender $sender) {}

    public function context(Request $request, int $companyId)
    {
        $data = $this->ids($request->validate(['customer_id' => ['nullable', 'integer'], 'car_id' => ['nullable', 'integer']]));
        $this->authorizeTarget($companyId, $data);
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();
        $context = $this->composer->context($companyId, $data['customer_id'], $data['car_id']);
        if (! $context['ok']) {
            return ApiResponse::error($context['error'], 422);
        }

        return ApiResponse::success([
            'to' => $context['to'], 'name' => $context['name'], 'consent' => $context['consent'],
            'mailbox' => $mailbox ? ['email' => $mailbox->email, 'signature' => $mailbox->signature] : null,
            'blocked' => $mailbox ? $this->sender->blockedReason($mailbox) : 'Ligue primeiro a sua caixa de correio no Perfil.',
            'attachments' => self::attachmentLimits(),
        ]);
    }

    /** E-mail 5b: os limites dos anexos, para o ecrã verificar antes de carregar. */
    public static function attachmentLimits(): array
    {
        return ['max_files' => \App\Services\Mailbox\MailAttachments::maxFiles(), 'max_bytes' => \App\Services\Mailbox\MailAttachments::maxBytes(),
            'max_file_bytes' => MyMailboxAttachmentController::maxFileBytes()];
    }

    /** E-mail 9: a mensagem já preenchida para enviar o link de financiamento ou de retoma. */
    public function linkContext(Request $request, int $companyId, LinkMail $linkMail)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(LinkMail::KINDS)], 'link_id' => ['required', 'integer']]);
        $this->authorizeLink($companyId, $data['kind']);
        $link = $linkMail->resolve($companyId, $data['kind'], (int) $data['link_id']);
        if (! $link->isOpen()) {
            return ApiResponse::error('Este link já não está ativo. Crie um link novo.', 422);
        }
        $context = $linkMail->context($link, $data['kind']);
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();

        return ApiResponse::success([
            'to' => $context['to'], 'name' => $context['name'], 'consent' => $context['consent'],
            'subject' => $context['subject'], 'body' => $context['body'], 'expires_at' => $context['expires_at'],
            'link' => ['kind' => $data['kind'], 'id' => $link->id],
            'mailbox' => $mailbox ? ['email' => $mailbox->email, 'signature' => $mailbox->signature] : null,
            'blocked' => $mailbox ? $this->sender->blockedReason($mailbox) : 'Ligue primeiro a sua caixa de correio no Perfil.',
            'attachments' => self::attachmentLimits(),
        ]);
    }

    public function send(Request $request, int $companyId, MailboxInbox $inbox)
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:200000'],
            'reply_to' => ['nullable', 'array'],
            'reply_to.folder' => ['required_with:reply_to', 'string', 'max:255'],
            'reply_to.uid' => ['required_with:reply_to', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer'],
            'car_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:' . \App\Services\Mailbox\MailAttachments::maxFiles()], // E-mail 5b: os uuid dos anexos carregados
            'attachments.*' => ['string', 'uuid'],
            'link' => ['nullable', 'array'], // E-mail 9: o link de financiamento ou de retoma
            'link.kind' => ['required_with:link', Rule::in(LinkMail::KINDS)],
            'link.id' => ['required_with:link', 'integer'],
        ], [
            'attachments.max' => 'No máximo ' . \App\Services\Mailbox\MailAttachments::maxFiles() . ' anexos por mensagem.',
            'to.required' => 'Indique pelo menos um destinatário.',
            'body.required' => 'Escreva a mensagem.',
        ]);
        $data = $this->ids($data);
        $this->authorizeTarget($companyId, $data);
        if (! empty($data['link'])) {
            $this->authorizeLink($companyId, (string) $data['link']['kind']);
            $data['financing_link'] = app(LinkMail::class)->resolve($companyId, (string) $data['link']['kind'], (int) $data['link']['id']);
        }
        $mailbox = Mailbox::personal($companyId, (int) $request->user()->id)->first();
        if (! $mailbox) {
            return ApiResponse::error('Ligue primeiro a sua caixa de correio no Perfil.', 422);
        }
        if (! empty($data['reply_to']) && ! $inbox->knows($mailbox, (string) $data['reply_to']['folder'])) {
            return ApiResponse::error('Essa pasta não existe na caixa.', 422);
        }
        if (trim(strip_tags((string) $data['body'])) === '') {
            return ApiResponse::error('Escreva a mensagem.', 422);
        }
        $data['subject'] = trim((string) ($data['subject'] ?? '')) ?: '(sem assunto)';

        $result = $this->composer->send($mailbox, $request->user(), $data);
        if (! $result['ok']) {
            return ApiResponse::error($result['error'], 422);
        }

        return ApiResponse::success(['message' => $result['message']->toConversationArray()], \App\Services\Mailbox\DevRecipientGuard::active()
            ? "Em dev, a mensagem foi só para a sua própria caixa ({$mailbox->email}), com os destinatários no assunto."
            : 'Mensagem enviada pela sua caixa.');
    }

    /** E-mail 9: as mesmas permissões (e módulos) do "Copiar link" de cada cartão. */
    private function authorizeLink(int $companyId, string $kind): void
    {
        $kind === LinkMail::FINANCING
            ? $this->authorizePermission($companyId, 'financiamento.criar', ['modules' => ['stock']])
            : $this->authorizePermission($companyId, 'automovel.editar', ['modules' => ['commercial_crm']]);
    }

    /** Os identificadores chegam como texto no multipart e na query: inteiros ou null. */
    private function ids(array $data): array
    {
        foreach (['customer_id', 'car_id'] as $key) {
            $data[$key] = empty($data[$key]) ? null : (int) $data[$key];
        }
        if (! empty($data['reply_to'])) {
            $data['reply_to']['uid'] = (int) $data['reply_to']['uid'];
        }

        return $data;
    }

    /** A ficha do cliente e a venda pedem a permissão de as ver (a mesma da secção "Conversa"), com o 403 do middleware permission. */
    private function authorizeTarget(int $companyId, array $data): void
    {
        if (! empty($data['customer_id'])) {
            $this->authorizePermission($companyId, 'financas.ver');
        }
        if (! empty($data['car_id'])) {
            $this->authorizePermission($companyId, 'automovel.ver');
        }
    }
}
