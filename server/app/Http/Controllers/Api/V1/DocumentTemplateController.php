<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentTemplateRequest;
use App\Http\Resources\DocumentTemplateResource;
use App\Models\Car;
use App\Models\DocumentTemplate;
use App\Services\DocumentTemplateService;
use App\Support\DocumentVariables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * DMS — Caminho B (Metade 1): modelos de documento .docx da empresa.
 *
 * Gestão dos modelos vive na área da EMPRESA (servem todas as viaturas). A
 * geração preenchida vive no contexto da viatura (venda). Tenant-guard em 2
 * camadas (padrão SupplierController).
 */
class DocumentTemplateController extends Controller
{
    public function __construct(protected DocumentTemplateService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    private function findScoped(int $companyId, int $id): ?DocumentTemplate
    {
        return DocumentTemplate::where('company_id', $companyId)->find($id);
    }

    public function index(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $templates = DocumentTemplate::where('company_id', $companyId)
            ->orderBy('archived')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            DocumentTemplateResource::collection($templates)->resolve(),
            'Document templates fetched successfully.'
        );
    }

    /** Catálogo de variáveis disponíveis (para o stand copiar para o .docx). */
    public function variables(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(DocumentVariables::catalog(), 'Variables fetched successfully.');
    }

    /** .docx de exemplo para download. */
    public function example(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $path = $this->service->exampleDocx();

        return response()->download($path, 'exemplo-modelo.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Ficheiro não-confiável: extensão .docx + mime da família OOXML/zip
            // (um .docx É um zip — o finfo deteta-o como application/zip).
            'file' => [
                'required', 'file', 'max:10240', 'extensions:docx',
                'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/octet-stream',
            ],
        ]);

        $template = $this->service->storeUpload($companyId, $data['name'], $request->file('file'));

        return ApiResponse::success(
            (new DocumentTemplateResource($template))->resolve(),
            'Document template created successfully.'
        );
    }

    /** Substitui o .docx do modelo (apaga o antigo). Mantém id/nome. */
    public function replaceFile(Request $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $template = $this->findScoped($companyId, $id);
        if (! $template) {
            return ApiResponse::error('Modelo não encontrado.', 404);
        }

        $request->validate([
            'file' => [
                'required', 'file', 'max:10240', 'extensions:docx',
                'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/octet-stream',
            ],
        ]);

        $updated = $this->service->replaceFile($template, $request->file('file'));

        return ApiResponse::success(
            (new DocumentTemplateResource($updated))->resolve(),
            'Document template file replaced successfully.'
        );
    }

    public function update(DocumentTemplateRequest $request, int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $template = $this->findScoped($companyId, $id);
        if (! $template) {
            return ApiResponse::error('Modelo não encontrado.', 404);
        }

        $data = $request->validated();
        unset($data['company_id']);
        $template->update($data);

        return ApiResponse::success(
            (new DocumentTemplateResource($template->fresh()))->resolve(),
            'Document template updated successfully.'
        );
    }

    public function destroy(int $companyId, int $id)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $template = $this->findScoped($companyId, $id);
        if (! $template) {
            return ApiResponse::error('Modelo não encontrado.', 404);
        }

        $this->service->deleteWithFile($template);

        return ApiResponse::success(null, 'Document template deleted successfully.');
    }

    /**
     * Gera o documento (modelo + venda) → download .docx.
     *
     * `preview=1`  → devolve o .docx com as LACUNAS como {{var}} literais, para o
     *               frontend as assinalar/preencher em tempo real (nunca 422).
     * `extra`      → valores EFÉMEROS introduzidos no preview (não gravam).
     * `force=1`    → gera mesmo com variáveis não reconhecidas.
     *
     * Rede de segurança (download, sem force): se houver variáveis NÃO
     * reconhecidas, devolve 422 com a lista.
     */
    public function generate(Request $request, int $companyId, int $carId, int $templateId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $template = $this->findScoped($companyId, $templateId);
        if (! $template) {
            return ApiResponse::error('Modelo não encontrado.', 404);
        }

        $car = Car::where('company_id', $companyId)->find($carId);
        if (! $car) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        $preview = $request->boolean('preview');
        $extra   = $request->input('extra', []);
        $extra   = is_array($extra) ? $extra : [];

        try {
            $result = $this->service->generate($template, $companyId, $carId, $extra, $preview);
        } catch (\Throwable $e) {
            return ApiResponse::error('Não foi possível gerar o documento.', 500);
        }

        if (! $preview && ! empty($result['unknown']) && ! $request->boolean('force')) {
            @unlink($result['path']);
            $list = implode(', ', array_map(fn ($m) => '{{' . $m . '}}', $result['unknown']));

            return ApiResponse::error(
                "Estas variáveis não foram reconhecidas: {$list}. Corrija o modelo ou gere mesmo assim.",
                422,
                ['missing' => $result['unknown']]
            );
        }

        return response()->download($result['path'], $result['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
