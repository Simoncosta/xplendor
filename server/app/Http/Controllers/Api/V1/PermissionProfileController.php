<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Access\Access;
use App\Access\Permissions;
use App\Access\ProfileService;
use App\Access\ProfileSuggestions;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ACL (F5): Utilizadores › Perfis. Os perfis da empresa, as sugestões (D13), a atribuição
 * a cada pessoa (D12: sempre um administrador ativo) e o teto da agência gestora (D2).
 * As permissões são verificadas na rota (app/Access/RoutePermissions.php).
 */
class PermissionProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    // GET /companies/{id}/permission-profiles
    public function index(Request $request, int $companyId)
    {
        $company = Company::findOrFail($companyId);
        $list = $this->profiles->profilesFor($company);
        $counts = User::where('company_id', $companyId)->whereNull('deactivated_at')
            ->selectRaw('profile_id, COUNT(*) n')->groupBy('profile_id')->pluck('n', 'profile_id');
        $agencyCounts = User::where('company_id', $companyId)->whereNull('deactivated_at')
            ->selectRaw('agency_profile_id, COUNT(*) n')->groupBy('agency_profile_id')->pluck('n', 'agency_profile_id');
        $management = CompanyManagement::active()->where('managed_company_id', $companyId)->with('agency:id,fiscal_name,trade_name')->first();

        return ApiResponse::success([
            'catalog' => $this->catalog(),
            'allowed' => [
                PermissionProfile::SIDE_CLIENT => ProfileService::allowedFor(PermissionProfile::SIDE_CLIENT),
                PermissionProfile::SIDE_AGENCY => ProfileService::allowedFor(PermissionProfile::SIDE_AGENCY),
                PermissionProfile::SIDE_CEILING => ProfileService::allowedFor(PermissionProfile::SIDE_CEILING),
            ],
            'profiles' => $list->map(fn (PermissionProfile $p) => $this->present($p, $company,
                $p->side === PermissionProfile::SIDE_AGENCY ? (int) ($agencyCounts[$p->id] ?? 0) : (int) ($counts[$p->id] ?? 0)))->values(),
            'users' => User::where('company_id', $companyId)->where('role', '!=', 'root')->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'profile_id', 'agency_profile_id', 'can_approve_content', 'deactivated_at'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_admin' => $u->isAdmin(),
                    'profile_id' => $u->profile_id, 'agency_profile_id' => $u->agency_profile_id, 'approver' => (bool) $u->can_approve_content,
                    'active' => $u->deactivated_at === null])->values(),
            'is_agency' => ProfileService::isAgency($company),
            'management' => $management ? [
                'agency' => $management->agency?->trade_name ?: $management->agency?->fiscal_name,
                'guest_profile_id' => $management->guest_profile_id,
            ] : null,
            'can_manage' => $this->can($companyId, 'utilizadores.configurar', ['sensitive' => true]),
            'can_set_ceiling' => $this->can($companyId, 'empresa.aprovar', ['sensitive' => true]),
        ], 'Perfis.');
    }

    // POST /companies/{id}/permission-profiles
    public function store(Request $request, int $companyId)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'side' => ['required', Rule::in([PermissionProfile::SIDE_CLIENT, PermissionProfile::SIDE_AGENCY, PermissionProfile::SIDE_CEILING])],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::assignable())],
            'from_profile_id' => ['nullable', 'integer'],
        ]);
        $company = Company::findOrFail($companyId);
        $profile = $this->profiles->create($company, $data, $request->user());

        return ApiResponse::success($this->present($profile->fresh('permissions'), $company, 0), 'Perfil criado.');
    }

    // PUT /companies/{id}/permission-profiles/{profileId}
    public function update(Request $request, int $companyId, int $profileId)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::assignable())],
        ]);
        $company = Company::findOrFail($companyId);
        $profile = $this->profiles->update($company, PermissionProfile::findOrFail($profileId), $data, $request->user());

        return ApiResponse::success($this->present($profile, $company, User::where('profile_id', $profile->id)->count()), 'Perfil guardado.');
    }

    // DELETE /companies/{id}/permission-profiles/{profileId}
    public function destroy(int $companyId, int $profileId)
    {
        $this->profiles->delete(Company::findOrFail($companyId), PermissionProfile::findOrFail($profileId));

        return ApiResponse::success(null, 'Perfil apagado.');
    }

    /**
     * D13: antes de gravar, o que o perfil dá (por área, em linguagem simples), o que se
     * ignora por ser deste lado, e as áreas sem efeito por o módulo não estar ativo.
     * POST /companies/{id}/permission-profiles/preview   Body: { side, permissions[] }
     */
    public function preview(Request $request, int $companyId, CompanyModuleService $modules)
    {
        $data = $request->validate([
            'side' => ['required', Rule::in([PermissionProfile::SIDE_CLIENT, PermissionProfile::SIDE_AGENCY, PermissionProfile::SIDE_CEILING])],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);
        $allowed = ProfileService::allowedFor($data['side']);
        $kept = array_values(array_intersect($data['permissions'], $allowed));
        $ignored = array_values(array_diff($data['permissions'], $allowed));
        $inactive = [];
        foreach (array_unique(array_map(fn ($p) => Permissions::area($p), $kept)) as $area) {
            $module = Permissions::AREAS[$area]['module'] ?? null;
            if ($module && ! $modules->isEnabled($companyId, $module)) {
                $inactive[] = Permissions::areaLabel($area);
            }
        }

        return ApiResponse::success([
            'summary' => ProfileSuggestions::describe($kept, $allowed),
            'effective' => $kept,
            'ignored' => array_map(fn ($p) => Permissions::phrase($p), $ignored),
            'inactive_modules' => $inactive,
            'note' => match ($data['side']) {
                PermissionProfile::SIDE_CEILING => 'Cada pessoa da agência fica com o que o seu perfil na agência permite, dentro deste teto. A agência nunca aprova conteúdos nem decide orçamentos.',
                PermissionProfile::SIDE_AGENCY => 'Dentro de cada cliente, a pessoa fica com este perfil, limitado pelo teto que o cliente deu à agência.',
                default => null,
            },
        ], 'Pré-visualização.');
    }

    // PUT /companies/{id}/users/{user}/profile   Body: { profile_id }
    public function assign(Request $request, int $companyId, int $userId)
    {
        $data = $request->validate(['profile_id' => ['required', 'integer']]);
        $company = Company::findOrFail($companyId);
        $target = User::where('company_id', $companyId)->findOrFail($userId);
        $user = $this->profiles->assign($company, $target, PermissionProfile::findOrFail($data['profile_id']), $request->user());

        return ApiResponse::success(['id' => $user->id, 'profile_id' => $user->profile_id, 'agency_profile_id' => $user->agency_profile_id, 'is_admin' => $user->isAdmin()], 'Perfil atribuído.');
    }

    // PUT /companies/{id}/management/guest-profile   Body: { profile_id }
    public function setCeiling(Request $request, int $companyId)
    {
        $data = $request->validate(['profile_id' => ['required', 'integer']]);
        $m = $this->profiles->setCeiling(Company::findOrFail($companyId), PermissionProfile::findOrFail($data['profile_id']));

        return ApiResponse::success(['guest_profile_id' => $m->guest_profile_id], 'Teto da agência guardado.');
    }

    private function catalog(): array
    {
        $out = [];
        foreach (Permissions::AREAS as $area => $def) {
            if (in_array($area, Permissions::NOT_ASSIGNABLE, true)) {
                continue;
            }
            $out[] = ['area' => $area, 'label' => $def['label'], 'module' => $def['module'], 'actions' => $def['actions']];
        }

        return $out;
    }

    private function present(PermissionProfile $p, Company $company, int $users): array
    {
        $permissions = $p->permissions->map(fn ($x) => "{$x->area}.{$x->action}")->sort()->values()->all();

        return [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'side' => $p->side,
            'is_system' => $p->is_system, 'is_suggestion' => $p->is_suggestion, 'is_admin' => $p->system_key === PermissionProfile::ADMIN,
            'editable' => ! $p->is_system && (int) $p->company_id === (int) $company->id,
            'assignable' => $this->profiles->usable($company, $p) && $p->side !== PermissionProfile::SIDE_CEILING,
            'only_assigned_clients' => (bool) $p->only_assigned_clients,
            'users' => $users, 'permissions' => $permissions, 'summary' => ProfileSuggestions::describe($permissions, ProfileService::allowedFor($p->side)),
        ];
    }
}
