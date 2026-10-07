<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\Company;
use App\Models\CompanyManagement;

/**
 * O que acontece quando uma relação de gestão termina (pela empresa, pela agência ou pelo
 * root). O acesso da agência já foi cortado (a relação deixou de estar ativa); os DADOS FICAM
 * NA EMPRESA DO CLIENTE.
 *  · Empresa sem admin: fica arquivada 90 dias (desativada) e as ligações da agência
 *    desligam-se.
 *  · Empresa com admin que só tinha acesso pela agência: começa um período de teste de 30 dias.
 *  · Ligações à Meta feitas pela agência: quem termina do lado da empresa escolhe logo (manter
 *    ou desligar); se termina a agência ou o root, o admin do cliente escolhe depois.
 *  · Avisos ao(s) outro(s) lado(s), no sino e por email.
 */
class ManagementEndEffects
{
    public const KEEP = 'keep';
    public const DISCONNECT = 'disconnect';

    public const DECISION_PENDING = 'pending';
    public const DECISION_KEPT = 'kept';
    public const DECISION_DISCONNECTED = 'disconnected';

    public const TRIAL_DAYS = 30;

    public function __construct(
        private readonly AgencyNotifier $notify,
        private readonly CompanyArchiveService $archive,
        private readonly AgencyConnectionsService $connections,
    ) {}

    /** @param bool $hadOwnAccess a empresa tinha acesso próprio antes de terminar (calculado antes) */
    public function apply(CompanyManagement $m, bool $hadOwnAccess, ?string $connectionsChoice): void
    {
        $company = Company::find($m->managed_company_id);
        $agency = Company::find($m->agency_company_id);
        if (! $company) {
            return;
        }
        $hasAdmin = AgencyNotifier::hasAdmin($company->id);
        $made = $agency ? $this->connections->agencyMade($company->id, $agency->id) : [];
        $decision = null;
        $outcome = $hasAdmin ? 'handed_over' : 'archived';

        if (! $hasAdmin) {
            if ($made !== [] && $agency) {
                $this->connections->disconnect($company->id, $agency->id);
                $decision = self::DECISION_DISCONNECTED;
            }
            $this->archive->archive($company);
        } else {
            if (! $hadOwnAccess) {
                $company->initializeTrial(self::TRIAL_DAYS);
                $company->save();
            }
            if ($made !== [] && $agency) {
                if ($m->ended_by_side === CompanyManagement::SIDE_COMPANY) {
                    if ($connectionsChoice === self::DISCONNECT) {
                        $this->connections->disconnect($company->id, $agency->id);
                        $decision = self::DECISION_DISCONNECTED;
                    } else {
                        $decision = self::DECISION_KEPT;
                    }
                } else {
                    $decision = self::DECISION_PENDING;
                }
            }
        }

        $m->update(['data_outcome' => $outcome, 'connections_decision' => $decision,
            'connections_decided_at' => in_array($decision, [self::DECISION_KEPT, self::DECISION_DISCONNECTED], true) ? now() : null]);

        $this->notifySides($m, $company, $agency, $hasAdmin, ! $hadOwnAccess, $decision === self::DECISION_PENDING ? $made : []);
    }

    /** A escolha do admin do cliente sobre as ligações, quando ficou pendente. */
    public function decideConnections(CompanyManagement $m, string $choice): void
    {
        if ($choice === self::DISCONNECT) {
            $this->connections->disconnect($m->managed_company_id, $m->agency_company_id);
        }
        $m->update(['connections_decision' => $choice === self::DISCONNECT ? self::DECISION_DISCONNECTED : self::DECISION_KEPT,
            'connections_decided_at' => now()]);
    }

    private function notifySides(CompanyManagement $m, Company $company, ?Company $agency, bool $hasAdmin, bool $trial, array $pendingConnections): void
    {
        $companyName = AgencyNotifier::name($company);
        $agencyName = AgencyNotifier::name($agency);
        $reason = $m->end_reason ? "Motivo: {$m->end_reason}" : null;

        if ($agency && $m->ended_by_side !== CompanyManagement::SIDE_AGENCY) {
            $this->notify->agency($agency, "Relação terminada: {$companyName}", array_values(array_filter([
                $m->ended_by_side === CompanyManagement::SIDE_COMPANY
                    ? "{$companyName} terminou a relação com a sua agência."
                    : "A XPLENDOR terminou a relação da sua agência com {$companyName}.",
                'A sua agência deixou de ter acesso a esta empresa. Os dados ficam na empresa.',
                $reason,
            ])), '/agency', 'warning');
        }

        if ($hasAdmin && $m->ended_by_side !== CompanyManagement::SIDE_COMPANY) {
            $lines = [
                $m->ended_by_side === CompanyManagement::SIDE_AGENCY
                    ? "A agência {$agencyName} terminou a relação com a sua empresa."
                    : "A XPLENDOR terminou a relação da sua empresa com a agência {$agencyName}.",
                'A agência deixou de ter acesso. Os dados continuam na sua empresa.',
            ];
            if ($reason) {
                $lines[] = $reason;
            }
            if ($trial) {
                $lines[] = 'Como o acesso à XPLENDOR vinha da agência, a sua empresa tem agora um período de teste de ' . self::TRIAL_DAYS . ' dias.';
            }
            if ($pendingConnections !== []) {
                $lines[] = 'A agência deixou ligações feitas em nome da sua empresa (' . implode(', ', array_column($pendingConnections, 'label'))
                    . '). Escolha se as quer manter ou desligar, em Perfil da empresa > Agência gestora.';
            }
            $this->notify->companyAdmins($company, 'Relação com a agência terminada', $lines, ManagementRequestService::companyPath($company->id), 'warning');
        }
    }
}
