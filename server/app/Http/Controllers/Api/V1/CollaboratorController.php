<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\CollaboratorResource;
use App\Models\Collaborator;
use App\Services\CollaboratorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Colaboradores de uma empresa (/companies/{id}/collaborators; middleware tenant).
 * Ler: qualquer utilizador da empresa (também em impersonation). Conteúdo: admin da
 * empresa ou impersonation. Acessos à plataforma: só o admin da própria empresa, fora
 * de impersonation (as rotas também têm block_when_impersonating).
 */
class CollaboratorController extends Controller
{
    public function __construct(private readonly CollaboratorService $service) {}

    private function find(int $companyId, int $id): Collaborator
    {
        $c = Collaborator::where('company_id', $companyId)->with(['department', 'user', 'pendingInvite'])->find($id);
        abort_unless($c, 404, 'Colaborador não encontrado.');

        return $c;
    }

    private function ensureContent(Request $request, int $companyId): void
    {
        abort_unless(CollaboratorService::canEditContent($request->user(), $companyId), 403, 'Só o administrador da empresa pode alterar os colaboradores.');
    }

    private function ensureAccess(Request $request, int $companyId): void
    {
        abort_unless(CollaboratorService::canManageAccess($request->user(), $companyId), 403, 'Só o administrador da própria empresa pode gerir acessos à plataforma (indisponível em sessão como cliente).');
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $phone = ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s.-]+$/'];

        return [
            'name'                     => [$req, 'string', 'max:255'],
            'role_title'               => ['nullable', 'string', 'max:120'],
            'bio'                      => ['nullable', 'string', 'max:600'],
            'department_id'            => ['nullable', 'integer'],
            'whatsapp'                 => $phone,
            'phone'                    => $phone,
            'phone_type'               => ['nullable', Rule::in(Collaborator::PHONE_TYPES), 'required_with:phone'],
            'email'                    => ['nullable', 'email', 'max:255'],
            'contact_mode'             => ['sometimes', Rule::in(Collaborator::CONTACT_MODES)],
            'show_on_site'             => ['sometimes', 'boolean'],
            'publish_consent'          => ['sometimes', 'boolean'],
            'personal_contact_consent' => ['sometimes', 'boolean'],
            'sort'                     => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function index(Request $request, int $companyId)
    {
        $q = Collaborator::where('company_id', $companyId)->with(['department', 'user', 'pendingInvite']);
        if ($request->filled('department_id')) {
            $q->where('department_id', (int) $request->input('department_id'));
        }
        if ($request->input('status') === 'active') {
            $q->where('active', true);
        } elseif ($request->input('status') === 'inactive') {
            $q->where('active', false);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('role_title', 'like', "%{$search}%"));
        }

        return ApiResponse::success(CollaboratorResource::collection($q->orderByDesc('active')->orderBy('sort')->orderBy('name')->get())->resolve(), 'Collaborators fetched successfully.');
    }

    public function show(int $companyId, int $id)
    {
        return ApiResponse::success((new CollaboratorResource($this->find($companyId, $id)))->resolve(), 'Collaborator fetched successfully.');
    }

    /** Cria o colaborador; com create_access, convida-o também (só admin, fora de impersonation). */
    public function store(Request $request, int $companyId)
    {
        $this->ensureContent($request, $companyId);
        $data = $request->validate($this->rules(true) + [
            'create_access' => ['sometimes', 'boolean'],
            'access_email'  => ['required_if:create_access,true,1', 'nullable', 'email', 'max:255'],
        ]);
        if (! empty($data['create_access'])) {
            $this->ensureAccess($request, $companyId);   // antes de gravar o que quer que seja
        }

        $c = \Illuminate\Support\Facades\DB::transaction(function () use ($companyId, $data, $request) {
            $c = $this->service->save(null, $companyId, $data, $request->user());
            if (! empty($data['create_access'])) {
                $c = $this->service->grantAccess($c, $data['access_email'], $request->user());
            }

            return $c;
        });

        return ApiResponse::success((new CollaboratorResource($c))->resolve(), 'Colaborador criado.', 201);
    }

    public function update(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);
        $c = $this->service->save($this->find($companyId, $id), $companyId, $request->validate($this->rules(false)), $request->user());

        return ApiResponse::success((new CollaboratorResource($c))->resolve(), 'Colaborador guardado.');
    }

    public function storePhoto(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);
        $c = $this->service->storePhoto($this->find($companyId, $id), $request->file('photo'));

        return ApiResponse::success((new CollaboratorResource($c))->resolve(), 'Foto guardada.');
    }

    public function deletePhoto(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);

        return ApiResponse::success((new CollaboratorResource($this->service->deletePhoto($this->find($companyId, $id))))->resolve(), 'Foto removida.');
    }

    public function deactivate(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);

        return ApiResponse::success((new CollaboratorResource($this->service->setActive($this->find($companyId, $id), false)))->resolve(), 'Colaborador desativado: deixa de aparecer no site.');
    }

    public function activate(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);

        return ApiResponse::success((new CollaboratorResource($this->service->setActive($this->find($companyId, $id), true)))->resolve(), 'Colaborador ativado.');
    }

    public function destroy(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);
        $this->service->delete($this->find($companyId, $id));

        return ApiResponse::success(null, 'Colaborador apagado.');
    }

    // ── Acessos à plataforma ─────────────────────────────────────────────────

    public function grantAccess(Request $request, int $companyId, int $id)
    {
        $this->ensureAccess($request, $companyId);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $c = $this->service->grantAccess($this->find($companyId, $id), $data['email'], $request->user());

        return ApiResponse::success((new CollaboratorResource($c))->resolve(), 'Convite enviado. A pessoa define a password no link que recebe por email.');
    }

    public function resendInvite(Request $request, int $companyId, int $id)
    {
        $this->ensureAccess($request, $companyId);

        return ApiResponse::success((new CollaboratorResource($this->service->resendInvite($this->find($companyId, $id))))->resolve(), 'Convite reenviado.');
    }

    public function cancelInvite(Request $request, int $companyId, int $id)
    {
        $this->ensureAccess($request, $companyId);

        return ApiResponse::success((new CollaboratorResource($this->service->cancelInvite($this->find($companyId, $id))))->resolve(), 'Convite cancelado.');
    }

    public function revokeAccess(Request $request, int $companyId, int $id)
    {
        $this->ensureAccess($request, $companyId);

        return ApiResponse::success((new CollaboratorResource($this->service->revokeAccess($this->find($companyId, $id), $request->user())))->resolve(), 'Acesso retirado: a conta deixa de entrar na plataforma.');
    }

    public function restoreAccess(Request $request, int $companyId, int $id)
    {
        $this->ensureAccess($request, $companyId);

        return ApiResponse::success((new CollaboratorResource($this->service->restoreAccess($this->find($companyId, $id))))->resolve(), 'Acesso reposto.');
    }
}
