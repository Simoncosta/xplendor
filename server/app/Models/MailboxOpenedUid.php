<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * E-mail 4 · Uma mensagem que a pessoa já abriu na XPLENDOR, numa caixa Gmail com a opção "marcar
 * também como lida no Gmail" desligada: só a pasta, o UIDVALIDITY e o UID (nenhum conteúdo).
 */
class MailboxOpenedUid extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['mailbox_id', 'folder', 'uidvalidity', 'uid'];

    protected $casts = ['uidvalidity' => 'integer', 'uid' => 'integer'];

    /** Os UIDs abertos desta pasta, no UIDVALIDITY atual. @return array<int, true> */
    public static function set(Mailbox $mailbox, string $folder, ?int $uidvalidity): array
    {
        if ($uidvalidity === null) {
            return [];
        }

        return array_fill_keys(self::where('mailbox_id', $mailbox->id)->where('folder', $folder)->where('uidvalidity', $uidvalidity)
            ->pluck('uid')->map(fn ($u) => (int) $u)->all(), true);
    }
}
