<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\ManagedCompanyRequest;
use Illuminate\Support\Facades\Log;

/**
 * Arquivo de uma empresa SEM admin que perde a agência: fica desativada 90 dias (os dados
 * ficam guardados), com aviso ao contacto no início e 7 dias antes do fim, e é apagada no
 * fim (job diário). Se entretanto ganhar um admin ou outra agência, sai do arquivo.
 */
class CompanyArchiveService
{
    public const DAYS = 90;
    public const WARN_DAYS_BEFORE = 7;

    public function __construct(private readonly AgencyNotifier $notify) {}

    public function archive(Company $company): void
    {
        $deleteAt = now()->addDays(self::DAYS);
        $company->forceFill(['archived_at' => now(), 'archive_delete_at' => $deleteAt, 'archive_warned_at' => null])->save();

        if ($to = self::contactEmail($company)) {
            $name = AgencyNotifier::name($company);
            $this->notify->email($to, "A empresa {$name} foi arquivada na XPLENDOR", [
                "A agência que geria a empresa {$name} deixou de a gerir, e a empresa não tem nenhum administrador na XPLENDOR.",
                'Por isso, a empresa ficou arquivada: os dados estão guardados, mas ninguém lhe acede.',
                'Se nada mudar, a empresa e os dados dela são apagados a ' . $deleteAt->format('d/m/Y') . '.',
                'Para a manter, contacte a XPLENDOR (ou uma agência) antes dessa data.',
            ]);
        }
    }

    public function release(Company $company): void
    {
        if ($company->archived_at !== null) {
            $company->forceFill(['archived_at' => null, 'archive_delete_at' => null, 'archive_warned_at' => null])->save();
        }
    }

    /** Job diário: sai do arquivo quem ganhou admin ou agência; aviso 7 dias antes; apaga no fim. @return array{released: int, warned: int, deleted: int} */
    public function runDaily(): array
    {
        $out = ['released' => 0, 'warned' => 0, 'deleted' => 0];
        foreach (Company::whereNotNull('archived_at')->get() as $company) {
            if (AgencyNotifier::hasAdmin($company->id) || CompanyManagement::active()->where('managed_company_id', $company->id)->exists()) {
                $this->release($company);
                $out['released']++;
                continue;
            }
            if ($company->archive_delete_at && $company->archive_delete_at->lte(now())) {
                Log::info('[Arquivo] Empresa apagada ao fim de 90 dias sem admin nem agência.', ['company_id' => $company->id]);
                $company->delete();
                $out['deleted']++;
                continue;
            }
            if ($company->archive_warned_at === null && $company->archive_delete_at && $company->archive_delete_at->lte(now()->addDays(self::WARN_DAYS_BEFORE))) {
                if ($to = self::contactEmail($company)) {
                    $name = AgencyNotifier::name($company);
                    $this->notify->email($to, "A empresa {$name} vai ser apagada da XPLENDOR", [
                        "A empresa {$name} está arquivada desde " . $company->archived_at->format('d/m/Y') . ', sem administrador nem agência.',
                        'A empresa e os dados dela são apagados a ' . $company->archive_delete_at->format('d/m/Y') . '.',
                        'Para a manter, contacte a XPLENDOR (ou uma agência) antes dessa data.',
                    ]);
                }
                $company->forceFill(['archive_warned_at' => now()])->save();
                $out['warned']++;
            }
        }

        return $out;
    }

    /** O contacto da empresa: o email dela, o de faturação, ou o contacto do pedido que a criou. */
    public static function contactEmail(Company $company): ?string
    {
        $email = $company->email ?: $company->invoice_email
            ?: ManagedCompanyRequest::where('company_id', $company->id)->value('contact_email');

        return $email ? mb_strtolower((string) $email) : null;
    }
}
