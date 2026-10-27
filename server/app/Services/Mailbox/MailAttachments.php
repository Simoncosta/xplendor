<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\Mailbox;
use App\Models\MailAttachmentUpload;
use App\Models\MailMessage;
use App\Models\User;
use App\Services\Financing\Antivirus\ClamdUnavailable;
use App\Services\Financing\Antivirus\VirusScanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * E-mail 5b · Os anexos de uma mensagem a enviar, como no Gmail: cada ficheiro (do computador ou dos
 * Ficheiros) é recusado se for um executável (pela extensão e pelo conteúdo), passa pelo ClamAV ao
 * carregar e fica só temporariamente, em privado, até ao envio. Até 10 ficheiros e 20 MB no total por
 * mensagem. Depois do envio o ficheiro apaga-se (fica só o nome e o tamanho na mensagem guardada); se
 * a mensagem nunca for enviada, apaga-se ao fim de 24 horas (purgeExpired, de hora a hora).
 */
class MailAttachments
{
    public function __construct(private readonly VirusScanner $scanner) {}

    public static function maxFiles(): int
    {
        return (int) config('mailboxes.attachments.max_files', 10);
    }

    public static function maxBytes(): int
    {
        return (int) config('mailboxes.attachments.max_bytes', 20 * 1024 * 1024);
    }

    /** @return array{ok: bool, upload?: MailAttachmentUpload, error?: string, code?: int} */
    public function store(Mailbox $mailbox, User $user, string $content, string $name, string $source = 'upload', ?int $sourceFileId = null): array
    {
        $name = self::safeName($name);
        if (strlen($content) === 0) {
            return ['ok' => false, 'error' => "O ficheiro \"{$name}\" está vazio.", 'code' => 422];
        }
        if (strlen($content) > self::maxBytes()) {
            return ['ok' => false, 'error' => "O ficheiro \"{$name}\" tem mais de " . self::mb(self::maxBytes()) . ' (o máximo por mensagem).', 'code' => 422];
        }
        if ($refusal = ExecutableGuard::refusal($name, $content)) {
            return ['ok' => false, 'error' => $refusal, 'code' => 422];
        }

        $disk = (string) config('mailboxes.attachments.disk', 'local');
        $uuid = (string) Str::uuid();
        $path = "mail-attachments/{$mailbox->company_id}/{$uuid}";
        Storage::disk($disk)->put($path, $content);
        if ($refused = $this->scan($disk, $path, $mailbox, $name)) {
            Storage::disk($disk)->delete($path);

            return $refused;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: 'application/octet-stream';

        $upload = MailAttachmentUpload::create([
            'uuid' => $uuid, 'company_id' => $mailbox->company_id, 'user_id' => $user->id, 'mailbox_id' => $mailbox->id,
            'name' => $name, 'mime' => $mime, 'size' => strlen($content), 'disk' => $disk, 'path' => $path,
            'source' => $source, 'source_file_id' => $sourceFileId, 'expires_at' => now()->addHours((int) config('mailboxes.attachments.keep_hours', 24)),
        ]);

        return ['ok' => true, 'upload' => $upload];
    }

    /** Os anexos escolhidos para enviar: da própria caixa, ainda por enviar e dentro dos limites. @return array{ok: bool, uploads?: Collection, error?: string} */
    public function forSend(Mailbox $mailbox, User $user, array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (! $ids) {
            return ['ok' => true, 'uploads' => collect()];
        }
        if (count($ids) > self::maxFiles()) {
            return ['ok' => false, 'error' => 'No máximo ' . self::maxFiles() . ' anexos por mensagem.'];
        }
        $uploads = MailAttachmentUpload::whereIn('uuid', $ids)->where('mailbox_id', $mailbox->id)->where('user_id', $user->id)
            ->whereNull('mail_message_id')->where('expires_at', '>', now())->get();
        if ($uploads->count() !== count($ids)) {
            return ['ok' => false, 'error' => 'Um dos anexos já não está disponível (passaram 24 horas ou foi removido). Volte a anexá-lo.'];
        }
        if ($uploads->sum('size') > self::maxBytes()) {
            return ['ok' => false, 'error' => 'Os anexos passam de ' . self::mb(self::maxBytes()) . ' no total (o máximo por mensagem).'];
        }

        return ['ok' => true, 'uploads' => $uploads->sortBy(fn ($u) => array_search($u->uuid, $ids, true))->values()];
    }

    /** Liga os anexos à mensagem (antes de ir para a fila) e guarda na mensagem só o nome e o tamanho. */
    public function attach(MailMessage $message, Collection $uploads): void
    {
        if ($uploads->isEmpty()) {
            return;
        }
        MailAttachmentUpload::whereIn('id', $uploads->pluck('id'))->update(['mail_message_id' => $message->id]);
        $message->forceFill(['attachments' => $uploads->map(fn ($u) => ['name' => $u->name, 'size' => $u->size])->values()->all()])->save();
    }

    /** Os anexos de uma mensagem, para o envio. @return list<array{name: string, mime: string, content: string}> */
    public function contents(MailMessage $message): array
    {
        return MailAttachmentUpload::where('mail_message_id', $message->id)->orderBy('id')->get()
            ->map(fn ($u) => ['name' => $u->name, 'mime' => $u->mime, 'content' => (string) Storage::disk($u->disk)->get($u->path)])->all();
    }

    /** Depois do envio (bem ou mal): os ficheiros apagam-se; fica só o nome e o tamanho na mensagem. */
    public function purgeFor(MailMessage $message): void
    {
        $this->delete(MailAttachmentUpload::where('mail_message_id', $message->id)->get());
    }

    public function remove(Mailbox $mailbox, User $user, string $uuid): bool
    {
        $uploads = MailAttachmentUpload::where('uuid', $uuid)->where('mailbox_id', $mailbox->id)->where('user_id', $user->id)->whereNull('mail_message_id')->get();
        $this->delete($uploads);

        return $uploads->isNotEmpty();
    }

    /** Os que passaram das 24 horas (mensagens nunca enviadas, ou um envio interrompido). Devolve quantos apagou. */
    public function purgeExpired(): int
    {
        $count = 0;
        MailAttachmentUpload::where('expires_at', '<=', now())->chunkById(200, function ($uploads) use (&$count) {
            $this->delete($uploads);
            $count += $uploads->count();
        });

        return $count;
    }

    private function delete(Collection $uploads): void
    {
        foreach ($uploads as $u) {
            Storage::disk($u->disk)->delete($u->path);
            $u->delete();
        }
    }

    private function scan(string $disk, string $path, Mailbox $mailbox, string $name): ?array
    {
        try {
            $verdict = $this->scanner->scan($disk, $path);
        } catch (ClamdUnavailable) {
            return ['ok' => false, 'error' => 'O antivírus não está disponível neste momento, por isso o anexo não foi carregado. Tente daqui a pouco.', 'code' => 503];
        } catch (Throwable $e) {
            Log::warning('E-mail 5b: a análise antivírus do anexo falhou.', ['mailbox_id' => $mailbox->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Não foi possível analisar o anexo com o antivírus, por isso não foi carregado.', 'code' => 503];
        }
        if ($verdict !== VirusScanner::CLEAN) {
            Log::warning('E-mail 5b: anexo com vírus recusado.', ['mailbox_id' => $mailbox->id, 'signature' => $this->scanner->lastSignature()]);

            return ['ok' => false, 'error' => "O antivírus encontrou um vírus em \"{$name}\". O anexo não foi carregado.", 'code' => 422];
        }

        return null;
    }

    private static function safeName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n", "\0", '/', '\\'], ' ', $name));

        return mb_substr($name !== '' ? $name : 'anexo', 0, 200);
    }

    private static function mb(int $bytes): string
    {
        return round($bytes / 1048576) . ' MB';
    }
}
