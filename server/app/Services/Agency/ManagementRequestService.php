<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\ManagementRequest;
use App\Models\User;
use App\Services\Tenancy\CompanyManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pedido de gestão de uma empresa EXISTENTE.
 *  · Só o admin da agência pede, com o NIPC OU o email de um admin da empresa (nunca uma
 *    pesquisa livre), uma mensagem e a declaração de autorização do cliente.
 *  · A resposta à agência é sempre a mesma: nunca revela se a empresa existe. O pedido fica
 *    registado mesmo sem empresa correspondente (e expira como os outros).
 *  · Os admins da empresa recebem o aviso (sino e email) e aceitam ou recusam NA APP, vendo
 *    o que a agência poderá e não poderá fazer. Recusa com motivo opcional.
 *  · Expira aos 14 dias; a agência pode retirá-lo enquanto pendente.
 *  · Ao aceitar: relação ativa (origin request_accepted); o root recebe o aviso com a
 *    situação de faturação. Se a empresa era gerida por outra agência, essa relação termina.
 */
class ManagementRequestService
{
    /** A mesma resposta para todos os pedidos (a agência nunca sabe se a empresa existe). */
    public const NEUTRAL_REPLY = 'Se existir uma empresa com estes dados, os administradores dela recebem o pedido.';

    /** Pedidos por agência e por dia (impede tentativas em massa). */
    public const DAILY_LIMIT = 30;

    /** O que a agência poderá e não poderá fazer (mostrado a quem decide). */
    public const SCOPE_CAN = [
        'Trabalhar na Linha Editorial, no Blog, no Perfil da Marca e nas outras áreas ativas da sua empresa.',
        'Ver os resultados, as estatísticas e os alertas da sua empresa.',
        'Ligar integrações em nome da sua empresa, como os anúncios da Meta, as redes sociais e o Google Analytics (só os administradores da agência).',
    ];

    public const SCOPE_CANNOT = [
        'Aprovar conteúdos ou aceitar orçamentos em nome da sua empresa.',
        'Gerir os utilizadores e os acessos da sua empresa.',
        'Alterar os dados da sua empresa ou apagá-la.',
        'Ver ou pagar as faturas da XPLENDOR da sua empresa.',
        'Ligar ou desligar módulos (só a XPLENDOR o faz).',
    ];

    public const SCOPE_NOTE = 'Pode terminar a relação a qualquer momento. Os dados ficam sempre na sua empresa.';

    public function __construct(
        private readonly AgencyNotifier $notify,
        private readonly CompanyManagementService $managements,
    ) {}

    public function request(Company $agency, User $admin, array $data): ManagementRequest
    {
        $todayCount = ManagementRequest::where('agency_company_id', $agency->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($todayCount >= self::DAILY_LIMIT) {
            throw ValidationException::withMessages(['identifier' => ['Atingiu o limite de pedidos de gestão de hoje. Tente novamente amanhã.']]);
        }

        [$type, $identifier] = self::normalize($data);
        $target = $this->target($agency, $type, $identifier);

        $request = ManagementRequest::create([
            'agency_company_id' => $agency->id, 'requested_by_user_id' => $admin->id,
            'identifier_type' => $type, 'identifier' => $identifier, 'message' => $data['message'] ?? null,
            'authorization_declared_at' => now(), 'status' => ManagementRequest::PENDING,
            'managed_company_id' => $target?->id, 'expires_at' => now()->addDays(ManagementRequest::EXPIRES_IN_DAYS),
        ]);

        if ($target) {
            $agencyName = AgencyNotifier::name($agency);
            $this->notify->companyAdmins($target, "Pedido de gestão: {$agencyName}", [
                "A agência {$agencyName} pediu para gerir a sua empresa na XPLENDOR.",
                'Aceite ou recuse na app, em Perfil da empresa > Agência gestora. Antes de decidir, veja o que a agência poderá e não poderá fazer.',
                'O pedido expira ao fim de ' . ManagementRequest::EXPIRES_IN_DAYS . ' dias.',
            ], self::companyPath($target->id));
        }

        return $request;
    }

    public function withdraw(ManagementRequest $request): ManagementRequest
    {
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['request' => ['Este pedido já não está pendente.']]);
        }
        $request->update(['status' => ManagementRequest::WITHDRAWN, 'withdrawn_at' => now()]);

        return $request->fresh();
    }

    public function accept(ManagementRequest $request, Company $company, User $admin): CompanyManagement
    {
        $this->assertOpenFor($request, $company);
        $agency = $request->agency;
        if (! $agency || ! $agency->isAgency()) {
            throw ValidationException::withMessages(['request' => ['A empresa que pediu já não é uma agência.']]);
        }
        $previous = $company->activeManagement()->with('agency')->first();
        if ($previous && (int) $previous->agency_company_id === $agency->id) {
            throw ValidationException::withMessages(['request' => ['Esta agência já gere a sua empresa.']]);
        }

        $management = DB::transaction(function () use ($request, $company, $admin, $agency, $previous) {
            if ($previous) {
                $this->managements->closeForSwitch($previous, $admin, CompanyManagement::SIDE_COMPANY,
                    'A empresa aceitou a gestão pela agência ' . AgencyNotifier::name($agency) . '.');
            }
            $m = CompanyManagement::create([
                'agency_company_id' => $agency->id, 'managed_company_id' => $company->id,
                'origin' => CompanyManagement::ORIGIN_REQUEST_ACCEPTED, 'status' => CompanyManagement::ACTIVE, 'active_key' => $company->id,
                'team_scope' => CompanyManagement::SCOPE_ALL,
                'requested_by_user_id' => $request->requested_by_user_id, 'requested_at' => $request->created_at,
                'request_message' => $request->message, 'agency_authorization_declared_at' => $request->authorization_declared_at,
                'responded_by_user_id' => $admin->id, 'responded_at' => now(),
            ]);
            $request->update(['status' => ManagementRequest::ACCEPTED, 'responded_by_user_id' => $admin->id, 'responded_at' => now(), 'management_id' => $m->id]);
            app(CompanyArchiveService::class)->release($company);

            return $m;
        });

        $companyName = AgencyNotifier::name($company);
        $agencyName = AgencyNotifier::name($agency);
        $this->notify->agency($agency, "Pedido de gestão aceite: {$companyName}", [
            "{$companyName} aceitou que a agência a gira. Já pode trabalhar nela no seletor \"A trabalhar em\".",
        ], '/agency?tab=gestao');
        if ($previous?->agency) {
            $this->notify->agency($previous->agency, "Relação terminada: {$companyName}", [
                "{$companyName} passou a ser gerida por outra agência. A sua agência deixou de ter acesso a esta empresa; os dados ficam na empresa.",
            ], '/agency', 'warning');
        }
        $billing = AgencyBilling::situation($company, now());
        $this->notify->roots("Nova empresa gerida por pedido: {$companyName}", [
            "{$companyName} aceitou a gestão pela agência {$agencyName}.",
            $billing['label'],
        ], '/companies?gestao=1');

        return $management;
    }

    public function decline(ManagementRequest $request, Company $company, User $admin, ?string $reason): ManagementRequest
    {
        $this->assertOpenFor($request, $company);
        $request->update(['status' => ManagementRequest::DECLINED, 'responded_by_user_id' => $admin->id, 'responded_at' => now(),
            'decline_reason' => $reason ? mb_substr($reason, 0, 500) : null]);

        if ($agency = $request->agency) {
            $lines = ['O pedido de gestão para ' . self::identifierLabel($request) . ' foi recusado pela empresa.'];
            if ($reason) {
                $lines[] = "Motivo: {$reason}";
            }
            $this->notify->agency($agency, 'Pedido de gestão recusado', $lines, '/agency?tab=gestao', 'warning');
        }

        return $request->fresh();
    }

    /** Job: marca como expirados os pedidos pendentes fora do prazo. */
    public function expireDue(): int
    {
        $due = ManagementRequest::where('status', ManagementRequest::PENDING)->where('expires_at', '<=', now())->get();
        foreach ($due as $r) {
            $r->update(['status' => ManagementRequest::EXPIRED]);
        }

        return $due->count();
    }

    /** Dias depois do fim (expirado ou retirado) em que se apaga o NIPC ou email de um pedido sem empresa. */
    public const SCRUB_AFTER_DAYS = 30;

    /** Job diário: apaga o NIPC ou email dos pedidos sem empresa correspondente, 30 dias depois do fim. */
    public function scrubUnmatched(): int
    {
        $limit = now()->subDays(self::SCRUB_AFTER_DAYS);

        return ManagementRequest::whereNull('managed_company_id')->whereNotNull('identifier')
            ->where(fn ($q) => $q->where(fn ($e) => $e->where('status', ManagementRequest::EXPIRED)->where('expires_at', '<=', $limit))
                ->orWhere(fn ($w) => $w->where('status', ManagementRequest::WITHDRAWN)->where('withdrawn_at', '<=', $limit)))
            ->update(['identifier' => null, 'identifier_scrubbed_at' => now()]);
    }

    // ── Apresentação ─────────────────────────────────────────────────────────

    /** Para a agência: nunca diz se a empresa existe (só o nome depois de aceite). */
    public static function presentForAgency(ManagementRequest $r): array
    {
        $r->loadMissing(['requester:id,name', 'managed:id,fiscal_name,trade_name']);
        $status = $r->status === ManagementRequest::PENDING && ! $r->isOpen() ? ManagementRequest::EXPIRED : $r->status;

        return [
            'id' => $r->id, 'identifier_type' => $r->identifier_type, 'identifier' => $r->identifier,
            'identifier_scrubbed' => $r->identifier_scrubbed_at !== null, 'message' => $r->message,
            'status' => $status, 'requested_by' => $r->requester?->name, 'requested_at' => optional($r->created_at)->toIso8601String(),
            'expires_at' => optional($r->expires_at)->toIso8601String(), 'responded_at' => optional($r->responded_at)->toIso8601String(),
            'decline_reason' => $status === ManagementRequest::DECLINED ? $r->decline_reason : null,
            'company' => $status === ManagementRequest::ACCEPTED && $r->managed ? ['id' => $r->managed->id, 'name' => AgencyNotifier::name($r->managed)] : null,
        ];
    }

    /** Para os admins da empresa: quem pede, a mensagem e o âmbito explicado. */
    public static function presentForCompany(ManagementRequest $r): array
    {
        $r->loadMissing(['requester:id,name', 'agency:id,fiscal_name,trade_name']);

        return [
            'id' => $r->id, 'agency' => ['id' => $r->agency_company_id, 'name' => AgencyNotifier::name($r->agency)],
            'requested_by' => $r->requester?->name, 'message' => $r->message,
            'requested_at' => optional($r->created_at)->toIso8601String(), 'expires_at' => optional($r->expires_at)->toIso8601String(),
            'scope' => ['can' => self::SCOPE_CAN, 'cannot' => self::SCOPE_CANNOT, 'note' => self::SCOPE_NOTE],
        ];
    }

    public static function companyPath(int $companyId): string
    {
        return "/companies/{$companyId}?gestao=1";
    }

    // ── Regras ───────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} */
    private static function normalize(array $data): array
    {
        if (! empty($data['nipc'])) {
            return [ManagementRequest::BY_NIPC, preg_replace('/\D/', '', (string) $data['nipc'])];
        }

        return [ManagementRequest::BY_EMAIL, mb_strtolower(trim((string) $data['email']))];
    }

    /** A empresa que pode receber o pedido, ou null (sem revelar o motivo a ninguém). */
    private function target(Company $agency, string $type, string $identifier): ?Company
    {
        $company = $type === ManagementRequest::BY_NIPC
            ? Company::where('nipc', $identifier)->first()
            : Company::find(User::whereRaw('LOWER(email) = ?', [$identifier])->where('role', 'admin')->whereNull('deactivated_at')->value('company_id'));

        if (! $company || $company->id === $agency->id || $company->isAgency() || ! AgencyNotifier::hasAdmin($company->id)) {
            return null;
        }
        if (CompanyManagement::active()->where('managed_company_id', $company->id)->where('agency_company_id', $agency->id)->exists()) {
            return null;
        }
        $duplicate = ManagementRequest::where('agency_company_id', $agency->id)->where('managed_company_id', $company->id)
            ->where('status', ManagementRequest::PENDING)->where('expires_at', '>', now())->exists();

        return $duplicate ? null : $company;
    }

    private function assertOpenFor(ManagementRequest $request, Company $company): void
    {
        if ((int) $request->managed_company_id !== $company->id) {
            abort(404, 'Pedido não encontrado.');
        }
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['request' => ['Este pedido já não está pendente.']]);
        }
    }

    private static function identifierLabel(ManagementRequest $r): string
    {
        return $r->identifier_type === ManagementRequest::BY_NIPC ? "o NIPC {$r->identifier}" : "o email {$r->identifier}";
    }
}
