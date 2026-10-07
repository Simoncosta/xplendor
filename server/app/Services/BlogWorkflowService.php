<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\BlogReviewRequestedMail;
use App\Models\Blog;
use App\Models\Company;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Tenancy\CompanyAccess;
use App\Support\BlogHtml;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fluxo editorial do blog: rascunho → em revisão → aprovado (agendado) → publicado.
 *  · Escrever e enviar para revisão: qualquer utilizador da empresa e a equipa XPLENDOR
 *    (root ou em sessão como cliente).
 *  · Aprovar, pedir alterações, voltar a rascunho e alterar artigos aprovados ou publicados:
 *    só o administrador da própria empresa, fora de impersonation.
 *  · A aprovação é bloqueada enquanto houver "[VERIFICAR]" por resolver; o resto do
 *    checklist de SEO são avisos.
 *  · Aprovado com data futura fica agendado; o PublishScheduledBlogsJob publica-o.
 */
class BlogWorkflowService
{
    /** Campos onde se procura "[VERIFICAR]". */
    public const MARKER_FIELDS = [
        'title' => 'Título', 'subtitle' => 'Subtítulo', 'excerpt' => 'Resumo', 'content' => 'Texto',
        'meta_title' => 'Título SEO', 'meta_description' => 'Meta description',
    ];

    public function __construct(private readonly AlertService $alerts) {}

    // ── Permissões ───────────────────────────────────────────────────────────

    public static function canWrite(User $actor, int $companyId): bool
    {
        return app(CompanyAccess::class)->allows($actor, $companyId);
    }

    /** Administrador da própria empresa (o root só na sua própria empresa), fora de impersonation. */
    public static function canApprove(User $actor, int $companyId): bool
    {
        return in_array($actor->role, ['admin', 'root'], true)
            && (int) $actor->company_id === $companyId
            && ! ImpersonationSession::activeFor($actor);
    }

    public static function canEdit(User $actor, Blog $blog): bool
    {
        if (! self::canWrite($actor, (int) $blog->company_id)) {
            return false;
        }

        return in_array($blog->status, [Blog::DRAFT, Blog::IN_REVIEW], true) || self::canApprove($actor, (int) $blog->company_id);
    }

    public static function permissions(User $actor, Blog $blog): array
    {
        $approver = self::canApprove($actor, (int) $blog->company_id);
        $writer = self::canWrite($actor, (int) $blog->company_id);

        return [
            'can_edit'            => self::canEdit($actor, $blog),
            'can_delete'          => self::canEdit($actor, $blog),
            'can_submit'          => $writer && $blog->status === Blog::DRAFT,
            'can_approve'         => $approver && in_array($blog->status, [Blog::DRAFT, Blog::IN_REVIEW], true),
            'can_request_changes' => $approver && $blog->status === Blog::IN_REVIEW,
            'can_unpublish'       => $approver && in_array($blog->status, [Blog::APPROVED, Blog::PUBLISHED], true),
            'is_approver'         => $approver,
            'slug_locked'         => $blog->isSlugLocked(),
        ];
    }

    public function assertCanEdit(User $actor, Blog $blog): void
    {
        if (! self::canEdit($actor, $blog)) {
            throw new HttpException(403, 'Só o administrador da empresa pode alterar um artigo aprovado ou publicado. Peça para o devolver a rascunho.');
        }
    }

    /** Um artigo aprovado ou publicado não pode ficar com "[VERIFICAR]" por resolver. */
    public function assertNoMarkersIfLive(Blog $blog): void
    {
        if (in_array($blog->status, [Blog::APPROVED, Blog::PUBLISHED], true)) {
            $this->assertNoMarkers($blog);
        }
    }

    /** @return string[] rótulos dos campos com "[VERIFICAR]" */
    public static function unresolvedMarkers(Blog $blog): array
    {
        $found = [];
        foreach (self::MARKER_FIELDS as $field => $label) {
            if (BlogHtml::hasVerifyMarker((string) $blog->{$field})) {
                $found[] = $label;
            }
        }

        return $found;
    }

    // ── Transições ───────────────────────────────────────────────────────────

    public function submit(Blog $blog, User $actor): Blog
    {
        if (! self::canWrite($actor, (int) $blog->company_id)) {
            throw new HttpException(403, 'Acesso negado.');
        }

        $blog = DB::transaction(function () use ($blog, $actor) {
            $blog = Blog::lockForUpdate()->findOrFail($blog->id);
            if ($blog->status !== Blog::DRAFT) {
                throw ValidationException::withMessages(['status' => ['Só um rascunho pode ser enviado para revisão.']]);
            }
            $this->assertComplete($blog);

            $blog->forceFill([
                'status' => Blog::IN_REVIEW, 'submitted_at' => now(), 'submitted_by' => $actor->id, 'review_note' => null,
            ])->save();

            return $blog;
        });

        $this->notifyReviewRequested($blog, $actor);

        return $blog;
    }

    /** Aprova: sem data (ou data passada) publica já; com data futura fica agendado. */
    public function approve(Blog $blog, User $actor, ?CarbonImmutable $publishAt): Blog
    {
        $this->assertApprover($actor, $blog);

        return DB::transaction(function () use ($blog, $actor, $publishAt) {
            $blog = Blog::lockForUpdate()->findOrFail($blog->id);
            if (! in_array($blog->status, [Blog::DRAFT, Blog::IN_REVIEW], true)) {
                throw ValidationException::withMessages(['status' => ['Este artigo já foi aprovado.']]);
            }
            $this->assertComplete($blog);
            $this->assertNoMarkers($blog);

            $now = now();
            $scheduled = $publishAt !== null && $publishAt->greaterThan($now);
            $blog->forceFill([
                'status'       => $scheduled ? Blog::APPROVED : Blog::PUBLISHED,
                'published_at' => $scheduled ? $publishAt : $now,
                'approved_at'  => $now,
                'approved_by'  => $actor->id,
                'review_note'  => null,
            ]);
            if (! $scheduled && $blog->first_published_at === null) {
                $blog->first_published_at = $now;
            }
            $blog->save();

            return $blog;
        });
    }

    public function requestChanges(Blog $blog, User $actor, string $note): Blog
    {
        $this->assertApprover($actor, $blog);

        return DB::transaction(function () use ($blog, $note) {
            $blog = Blog::lockForUpdate()->findOrFail($blog->id);
            if ($blog->status !== Blog::IN_REVIEW) {
                throw ValidationException::withMessages(['status' => ['Só um artigo em revisão pode ser devolvido com alterações.']]);
            }
            $blog->forceFill(['status' => Blog::DRAFT, 'review_note' => trim($note)])->save();

            return $blog;
        });
    }

    /** Aprovado ou publicado → rascunho (sai do site; o slug mantém-se se já foi publicado). */
    public function backToDraft(Blog $blog, User $actor): Blog
    {
        $this->assertApprover($actor, $blog);

        return DB::transaction(function () use ($blog) {
            $blog = Blog::lockForUpdate()->findOrFail($blog->id);
            if (! in_array($blog->status, [Blog::APPROVED, Blog::PUBLISHED], true)) {
                throw ValidationException::withMessages(['status' => ['O artigo já está em rascunho ou em revisão.']]);
            }
            $blog->forceFill(['status' => Blog::DRAFT, 'published_at' => null, 'approved_at' => null, 'approved_by' => null])->save();

            return $blog;
        });
    }

    /** Publica os artigos aprovados cuja data chegou. Devolve quantos publicou. */
    public function publishDue(): int
    {
        $count = 0;
        Blog::where('status', Blog::APPROVED)->where('published_at', '<=', now())->orderBy('id')
            ->chunkById(100, function ($blogs) use (&$count) {
                foreach ($blogs as $blog) {
                    DB::transaction(function () use ($blog, &$count) {
                        $fresh = Blog::lockForUpdate()->find($blog->id);
                        if (! $fresh || $fresh->status !== Blog::APPROVED || $fresh->published_at === null || $fresh->published_at->isFuture()) {
                            return;
                        }
                        $fresh->forceFill([
                            'status' => Blog::PUBLISHED,
                            'first_published_at' => $fresh->first_published_at ?? $fresh->published_at,
                        ])->save();
                        $count++;
                    });
                }
            });

        return $count;
    }

    // ── internos ─────────────────────────────────────────────────────────────

    private function assertApprover(User $actor, Blog $blog): void
    {
        if (! self::canApprove($actor, (int) $blog->company_id)) {
            throw new HttpException(403, 'Só o administrador da empresa pode aprovar ou devolver artigos.');
        }
    }

    private function assertComplete(Blog $blog): void
    {
        if (trim((string) $blog->title) === '' || BlogHtml::wordCount($blog->content) === 0) {
            throw ValidationException::withMessages(['content' => ['O artigo precisa de título e de texto.']]);
        }
    }

    private function assertNoMarkers(Blog $blog): void
    {
        $fields = self::unresolvedMarkers($blog);
        if ($fields) {
            throw ValidationException::withMessages([
                'markers' => ['Há "[VERIFICAR]" por resolver em: ' . implode(', ', $fields) . '. Confirme esses pontos antes de aprovar.'],
            ]);
        }
    }

    /** Sino da empresa + email a cada administrador ativo. Uma falha no email não anula o envio. */
    private function notifyReviewRequested(Blog $blog, User $actor): void
    {
        $this->alerts->createSystemAlert(
            companyId: (int) $blog->company_id,
            type: 'opportunity',
            title: 'Artigo para rever',
            message: "\u{201C}{$blog->title}\u{201D} aguarda a aprovação de um administrador.",
            severity: 'medium',
            detailPath: "/blogs/{$blog->id}",
        );

        $company = Company::find($blog->company_id);
        $admins = User::where('company_id', $blog->company_id)->where('role', 'admin')
            ->whereNull('deactivated_at')->whereNotNull('email')->get(['id', 'name', 'email']);

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->queue(new BlogReviewRequestedMail(
                    blogId: (int) $blog->id,
                    title: (string) $blog->title,
                    companyName: (string) ($company?->trade_name ?: $company?->fiscal_name ?: ''),
                    authorName: (string) $actor->name,
                    url: rtrim((string) config('app.frontend_url'), '/') . "/blogs/{$blog->id}",
                ));
            } catch (\Throwable $e) {
                Log::warning('[Blog] Falha ao enviar o email de revisão', ['blog_id' => $blog->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
