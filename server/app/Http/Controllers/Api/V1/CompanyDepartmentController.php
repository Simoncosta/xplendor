<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Collaborator;
use App\Models\CompanyDepartment;
use App\Services\CollaboratorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Departamentos da empresa e os contactos públicos de cada um (conteúdo do site). */
class CompanyDepartmentController extends Controller
{
    public function __construct(private readonly CollaboratorService $service) {}

    private function ensureContent(Request $request, int $companyId): void
    {
        abort_unless(CollaboratorService::canEditContent($request->user(), $companyId), 403, 'Só o administrador da empresa pode alterar os departamentos.');
    }

    private function rules(bool $creating): array
    {
        $phone = ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s.-]+$/'];

        return [
            'name'       => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'sort'       => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active'     => ['sometimes', 'boolean'],
            'whatsapp'   => $phone,
            'phone'      => $phone,
            'phone_type' => ['nullable', Rule::in(Collaborator::PHONE_TYPES), 'required_with:phone'],
            'email'      => ['nullable', 'email', 'max:255'],
        ];
    }

    private function find(int $companyId, int $id): CompanyDepartment
    {
        $d = CompanyDepartment::where('company_id', $companyId)->find($id);
        abort_unless($d, 404, 'Departamento não encontrado.');

        return $d;
    }

    public function index(int $companyId)
    {
        return ApiResponse::success(
            CompanyDepartment::where('company_id', $companyId)->withCount('collaborators')->orderBy('sort')->orderBy('name')->get(),
            'Departments fetched successfully.'
        );
    }

    public function store(Request $request, int $companyId)
    {
        $this->ensureContent($request, $companyId);
        $data = $request->validate($this->rules(true));
        $data['sort'] ??= ((int) CompanyDepartment::where('company_id', $companyId)->max('sort')) + 10;

        return ApiResponse::success(CompanyDepartment::create($data + ['company_id' => $companyId]), 'Departamento criado.', 201);
    }

    public function update(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);
        $d = $this->find($companyId, $id);
        $d->update($request->validate($this->rules(false)));

        return ApiResponse::success($d->fresh(), 'Departamento guardado.');
    }

    /** Apagar: os colaboradores do departamento ficam sem departamento. */
    public function destroy(Request $request, int $companyId, int $id)
    {
        $this->ensureContent($request, $companyId);
        $this->find($companyId, $id)->delete();

        return ApiResponse::success(null, 'Departamento apagado.');
    }

    public function suggested(Request $request, int $companyId)
    {
        $this->ensureContent($request, $companyId);
        $created = $this->service->createSuggestedDepartments($companyId);

        return ApiResponse::success(
            CompanyDepartment::where('company_id', $companyId)->orderBy('sort')->orderBy('name')->get(),
            $created ? "Criados {$created} departamentos sugeridos." : 'Os departamentos sugeridos já existiam.'
        );
    }
}
