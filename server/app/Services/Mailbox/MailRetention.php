<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Models\MailMessage;
use App\Settings\Settings;
use Illuminate\Support\Facades\Log;

/**
 * E-mail 8 · A retenção das mensagens guardadas na XPLENDOR (mail_messages: as ligadas a um cliente,
 * a uma venda ou a uma lead, e as enviadas pela XPLENDOR). Cada empresa tem o seu prazo (12 meses por
 * omissão, de 3 a 24, em Perfil da empresa › Correio). A purga corre de noite, é interna (não liga a
 * nenhum servidor de e-mail: a mensagem no servidor da pessoa não é tocada) e regista só quantas
 * apagou por empresa, sem conteúdos. A data de cada mensagem é a do envio ou da receção (ou, sem ela,
 * a de quando foi guardada).
 */
class MailRetention
{
    public const SETTING = 'mail.retention_months';

    public static function months(int $companyId): int
    {
        return (int) Settings::company($companyId, self::SETTING);
    }

    /** @return array<int, int> quantas apagou por empresa (só as empresas com mensagens apagadas) */
    public function purge(): array
    {
        $counts = [];
        $companies = MailMessage::query()->distinct()->pluck('company_id');
        foreach ($companies as $companyId) {
            $deleted = $this->purgeCompany((int) $companyId);
            if ($deleted > 0) {
                $counts[(int) $companyId] = $deleted;
                Log::info('[Caixa de correio] Retenção: mensagens guardadas apagadas', ['company_id' => (int) $companyId, 'deleted' => $deleted, 'months' => self::months((int) $companyId)]);
            }
        }

        return $counts;
    }

    public function purgeCompany(int $companyId): int
    {
        $cutoff = now()->subMonthsNoOverflow(self::months($companyId));
        $total = 0;
        do {
            $ids = MailMessage::where('company_id', $companyId)
                ->whereRaw('COALESCE(sent_at, received_at, created_at) < ?', [$cutoff])
                ->limit(500)->pluck('id');
            if ($ids->isNotEmpty()) {
                $total += MailMessage::whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === 500);

        return $total;
    }
}
