<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Mail\AgencyNoticeMail;
use App\Models\Company;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Billing\ChargeService;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos da gestão por agências, no sino e por email, para o lado certo:
 *  · os admins de uma empresa (avisos só da própria empresa: a agência que a gere não os vê);
 *  · os admins de uma agência e o email de avisos dela;
 *  · os roots (sino da equipa da plataforma e email).
 */
class AgencyNotifier
{
    public function __construct(private readonly AlertService $alerts) {}

    /** @param string[] $lines */
    public function companyAdmins(Company $company, string $title, array $lines, string $path, string $type = 'opportunity'): void
    {
        $this->alerts->createSystemAlert($company->id, $type, $title, mb_substr(implode(' ', $lines), 0, 900), 'medium', $path, ownOnly: true);
        $emails = self::adminEmails($company->id);
        if ($emails !== []) {
            Mail::to($emails)->queue(new AgencyNoticeMail($title, $title, $lines, 'Abrir a XPLENDOR', self::url($path)));
        }
    }

    /** @param string[] $lines */
    public function agency(Company $agency, string $title, array $lines, string $path, string $type = 'opportunity'): void
    {
        $this->alerts->createSystemAlert($agency->id, $type, $title, mb_substr(implode(' ', $lines), 0, 900), 'medium', $path);
        $emails = self::agencyAdminEmails($agency->id);
        if ($agency->agency_notification_email) {
            $emails[] = mb_strtolower($agency->agency_notification_email);
        }
        $emails = array_values(array_unique($emails));
        if ($emails !== []) {
            Mail::to($emails)->queue(new AgencyNoticeMail($title, $title, $lines, 'Abrir a XPLENDOR', self::url($path)));
        }
    }

    /** @param string[] $lines */
    public function roots(string $title, array $lines, string $path): void
    {
        if ($team = ChargeService::teamCompanyId()) {
            $this->alerts->createSystemAlert($team, 'opportunity', $title, mb_substr(implode(' ', $lines), 0, 900), 'medium', $path);
        }
        $roots = User::where('role', 'root')->whereNull('deactivated_at')->whereNotNull('email')->pluck('email')->unique()->values()->all();
        if ($roots !== []) {
            Mail::to($roots)->queue(new AgencyNoticeMail($title, $title, $lines, 'Abrir a XPLENDOR', self::url($path)));
        }
    }

    /** @param string[] $lines */
    public function email(string $to, string $title, array $lines): void
    {
        Mail::to($to)->queue(new AgencyNoticeMail($title, $title, $lines));
    }

    /**
     * Quem recebe os avisos e resumos de um cliente gerido: as pessoas atribuídas ao cliente
     * (quando o cliente está limitado a pessoas escolhidas) ou os admins da agência, e o email
     * de avisos da agência.
     *
     * @return string[]
     */
    public static function recipientsFor(\App\Models\CompanyManagement $m): array
    {
        $agency = Company::find($m->agency_company_id);
        $emails = $m->team_scope === \App\Models\CompanyManagement::SCOPE_ASSIGNED
            ? User::whereIn('id', $m->members()->pluck('user_id'))->where('company_id', $m->agency_company_id)->whereNull('deactivated_at')
                ->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all()
            : self::agencyAdminEmails($m->agency_company_id);
        if ($emails === []) {
            $emails = self::agencyAdminEmails($m->agency_company_id);
        }
        if ($agency?->agency_notification_email) {
            $emails[] = mb_strtolower($agency->agency_notification_email);
        }

        return array_values(array_unique($emails));
    }

    /**
     * Os admins de uma AGÊNCIA para os avisos: os admins e os roots que pertencem à empresa da
     * agência (ex.: a XPLENDOR a gerir os seus clientes; os roots dela recebem os avisos).
     *
     * @return string[]
     */
    public static function agencyAdminEmails(int $agencyId): array
    {
        return User::where('company_id', $agencyId)->whereIn('role', ['admin', 'root'])->whereNull('deactivated_at')->whereNotNull('email')
            ->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->unique()->values()->all();
    }

    /** @return string[] */
    public static function adminEmails(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('role', 'admin')->whereNull('deactivated_at')->whereNotNull('email')
            ->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->unique()->values()->all();
    }

    public static function hasAdmin(int $companyId): bool
    {
        return User::where('company_id', $companyId)->where('role', 'admin')->whereNull('deactivated_at')->exists();
    }

    public static function name(?Company $c): string
    {
        return $c ? (string) ($c->trade_name ?: $c->fiscal_name) : '';
    }

    public static function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }
}
