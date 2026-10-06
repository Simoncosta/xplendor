<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\EditorialPostComment;
use App\Models\User;
use App\Services\CollaboratorService;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\EditorialLineService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Linha Editorial, F3a: Kanban das etapas, detalhe de produção (versões, decisões,
 * comentários, histórico), mudar de etapa, editar o conteúdo, aprovar ou pedir alterações
 * dentro da XPLENDOR, e as definições do fluxo da empresa. O middleware tenant garante a
 * empresa da rota; cada publicação é procurada dentro dela (404 se for de outra). As
 * permissões vivem no EditorialWorkflowService.
 */
class EditorialWorkflowController extends Controller
{
    public function __construct(
        private readonly EditorialWorkflowService $workflow,
        private readonly EditorialLineService $line,
    ) {}

    /** Kanban de um mês: as publicações com a etapa, a versão e o que o utilizador pode fazer. */
    public function board(Request $request, int $companyId)
    {
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $company = Company::findOrFail($companyId);
        $user = $request->user();
        $approver = EditorialWorkflowService::isApprover($user, $companyId);

        $start = \Carbon\CarbonImmutable::parse($data['month'] . '-01');
        $posts = EditorialPost::where('company_id', $companyId)
            ->whereBetween('publish_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->with(['currentVersion:id,number,status', 'blog:id,company_id,title,status'])
            ->withCount(['comments as comments_count' => fn ($q) => EditorialWorkflowService::isTeam($user) ? $q : $q->where('visibility', EditorialPostComment::SHARED)])
            ->orderBy('publish_date')->orderBy('id')
            ->get();
        // F3d: na Análise (e em Publicada), o alcance e a taxa de envolvimento no cartão.
        $metrics = \App\Models\EditorialPostMetric::whereIn('editorial_post_id', $posts->whereIn('stage', [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS])->pluck('id'))
            ->get()->groupBy('editorial_post_id');

        return ApiResponse::success([
            'month' => $data['month'],
            'is_approver' => $approver,
            'is_team' => EditorialWorkflowService::isTeam($user),
            'can_produce' => EditorialWorkflowService::isProducer($user, $companyId),
            'settings' => [
                'content_approval_required' => (bool) $company->content_approval_required,
                'internal_review_required' => (bool) $company->internal_review_required,
                'production_mode' => EditorialWorkflowService::productionMode($companyId),
            ],
            'posts' => $posts->map(fn (EditorialPost $p) => [
                'id' => $p->id, 'title' => $p->title, 'channel' => $p->channel, 'publish_date' => $p->publish_date->toDateString(),
                'stage' => $p->stage, 'format' => $p->format, 'media_format' => $p->media_format,
                'publish_time' => $p->publish_time, 'overdue' => $p->isOverdue(),
                'results' => isset($metrics[$p->id]) ? (function () use ($p, $metrics) {
                    $m = \App\Services\Editorial\EditorialPublishingService::metrics($p, $metrics[$p->id]);

                    return ['reach' => $m['values']['reach']['value'] ?? null, 'engagement_rate' => $m['engagement_rate']];
                })() : null,
                'version' => $p->currentVersion ? ['number' => $p->currentVersion->number, 'status' => $p->currentVersion->status] : null,
                'changes_requested' => $p->changes_requested_at !== null,
                'comments_count' => (int) $p->comments_count,
                'blog' => $p->blog && (int) $p->blog->company_id === $companyId ? ['id' => $p->blog->id, 'status' => $p->blog->status] : null,
                'moves' => $this->workflow->allowedMoves($p, $user, $company),
                'can_approve' => $approver && $p->stage === EditorialPost::STAGE_CLIENT_REVIEW && $p->channel !== 'site',
            ])->all(),
        ], 'Quadro carregado.');
    }

    public function show(Request $request, int $companyId, int $postId)
    {
        return ApiResponse::success($this->workflow->detail($this->post($companyId, $postId), $request->user()), 'Publicação carregada.');
    }

    public function move(Request $request, int $companyId, int $postId)
    {
        $data = $request->validate(['stage' => ['required', Rule::in(EditorialPost::STAGES)]]);
        $post = $this->workflow->move($this->post($companyId, $postId), $request->user(), $data['stage']);

        return ApiResponse::success($this->workflow->detail($post, $request->user()), 'Etapa atualizada.');
    }

    public function content(Request $request, int $companyId, int $postId)
    {
        $post = $this->post($companyId, $postId);
        $this->workflow->saveContent($post, $request->user(), $request->only(['caption', 'hashtags', 'cta', 'first_comment', 'media_format']));

        return ApiResponse::success($this->workflow->detail($post->fresh(), $request->user()), 'Conteúdo guardado.');
    }

    public function comment(Request $request, int $companyId, int $postId)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['nullable', Rule::in([EditorialPostComment::INTERNAL, EditorialPostComment::SHARED])],
        ], ['body.required' => 'Escreva o comentário.']);
        $post = $this->post($companyId, $postId);
        $this->workflow->addComment($post, $request->user(), $data['body'], $data['visibility'] ?? EditorialPostComment::SHARED);

        return ApiResponse::success($this->workflow->detail($post->fresh(), $request->user()), 'Comentário publicado.');
    }

    public function approve(Request $request, int $companyId, int $postId)
    {
        $data = $request->validate(['message' => ['nullable', 'string', 'max:2000']]);
        $post = $this->workflow->approve($this->post($companyId, $postId), $request->user(), $data['message'] ?? null);

        return ApiResponse::success($this->workflow->detail($post, $request->user()), 'Publicação aprovada.');
    }

    public function requestChanges(Request $request, int $companyId, int $postId)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']], ['message.required' => 'Indique as alterações pedidas.']);
        $post = $this->workflow->requestChanges($this->post($companyId, $postId), $request->user(), $data['message']);

        return ApiResponse::success($this->workflow->detail($post, $request->user()), 'Alterações pedidas.');
    }

    /** "Aprovar tudo" (as publicações indicadas que estão à espera). */
    public function approveAll(Request $request, int $companyId)
    {
        $data = $request->validate(['post_ids' => ['required', 'array', 'max:200'], 'post_ids.*' => ['integer']]);
        $approved = $this->workflow->approveMany(Company::findOrFail($companyId), $request->user(), $data['post_ids']);

        return ApiResponse::success(['approved' => $approved], count($approved) === 1 ? '1 publicação aprovada.' : count($approved) . ' publicações aprovadas.');
    }

    // ── Definições do fluxo da empresa ───────────────────────────────────────

    public function settings(Request $request, int $companyId)
    {
        return ApiResponse::success($this->presentSettings(Company::findOrFail($companyId), $request->user()), 'Definições carregadas.');
    }

    /**
     * Aprovação do cliente e revisão interna: o administrador ou a equipa XPLENDOR.
     * Modo de produção: só a equipa XPLENDOR o muda (é ela que passa a produzir).
     */
    public function updateSettings(Request $request, int $companyId)
    {
        if (! CollaboratorService::canEditContent($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa ou a equipa XPLENDOR pode alterar o fluxo.', 403);
        }
        $data = $request->validate([
            'content_approval_required' => ['required', 'boolean'],
            'internal_review_required' => ['required', 'boolean'],
            'production_mode' => ['nullable', Rule::in(EditorialWorkflowService::MODES)],
        ]);
        $company = Company::findOrFail($companyId);
        $mode = $data['production_mode'] ?? null;
        unset($data['production_mode']);
        if ($mode !== null && $mode !== EditorialWorkflowService::productionMode($companyId)) {
            if (! EditorialWorkflowService::isTeam($request->user())) {
                return ApiResponse::error('O modo de produção só é alterado pela equipa XPLENDOR.', 403);
            }
            $data['content_production_mode'] = $mode;
        }
        $company->forceFill($data)->save();

        return ApiResponse::success($this->presentSettings($company, $request->user()), 'Definições guardadas.');
    }

    /** Quem pode aprovar: só o administrador da própria empresa, fora de impersonation. */
    public function setApprover(Request $request, int $companyId, int $userId)
    {
        if (! CollaboratorService::canManageAccess($request->user(), $companyId)) {
            return ApiResponse::error('Só o administrador da empresa pode escolher quem aprova os conteúdos.', 403);
        }
        $data = $request->validate(['can_approve_content' => ['required', 'boolean']]);
        $target = User::where('company_id', $companyId)->whereNull('deactivated_at')->find($userId);
        if (! $target) {
            return ApiResponse::error('Utilizador não encontrado.', 404);
        }
        $target->forceFill(['can_approve_content' => $data['can_approve_content']])->save();

        return ApiResponse::success($this->presentSettings(Company::findOrFail($companyId), $request->user()), 'Aprovadores atualizados.');
    }

    // ── internos ─────────────────────────────────────────────────────────────

    private function post(int $companyId, int $postId): EditorialPost
    {
        $post = EditorialPost::where('company_id', $companyId)->find($postId);
        abort_if(! $post, 404, 'Publicação não encontrada.');

        return $post;
    }

    private function presentSettings(Company $company, User $user): array
    {
        return [
            'content_approval_required' => (bool) $company->content_approval_required,
            'internal_review_required' => (bool) $company->internal_review_required,
            'production_mode' => EditorialWorkflowService::productionMode($company->id),
            'can_change_mode' => EditorialWorkflowService::isTeam($user),
            'can_edit' => CollaboratorService::canEditContent($user, $company->id),
            'can_manage_approvers' => CollaboratorService::canManageAccess($user, $company->id),
            'users' => User::where('company_id', $company->id)->whereNull('deactivated_at')->whereIn('role', ['admin', 'user'])
                ->orderBy('name')->get(['id', 'name', 'email', 'role', 'can_approve_content'])
                ->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'role' => $u->role,
                    'is_approver' => $u->role === 'admin' || (bool) $u->can_approve_content,
                    'by_role' => $u->role === 'admin',
                ])->all(),
        ];
    }
}
