<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BlogRequest;
use App\Http\Resources\BlogResource;
use App\Models\Blog;
use App\Services\BlogService;
use App\Services\BlogWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Blog da empresa (backoffice). O middleware tenant garante a empresa da rota; aqui cada
 * artigo é procurado DENTRO da empresa da rota (404 se for de outra). A listagem mostra
 * só os artigos da empresa da rota, também para o root.
 */
class BlogController extends Controller
{
    public function __construct(
        protected BlogService $blogService,
        protected BlogWorkflowService $workflow,
    ) {}

    public function index(Request $request, int $companyId)
    {
        $data = $request->validate([
            'status'  => ['nullable', Rule::in(Blog::STATUSES)],
            'search'  => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Blog::query()
            ->where('company_id', $companyId)
            ->with('author:id,name')
            ->select(['id', 'company_id', 'user_id', 'title', 'slug', 'banner', 'excerpt', 'status', 'published_at', 'focus_keyword', 'submitted_at', 'review_note', 'updated_at', 'created_at'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(trim((string) ($data['search'] ?? '')), fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderByRaw("CASE status WHEN 'in_review' THEN 0 WHEN 'draft' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at');

        $page = $query->paginate($data['perPage'] ?? 20);
        $page->through(fn (Blog $b) => [
            'id'           => $b->id,
            'title'        => $b->title,
            'slug'         => $b->slug,
            'banner'       => $b->banner,
            'excerpt'      => $b->excerpt,
            'status'       => $b->status,
            'published_at' => optional($b->published_at)->toIso8601String(),
            'focus_keyword' => $b->focus_keyword,
            'submitted_at' => optional($b->submitted_at)->toIso8601String(),
            'has_review_note' => (bool) $b->review_note,
            'author_name'  => $b->author?->name,
            'updated_at'   => optional($b->updated_at)->toIso8601String(),
        ]);

        return ApiResponse::success([
            'page'        => $page,
            'can_approve' => BlogWorkflowService::canApprove($request->user(), $companyId),
            'counts'      => Blog::where('company_id', $companyId)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ], 'Artigos carregados.');
    }

    public function store(BlogRequest $request, int $companyId)
    {
        if (! BlogWorkflowService::canWrite($request->user(), $companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $blog = $this->blogService->createArticle($companyId, $request->user(), $request->articleData());

        return ApiResponse::success($this->resource($request, $blog), 'Artigo criado.', 201);
    }

    public function show(Request $request, int $companyId, int $id)
    {
        $blog = $this->find($companyId, $id);
        if (! $blog) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        return ApiResponse::success($this->resource($request, $blog), 'Artigo carregado.');
    }

    public function update(BlogRequest $request, int $companyId, int $id)
    {
        $blog = $this->find($companyId, $id);
        if (! $blog) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $this->workflow->assertCanEdit($request->user(), $blog);

        // Um artigo aprovado ou publicado não pode ficar com "[VERIFICAR]".
        $probe = (clone $blog)->fill(array_intersect_key($request->validated(), array_flip(array_keys(BlogWorkflowService::MARKER_FIELDS))));
        $this->workflow->assertNoMarkersIfLive($probe);

        $blog = $this->blogService->updateArticle($blog, $request->articleData());

        return ApiResponse::success($this->resource($request, $blog), 'Artigo guardado.');
    }

    public function destroy(Request $request, int $companyId, int $id)
    {
        $blog = $this->find($companyId, $id);
        if (! $blog) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $this->workflow->assertCanEdit($request->user(), $blog);

        $this->blogService->deleteArticle($blog);

        return ApiResponse::success(null, 'Artigo apagado.');
    }

    public function destroyBanner(Request $request, int $companyId, int $id)
    {
        $blog = $this->find($companyId, $id);
        if (! $blog) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $this->workflow->assertCanEdit($request->user(), $blog);

        return ApiResponse::success($this->resource($request, $this->blogService->removeBanner($blog)), 'Banner removido.');
    }

    // ── Fluxo ────────────────────────────────────────────────────────────────

    public function submit(Request $request, int $companyId, int $id)
    {
        return $this->transition($request, $companyId, $id, fn (Blog $b) => $this->workflow->submit($b, $request->user()), 'Artigo enviado para revisão.');
    }

    public function approve(Request $request, int $companyId, int $id)
    {
        $data = $request->validate(['publish_at' => ['nullable', 'date']]);
        $publishAt = ! empty($data['publish_at']) ? CarbonImmutable::parse($data['publish_at'])->utc() : null;

        return $this->transition($request, $companyId, $id, fn (Blog $b) => $this->workflow->approve($b, $request->user(), $publishAt), 'Artigo aprovado.');
    }

    public function requestChanges(Request $request, int $companyId, int $id)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']], ['note.required' => 'Indique as alterações pedidas.']);

        return $this->transition($request, $companyId, $id, fn (Blog $b) => $this->workflow->requestChanges($b, $request->user(), $data['note']), 'Artigo devolvido com pedido de alterações.');
    }

    public function backToDraft(Request $request, int $companyId, int $id)
    {
        return $this->transition($request, $companyId, $id, fn (Blog $b) => $this->workflow->backToDraft($b, $request->user()), 'Artigo devolvido a rascunho.');
    }

    public function buildRssUrl(Request $request, int $companyId)
    {
        //
    }

    // ── internos ─────────────────────────────────────────────────────────────

    private function transition(Request $request, int $companyId, int $id, \Closure $run, string $ok)
    {
        $blog = $this->find($companyId, $id);
        if (! $blog) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        return ApiResponse::success($this->resource($request, $run($blog)), $ok);
    }

    /** O artigo existe E pertence à empresa da rota. */
    private function find(int $companyId, int $id): ?Blog
    {
        return Blog::where('company_id', $companyId)->find($id);
    }

    private function resource(Request $request, Blog $blog): array
    {
        return (new BlogResource($blog->loadMissing(['author:id,name', 'submitter:id,name', 'approver:id,name', 'company:id,website'])))->resolve($request);
    }
}
