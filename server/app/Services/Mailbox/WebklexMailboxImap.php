<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * O IMAP real (webklex/php-imap, IMAP em PHP puro). Uma ligação por operação, fechada no fim.
 *
 * Fora de produção, sem mailboxes.real_send, não liga a nenhum servidor (o dev não tem IMAP: o
 * Mailpit só recebe). Em produção, só liga a um nome que resolve para endereços públicos
 * (MailboxHosts), como o envio.
 */
class WebklexMailboxImap implements MailboxImap
{
    public function check(Mailbox $mailbox): ImapResult
    {
        return $this->with($mailbox, function (Client $client) {
            $list = $client->getConnection()->folders('', '*')->validatedData();

            return ImapResult::ok(SentFolder::find(array_map(fn ($f) => (array) ($f['flags'] ?? []), (array) $list)));
        });
    }

    public function append(Mailbox $mailbox, string $folder, string $raw): ImapResult
    {
        return $this->with($mailbox, function (Client $client) use ($folder, $raw) {
            $client->getConnection()->appendMessage($folder, $raw, ['\\Seen'])->validatedData();

            return ImapResult::ok();
        });
    }

    public function unseen(Mailbox $mailbox): ImapResult
    {
        return $this->with($mailbox, function (Client $client) {
            $status = $client->getConnection()->folderStatus('INBOX', ['UNSEEN', 'UIDVALIDITY'])->validatedData();

            return ImapResult::ok(null, (int) ($status['unseen'] ?? 0), isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null);
        });
    }

    /** @param callable(Client): ImapResult $work */
    private function with(Mailbox $mailbox, callable $work): ImapResult
    {
        if (! app()->environment('production') && ! config('mailboxes.real_send')) {
            return ImapResult::failed('Em dev, a XPLENDOR não liga ao servidor de e-mail por IMAP.');
        }
        if (MailboxHosts::publicAddress($mailbox->imap_host) === null) {
            return ImapResult::failed("O servidor {$mailbox->imap_host} não foi encontrado ou não é um servidor de e-mail público.");
        }
        $client = null;
        try {
            $client = (new ClientManager())->make([
                'host' => strtolower($mailbox->imap_host),
                'port' => $mailbox->imap_port,
                'protocol' => 'imap',
                'encryption' => $mailbox->imap_encryption === 'starttls' ? 'starttls' : 'ssl',
                'validate_cert' => true,
                'username' => $mailbox->username,
                'password' => (string) $mailbox->password,
                'timeout' => (int) config('mailboxes.timeout'),
            ]);
            $client->connect();

            return $work($client);
        } catch (Throwable $e) {
            Log::info('[Caixa de correio] IMAP falhou', ['mailbox_id' => $mailbox->id, 'type' => class_basename($e)]);

            return ImapResult::failed($this->describe($e, $mailbox));
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // a ligação já caiu
            }
        }
    }

    /** O motivo em português, sem a palavra-passe nem o texto cru do servidor. */
    private function describe(Throwable $e, Mailbox $mailbox): string
    {
        $message = strtolower($e->getMessage());
        $where = "{$mailbox->imap_host}:{$mailbox->imap_port}";

        return match (true) {
            $e instanceof \Webklex\PHPIMAP\Exceptions\AuthFailedException || str_contains($message, 'authenticat') || str_contains($message, 'login')
                => 'O servidor IMAP recusou o utilizador ou a palavra-passe.' . ($mailbox->account_type === 'gmail' ? ' No Gmail, use uma palavra-passe de app e confirme que o IMAP está ativo.' : ''),
            str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'crypto')
                => "Falhou a ligação segura (SSL/TLS) a {$where}. Confirme a porta e o tipo de segurança.",
            str_contains($message, 'timed out') || str_contains($message, 'timeout')
                => "O servidor {$where} não respondeu a tempo.",
            $e instanceof \Webklex\PHPIMAP\Exceptions\ConnectionFailedException || str_contains($message, 'connection')
                => "Não foi possível ligar a {$where}.",
            str_contains($message, 'trycreate') || str_contains($message, 'mailbox') || str_contains($message, 'folder')
                => 'A pasta de Enviados não existe no servidor.',
            default => 'O servidor IMAP não aceitou o pedido.',
        };
    }
}
