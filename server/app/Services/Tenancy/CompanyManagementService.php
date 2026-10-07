<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanyModuleEvent;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestão de empresas por agências, na plataforma (sem comandos):
 *  · o root marca ou desmarca uma empresa como agência;
 *  · o root define, muda ou retira a agência gestora (também ao criar a empresa);
 *  · o admin da empresa gerida termina a relação (acesso cortado de imediato; os dados
 *    ficam na empresa).
 * Mudar de agência termina a relação atual e cria outra: as linhas são o histórico.
 */
class CompanyManagementService
{
    public function __construct(private readonly CompanyModuleService $modules) {}

    /**
     * Marca ou desmarca a agência. Desmarcar com clientes ativos é recusado. Marcar liga a
     * Linha Editorial (a vista de todos os clientes vive lá), com registo no histórico.
     */
    public function setAgency(Company $company, bool $enabled, ?string $notificationEmail, ?User $actor = null): Company
    {
        if ($enabled && $company->activeManagement()->exists()) {
            throw ValidationException::withMessages(['enabled' => ['Uma empresa gerida por uma agência não pode ser ela própria uma agência.']]);
        }
        if (! $enabled && ($n = CompanyManagement::active()->where('agency_company_id', $company->id)->count()) > 0) {
            throw ValidationException::withMessages(['enabled' => ["Esta agência gere {$n} " . ($n === 1 ? 'empresa' : 'empresas') . '. Retire primeiro a gestão.']]);
        }
        $company->forceFill([
            'agency_enabled_at' => $enabled ? ($company->agency_enabled_at ?? now()) : null,
            'agency_notification_email' => $enabled ? $notificationEmail : null,
        ])->save();
        if ($enabled) {
            $this->modules->enable($company->id, 'linha_editorial', CompanyModuleEvent::SOURCE_AGENCY, $actor?->id, 'Ativado ao marcar a empresa como agência.');
        }

        return $company;
    }

    /**
     * Define (ou muda, ou retira com null) a agência gestora, pela plataforma.
     * Devolve a relação ativa resultante (ou null).
     */
    public function assign(Company $company, ?int $agencyId, User $actor, string $teamScope = CompanyManagement::SCOPE_ALL, array $memberIds = []): ?CompanyManagement
    {
        return DB::transaction(function () use ($company, $agencyId, $actor, $teamScope, $memberIds) {
            $current = CompanyManagement::active()->where('managed_company_id', $company->id)->lockForUpdate()->first();

            if ($agencyId === null) {
                if ($current) {
                    $this->end($current, $actor, CompanyManagement::SIDE_PLATFORM, 'Gestão retirada pela plataforma.');
                }

                return null;
            }

            $agency = Company::find($agencyId);
            if (! $agency || ! $agency->isAgency()) {
                throw ValidationException::withMessages(['agency_company_id' => ['Escolha uma empresa marcada como agência.']]);
            }
            if ($agency->id === $company->id) {
                throw ValidationException::withMessages(['agency_company_id' => ['Uma empresa não se gere a si própria.']]);
            }
            if ($company->isAgency()) {
                throw ValidationException::withMessages(['agency_company_id' => ['Uma agência não pode ser gerida por outra agência.']]);
            }

            if ($current && (int) $current->agency_company_id === $agency->id) {
                $current->update(['team_scope' => $teamScope]);
                $this->syncMembers($current, $memberIds, $actor);

                return $current->fresh();
            }
            if ($current) {
                $this->end($current, $actor, CompanyManagement::SIDE_PLATFORM, 'Agência gestora mudada pela plataforma.');
            }

            $m = CompanyManagement::create([
                'agency_company_id' => $agency->id, 'managed_company_id' => $company->id,
                'origin' => CompanyManagement::ORIGIN_PLATFORM, 'status' => CompanyManagement::ACTIVE,
                'active_key' => $company->id, 'team_scope' => $teamScope,
                'requested_by_user_id' => $actor->id, 'requested_at' => now(),
                'responded_by_user_id' => $actor->id, 'responded_at' => now(),
            ]);
            $this->syncMembers($m, $memberIds, $actor);

            return $m;
        });
    }

    /** O admin da empresa gerida termina a relação. */
    public function endByCompany(Company $company, User $actor, ?string $reason): void
    {
        DB::transaction(function () use ($company, $actor, $reason) {
            $m = CompanyManagement::active()->where('managed_company_id', $company->id)->lockForUpdate()->first();
            if (! $m) {
                throw ValidationException::withMessages(['management' => ['Esta empresa não tem agência gestora.']]);
            }
            $this->end($m, $actor, CompanyManagement::SIDE_COMPANY, $reason ?: 'Relação terminada pela empresa.');
        });
    }

    /** Histórico das relações da empresa (mais recente primeiro), para os ecrãs. */
    public function history(Company $company): array
    {
        return $company->managements()->with(['agency:id,fiscal_name,trade_name'])->orderByDesc('id')->get()
            ->map(fn (CompanyManagement $m) => $this->present($m))->all();
    }

    public function present(CompanyManagement $m): array
    {
        $names = User::whereIn('id', array_filter([$m->requested_by_user_id, $m->ended_by_user_id]))->pluck('name', 'id');

        return [
            'id' => $m->id,
            'agency' => ['id' => $m->agency_company_id, 'name' => $m->agency?->trade_name ?: $m->agency?->fiscal_name],
            'origin' => $m->origin, 'status' => $m->status, 'team_scope' => $m->team_scope,
            'member_ids' => $m->members()->pluck('user_id')->all(),
            'started_at' => optional($m->responded_at ?? $m->requested_at)->toIso8601String(),
            'started_by' => $names[$m->requested_by_user_id] ?? null,
            'ended_at' => optional($m->ended_at)->toIso8601String(),
            'ended_by' => $names[$m->ended_by_user_id] ?? null,
            'ended_by_side' => $m->ended_by_side, 'end_reason' => $m->end_reason,
        ];
    }

    private function end(CompanyManagement $m, User $actor, string $side, string $reason): void
    {
        $m->update([
            'status' => CompanyManagement::ENDED, 'active_key' => null,
            'ended_by_user_id' => $actor->id, 'ended_by_side' => $side, 'ended_at' => now(),
            'end_reason' => mb_substr($reason, 0, 500),
        ]);
    }

    public function syncMembers(CompanyManagement $m, array $memberIds, User $actor): void
    {
        $valid = User::where('company_id', $m->agency_company_id)->whereIn('id', $memberIds)->pluck('id')->all();
        $m->members()->whereNotIn('user_id', $valid)->delete();
        foreach ($valid as $id) {
            $m->members()->firstOrCreate(['user_id' => $id], ['assigned_by_user_id' => $actor->id, 'assigned_at' => now()]);
        }
    }
}
