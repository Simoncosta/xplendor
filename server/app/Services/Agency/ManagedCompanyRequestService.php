<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Mail\ManagedCompanyRequestMail;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\ManagedCompanyRequest;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Billing\ChargeService;
use App\Services\CompanyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Nova empresa gerida": a agência NÃO cria empresas. O admin da agência pede (nome, ramo,
 * contacto opcional, nota, com a declaração de autorização do cliente); o root aprova (a
 * empresa nasce gerida pela agência, origin 'created_by_agency', módulos pelo ramo, sem
 * período de teste próprio) ou recusa com motivo. Avisos no sino e por email.
 */
class ManagedCompanyRequestService
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly CompanyService $companies,
    ) {}

    public function request(Company $agency, User $admin, array $data): ManagedCompanyRequest
    {
        $request = ManagedCompanyRequest::create([
            'agency_company_id' => $agency->id, 'requested_by_user_id' => $admin->id, 'name' => trim($data['name']),
            'content_sector_id' => $data['content_sector_id'] ?? null, 'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null,
            'note' => $data['note'] ?? null, 'authorization_declared_at' => now(), 'status' => ManagedCompanyRequest::PENDING,
        ]);

        $agencyName = self::name($agency);
        if ($team = ChargeService::teamCompanyId()) {
            $this->alerts->createSystemAlert($team, 'opportunity', "Pedido de nova empresa gerida: {$request->name}",
                "{$agencyName} pediu uma nova empresa gerida: {$request->name}. Aprove ou recuse em Empresas.", 'medium', '/companies?pedidos=1');
        }
        $roots = User::where('role', 'root')->whereNull('deactivated_at')->whereNotNull('email')->pluck('email')->unique()->values()->all();
        if ($roots !== []) {
            Mail::to($roots)->queue(new ManagedCompanyRequestMail('new', $agencyName, $request->name, $request->note, self::url('/companies?pedidos=1')));
        }

        return $request;
    }

    public function approve(ManagedCompanyRequest $request, User $root): ManagedCompanyRequest
    {
        $this->assertPending($request);
        $agency = $request->agency;
        if (! $agency || ! $agency->isAgency()) {
            throw ValidationException::withMessages(['agency' => ['A empresa que pediu já não é uma agência.']]);
        }

        DB::transaction(function () use ($request, $root, $agency) {
            // Os módulos seguem o ramo (CompanyService::store). Sem período de teste próprio:
            // o acesso vem da agência enquanto a relação estiver ativa.
            $company = $this->companies->store([
                'fiscal_name' => $request->name, 'trade_name' => $request->name, 'plan_id' => $agency->plan_id,
                'content_sector_id' => $request->content_sector_id, 'responsible_name' => $request->contact_name,
                'email' => $request->contact_email, 'phone' => $request->contact_phone, 'public_api_token' => Str::uuid()->toString(),
            ]);
            $company->forceFill(['subscription_status' => null, 'trial_starts_at' => null, 'trial_ends_at' => null, 'subscription_ends_at' => null])->save();

            $management = CompanyManagement::create([
                'agency_company_id' => $agency->id, 'managed_company_id' => $company->id,
                'origin' => CompanyManagement::ORIGIN_CREATED_BY_AGENCY, 'status' => CompanyManagement::ACTIVE, 'active_key' => $company->id,
                'requested_by_user_id' => $request->requested_by_user_id, 'requested_at' => $request->created_at, 'request_message' => $request->note,
                'agency_authorization_declared_at' => $request->authorization_declared_at,
                'responded_by_user_id' => $root->id, 'responded_at' => now(),
            ]);
            $request->update(['status' => ManagedCompanyRequest::APPROVED, 'decided_by_user_id' => $root->id, 'decided_at' => now(),
                'company_id' => $company->id, 'management_id' => $management->id]);
        });

        $this->notifyAgency($request->fresh(), 'approved');

        return $request->fresh();
    }

    public function decline(ManagedCompanyRequest $request, User $root, string $reason): ManagedCompanyRequest
    {
        $this->assertPending($request);
        $request->update(['status' => ManagedCompanyRequest::DECLINED, 'decided_by_user_id' => $root->id, 'decided_at' => now(),
            'decline_reason' => mb_substr($reason, 0, 1000)]);
        $this->notifyAgency($request->fresh(), 'declined');

        return $request->fresh();
    }

    public static function present(ManagedCompanyRequest $r): array
    {
        $r->loadMissing(['agency:id,fiscal_name,trade_name', 'requester:id,name', 'decider:id,name', 'sector:id,name']);

        return [
            'id' => $r->id, 'status' => $r->status, 'name' => $r->name,
            'agency' => ['id' => $r->agency_company_id, 'name' => $r->agency ? self::name($r->agency) : null],
            'sector' => $r->sector ? ['id' => $r->sector->id, 'name' => $r->sector->name] : null,
            'contact_name' => $r->contact_name, 'contact_email' => $r->contact_email, 'contact_phone' => $r->contact_phone, 'note' => $r->note,
            'requested_by' => $r->requester?->name, 'requested_at' => optional($r->created_at)->toIso8601String(),
            'decided_by' => $r->decider?->name, 'decided_at' => optional($r->decided_at)->toIso8601String(),
            'decline_reason' => $r->decline_reason, 'company_id' => $r->company_id,
        ];
    }

    private function notifyAgency(ManagedCompanyRequest $request, string $kind): void
    {
        $agency = $request->agency;
        $title = $kind === 'approved' ? "Empresa gerida aprovada: {$request->name}" : "Pedido de empresa gerida recusado: {$request->name}";
        $message = $kind === 'approved'
            ? "{$request->name} foi criada e já é gerida pela agência. Escolha-a no seletor \"A trabalhar em\"."
            : "O pedido não foi aprovado. Motivo: {$request->decline_reason}";
        $this->alerts->createSystemAlert($agency->id, $kind === 'approved' ? 'opportunity' : 'warning', $title, mb_substr($message, 0, 900), 'medium', '/agency?tab=pedidos');
        if ($email = $request->requester?->email) {
            Mail::to($email)->queue(new ManagedCompanyRequestMail($kind, self::name($agency), $request->name,
                $kind === 'declined' ? $request->decline_reason : null, self::url('/agency?tab=pedidos')));
        }
    }

    private function assertPending(ManagedCompanyRequest $request): void
    {
        if ($request->status !== ManagedCompanyRequest::PENDING) {
            throw ValidationException::withMessages(['status' => ['Este pedido já foi decidido.']]);
        }
    }

    private static function name(Company $c): string
    {
        return (string) ($c->trade_name ?: $c->fiscal_name);
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }
}
