<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\EditorialPostComment;
use App\Models\EditorialPostCreative;
use App\Models\EditorialPostEvent;
use App\Models\EditorialPostReview;
use App\Models\EditorialPostVersion;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Ai\AiText;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Linha Editorial, F3a: produção e aprovação dentro da XPLENDOR.
 *
 * Etapas: Ideia → Planeamento → Produção → Revisão (interna) → Aprovação (cliente) →
 * Programado → Publicado → Análise. O servidor decide sempre o que é permitido:
 *  · Produção (mover, editar, comentar): os utilizadores da empresa e a equipa XPLENDOR
 *    (root ou em sessão como cliente).
 *  · Aprovação: o administrador da empresa e os utilizadores marcados como aprovadores;
 *    nunca o root e nunca em impersonation.
 *  · A revisão interna, quando a empresa a exige, é feita por outra pessoa (a pessoa
 *    real, não o utilizador em cuja sessão a equipa está).
 *  · A versão é congelada no envio ao cliente; editar uma versão congelada cria a
 *    seguinte e, se estava em Aprovação ou Programado, a publicação volta a Produção.
 *  · Uma só aprovação por versão. Tudo fica no histórico com impersonator_user_id.
 * As publicações do canal Site seguem o fluxo do blog e não passam por aqui.
 */
class EditorialWorkflowService
{
    private const LOCKED_STAGES = [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS];
    private const SEND_STAGES = [EditorialPost::STAGE_INTERNAL_REVIEW, EditorialPost::STAGE_CLIENT_REVIEW, EditorialPost::STAGE_SCHEDULED];

    // ── Papéis ───────────────────────────────────────────────────────────────

    public static function impersonatorId(User $user): ?int
    {
        return ImpersonationSession::activeFor($user)?->root_id;
    }

    /** Pessoa real que faz a ação (a equipa em sessão como cliente conta como ela própria). */
    public static function personId(User $user): int
    {
        return self::impersonatorId($user) ?? (int) $user->id;
    }

    public static function isProducer(User $user, int $companyId): bool
    {
        return $user->role === 'root' || (int) $user->company_id === $companyId;
    }

    /** Equipa XPLENDOR: root ou em sessão como cliente. */
    public static function isTeam(User $user): bool
    {
        return $user->role === 'root' || ImpersonationSession::activeFor($user) !== null;
    }

    public static function isApprover(User $user, int $companyId): bool
    {
        return $user->role !== 'root'
            && (int) $user->company_id === $companyId
            && ($user->role === 'admin' || (bool) $user->can_approve_content)
            && ! ImpersonationSession::activeFor($user);
    }

    // ── Passagens ────────────────────────────────────────────────────────────

    /**
     * Etapas para onde o utilizador pode mover a publicação (aprovar e pedir alterações
     * são ações próprias). Devolve [etapa => motivo|null]; null = permitido.
     *
     * @return array<string, ?string>
     */
    public function moves(EditorialPost $post, User $user, ?Company $company = null): array
    {
        if ($post->channel === 'site' || ! self::isProducer($user, (int) $post->company_id)) {
            return [];
        }
        $company ??= Company::findOrFail($post->company_id);
        $approval = (bool) $company->content_approval_required;
        $internal = (bool) $company->internal_review_required;
        $reviewOk = $this->internalReviewBlocker($post, $user, $internal);

        return match ($post->stage) {
            EditorialPost::STAGE_IDEA => [EditorialPost::STAGE_PLANNING => null],
            EditorialPost::STAGE_PLANNING => [EditorialPost::STAGE_IDEA => null, EditorialPost::STAGE_PRODUCTION => null],
            EditorialPost::STAGE_PRODUCTION => [
                EditorialPost::STAGE_IDEA => null,
                EditorialPost::STAGE_PLANNING => null,
                EditorialPost::STAGE_INTERNAL_REVIEW => null,
                EditorialPost::STAGE_CLIENT_REVIEW => $approval ? ($internal ? 'A empresa exige a revisão interna antes do cliente.' : null) : 'Esta empresa não pede a aprovação do cliente.',
                EditorialPost::STAGE_SCHEDULED => $approval ? 'Precisa da aprovação do cliente.' : ($internal ? 'A empresa exige a revisão interna antes de programar.' : null),
            ],
            EditorialPost::STAGE_INTERNAL_REVIEW => [
                EditorialPost::STAGE_PRODUCTION => null,
                EditorialPost::STAGE_CLIENT_REVIEW => $approval ? $reviewOk : 'Esta empresa não pede a aprovação do cliente.',
                EditorialPost::STAGE_SCHEDULED => $approval ? 'Precisa da aprovação do cliente.' : $reviewOk,
            ],
            // Retirar da aprovação (a equipa); o cliente usa "pedir alterações".
            EditorialPost::STAGE_CLIENT_REVIEW => [EditorialPost::STAGE_PRODUCTION => null],
            EditorialPost::STAGE_SCHEDULED => [EditorialPost::STAGE_PUBLISHED => null],
            EditorialPost::STAGE_PUBLISHED => [EditorialPost::STAGE_ANALYSIS => null],
            default => [],
        };
    }

    /** @return string[] só as etapas permitidas */
    public function allowedMoves(EditorialPost $post, User $user, ?Company $company = null): array
    {
        return array_keys(array_filter($this->moves($post, $user, $company), fn ($reason) => $reason === null));
    }

    public function move(EditorialPost $post, User $user, string $to): EditorialPost
    {
        if (! in_array($to, EditorialPost::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => ['Etapa inválida.']]);
        }
        $this->assertNotSite($post);
        if (! self::isProducer($user, (int) $post->company_id)) {
            throw new HttpException(403, 'Acesso negado.');
        }

        return DB::transaction(function () use ($post, $user, $to) {
            $post = EditorialPost::lockForUpdate()->findOrFail($post->id);
            $from = $post->stage;
            $moves = $this->moves($post, $user);
            if (! array_key_exists($to, $moves)) {
                throw new HttpException(422, 'Esta passagem não é permitida.');
            }
            if ($moves[$to] !== null) {
                throw new HttpException(422, $moves[$to]);
            }

            $version = $post->currentVersion;
            if (in_array($to, self::SEND_STAGES, true) && (! $version || trim((string) $version->caption) === '')) {
                throw new HttpException(422, 'Escreva a legenda antes de enviar.');
            }

            $now = now();
            if ($to === EditorialPost::STAGE_CLIENT_REVIEW) {
                // Congelada no envio: o cliente aprova exatamente esta versão.
                $version->forceFill(['status' => EditorialPostVersion::SENT, 'sent_at' => $now, 'frozen_at' => $version->frozen_at ?? $now])->save();
                $post->changes_requested_at = null;
            }
            if ($to === EditorialPost::STAGE_SCHEDULED) {
                // Sem aprovação do cliente: a versão fica congelada e é a aprovada.
                $version->forceFill(['status' => EditorialPostVersion::APPROVED, 'frozen_at' => $version->frozen_at ?? $now])->save();
                $post->approved_version_id = $version->id;
            }

            $post->forceFill(['stage' => $to, 'stage_changed_at' => $now])->save();
            $this->event($post, $user, 'stage', $from, $to, $version?->id);

            return $post->fresh();
        });
    }

    // ── Aprovação dentro da XPLENDOR ─────────────────────────────────────────

    public function approve(EditorialPost $post, User $user, ?string $message = null): EditorialPost
    {
        $this->assertApprover($post, $user);

        return DB::transaction(function () use ($post, $user, $message) {
            [$post, $version] = $this->lockForReview($post);
            if (EditorialPostReview::where('approved_version_id', $version->id)->exists()) {
                throw new HttpException(409, 'Esta versão já foi aprovada.');
            }

            try {
                EditorialPostReview::create([
                    'company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'version_id' => $version->id,
                    'decision' => EditorialPostReview::APPROVED, 'via' => 'app', 'user_id' => $user->id,
                    'reviewer_name' => $user->name, 'message' => $message ? trim($message) : null,
                    'approved_version_id' => $version->id, 'created_at' => now(),
                ]);
            } catch (QueryException) {
                throw new HttpException(409, 'Esta versão já foi aprovada.');
            }

            $version->forceFill(['status' => EditorialPostVersion::APPROVED])->save();
            $post->forceFill([
                'stage' => EditorialPost::STAGE_SCHEDULED, 'stage_changed_at' => now(),
                'approved_version_id' => $version->id, 'changes_requested_at' => null,
            ])->save();
            $this->event($post, $user, 'review', EditorialPost::STAGE_CLIENT_REVIEW, EditorialPost::STAGE_SCHEDULED, $version->id,
                'Aprovada a versão ' . $version->number . ($message ? ': ' . trim($message) : '.'));

            return $post->fresh();
        });
    }

    public function requestChanges(EditorialPost $post, User $user, string $message): EditorialPost
    {
        $this->assertApprover($post, $user);
        $message = trim($message);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => ['Indique as alterações pedidas.']]);
        }

        return DB::transaction(function () use ($post, $user, $message) {
            [$post, $version] = $this->lockForReview($post);

            EditorialPostReview::create([
                'company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'version_id' => $version->id,
                'decision' => EditorialPostReview::CHANGES_REQUESTED, 'via' => 'app', 'user_id' => $user->id,
                'reviewer_name' => $user->name, 'message' => $message, 'created_at' => now(),
            ]);
            $version->forceFill(['status' => EditorialPostVersion::CHANGES_REQUESTED])->save();
            $post->forceFill(['stage' => EditorialPost::STAGE_PRODUCTION, 'stage_changed_at' => now(), 'changes_requested_at' => now()])->save();
            $this->event($post, $user, 'review', EditorialPost::STAGE_CLIENT_REVIEW, EditorialPost::STAGE_PRODUCTION, $version->id,
                'Alterações pedidas na versão ' . $version->number . ': ' . $message);

            return $post->fresh();
        });
    }

    /** "Aprovar tudo": aprova as publicações indicadas que estão à espera; ignora as outras. */
    public function approveMany(Company $company, User $user, array $postIds): array
    {
        if (! self::isApprover($user, $company->id)) {
            throw new HttpException(403, 'Só o administrador ou um aprovador da empresa pode aprovar, e nunca em sessão como cliente.');
        }
        $approved = [];
        $posts = EditorialPost::where('company_id', $company->id)->whereIn('id', $postIds)
            ->where('stage', EditorialPost::STAGE_CLIENT_REVIEW)->where('channel', '!=', 'site')->get();
        foreach ($posts as $post) {
            try {
                $this->approve($post, $user);
                $approved[] = $post->id;
            } catch (HttpException) {
                // já aprovada ou mudou entretanto: segue para a próxima
            }
        }

        return $approved;
    }

    // ── Conteúdo e versões ───────────────────────────────────────────────────

    public function saveContent(EditorialPost $post, User $user, array $data): EditorialPostVersion
    {
        $this->assertNotSite($post);
        if (! self::isProducer($user, (int) $post->company_id)) {
            throw new HttpException(403, 'Acesso negado.');
        }
        $data = $this->validateContent($post, $data);

        return DB::transaction(function () use ($post, $user, $data) {
            $post = EditorialPost::lockForUpdate()->findOrFail($post->id);
            if (in_array($post->stage, self::LOCKED_STAGES, true)) {
                throw new HttpException(409, 'Uma publicação já publicada não se altera.');
            }

            $current = $post->currentVersion;
            $impersonator = self::impersonatorId($user);
            $actor = ['updated_by_user_id' => $user->id, 'updated_by_impersonator_id' => $impersonator];

            if ($current && ! $current->isFrozen()) {
                $current->forceFill($data + $actor)->save();

                return $current->fresh();
            }

            // Primeira versão (a partir do criativo aceite) ou a seguinte a uma congelada.
            $base = $current ? $current->only(EditorialPostVersion::CONTENT_FIELDS) : $this->fromCreative($post);
            $version = EditorialPostVersion::create($data + $base + $actor + [
                'company_id' => $post->company_id, 'editorial_post_id' => $post->id,
                'number' => (int) EditorialPostVersion::where('editorial_post_id', $post->id)->max('number') + 1,
                'status' => EditorialPostVersion::DRAFT,
                'created_by_user_id' => $user->id, 'impersonator_user_id' => $impersonator,
            ]);
            if ($current && in_array($current->status, [EditorialPostVersion::SENT, EditorialPostVersion::CHANGES_REQUESTED], true)) {
                $current->forceFill(['status' => EditorialPostVersion::SUPERSEDED])->save();
            }

            $post->current_version_id = $version->id;
            $from = $post->stage;
            if (in_array($from, [EditorialPost::STAGE_CLIENT_REVIEW, EditorialPost::STAGE_SCHEDULED], true)) {
                // Conteúdo alterado depois de enviado ou aprovado: volta a pedir aprovação.
                $post->stage = EditorialPost::STAGE_PRODUCTION;
                $post->stage_changed_at = now();
            }
            $post->save();

            $this->event($post, $user, 'version', null, null, $version->id, 'Versão ' . $version->number . ' criada.');
            if ($from !== $post->stage) {
                $this->event($post, $user, 'stage', $from, $post->stage, $version->id, 'Conteúdo alterado depois de enviado: volta a Produção.');
            }

            return $version;
        });
    }

    // ── Comentários ──────────────────────────────────────────────────────────

    public function addComment(EditorialPost $post, User $user, string $body, string $visibility): EditorialPostComment
    {
        if (! self::isProducer($user, (int) $post->company_id)) {
            throw new HttpException(403, 'Acesso negado.');
        }
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => ['Escreva o comentário.']]);
        }
        if ($visibility === EditorialPostComment::INTERNAL && ! self::isTeam($user)) {
            throw ValidationException::withMessages(['visibility' => ['Só a equipa XPLENDOR escreve comentários internos.']]);
        }

        $comment = EditorialPostComment::create([
            'company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'version_id' => $post->current_version_id,
            'user_id' => $user->id, 'impersonator_user_id' => self::impersonatorId($user),
            'author_name' => $this->personName($user), 'body' => mb_substr($body, 0, 5000), 'visibility' => $visibility,
        ]);
        $internal = $visibility === EditorialPostComment::INTERNAL;
        $this->event($post, $user, $internal ? 'comment_internal' : 'comment', null, null, $post->current_version_id,
            $internal ? 'Comentário interno.' : 'Comentário partilhado.');

        return $comment;
    }

    // ── Histórico (também usado pela criação e edição das publicações) ────────

    public function event(EditorialPost $post, ?User $user, string $type, ?string $from = null, ?string $to = null, ?int $versionId = null, ?string $message = null): void
    {
        $user ??= Auth::user();
        EditorialPostEvent::create([
            'company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'type' => $type,
            'from_stage' => $from, 'to_stage' => $to, 'version_id' => $versionId,
            'user_id' => $user?->id, 'impersonator_user_id' => $user ? self::impersonatorId($user) : null,
            'message' => $message ? mb_substr($message, 0, 500) : null, 'created_at' => now(),
        ]);
    }

    // ── Apresentação ─────────────────────────────────────────────────────────

    /** Detalhe de produção: versões, decisões, comentários, histórico e permissões. */
    public function detail(EditorialPost $post, User $user): array
    {
        $company = Company::findOrFail($post->company_id);
        $team = self::isTeam($user);
        $versions = EditorialPostVersion::where('editorial_post_id', $post->id)->orderByDesc('number')->get();
        $people = $this->peopleNames($post);
        $creative = EditorialPostCreative::where('editorial_post_id', $post->id)->first();

        return [
            'post' => [
                'id' => $post->id, 'title' => $post->title, 'channel' => $post->channel, 'publish_date' => $post->publish_date->toDateString(),
                'stage' => $post->stage, 'format' => $post->format, 'media_format' => $post->media_format, 'keyword' => $post->keyword,
                'changes_requested_at' => optional($post->changes_requested_at)->toIso8601String(),
                'current_version_id' => $post->current_version_id, 'approved_version_id' => $post->approved_version_id,
            ],
            'versions' => $versions->map(fn (EditorialPostVersion $v) => [
                'id' => $v->id, 'number' => $v->number, 'status' => $v->status, 'frozen' => $v->isFrozen(),
                'caption' => $v->caption, 'hashtags' => $v->hashtags ?? [], 'cta' => $v->cta, 'first_comment' => $v->first_comment,
                'media_format' => $v->media_format,
                'author' => $this->label($people, $v->created_by_user_id, $v->impersonator_user_id),
                'sent_at' => optional($v->sent_at)->toIso8601String(), 'created_at' => optional($v->created_at)->toIso8601String(),
            ])->all(),
            'reviews' => EditorialPostReview::where('editorial_post_id', $post->id)->orderByDesc('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'version_number' => $versions->firstWhere('id', $r->version_id)?->number,
                'decision' => $r->decision, 'via' => $r->via, 'reviewer' => $r->reviewer_name, 'message' => $r->message,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ])->all(),
            'comments' => EditorialPostComment::where('editorial_post_id', $post->id)
                ->when(! $team, fn ($q) => $q->where('visibility', EditorialPostComment::SHARED))
                ->orderBy('id')->get()->map(fn ($c) => [
                    'id' => $c->id, 'author' => $c->author_name, 'body' => $c->body, 'visibility' => $c->visibility,
                    'version_number' => $versions->firstWhere('id', $c->version_id)?->number,
                    'mine' => (int) $c->user_id === (int) $user->id, 'created_at' => optional($c->created_at)->toIso8601String(),
                ])->all(),
            'events' => EditorialPostEvent::where('editorial_post_id', $post->id)->orderByDesc('id')->limit(100)->get()
                ->filter(fn ($e) => $team || $e->type !== 'comment_internal')->values()->map(fn ($e) => [
                    'type' => $e->type, 'from_stage' => $e->from_stage, 'to_stage' => $e->to_stage, 'message' => $e->message,
                    'who' => $this->label($people, $e->user_id, $e->impersonator_user_id),
                    'created_at' => optional($e->created_at)->toIso8601String(),
                ])->all(),
            'creative' => $creative ? ['caption' => $creative->caption, 'hashtags' => $creative->hashtags ?? [], 'cta' => $creative->cta, 'media_format' => $creative->media_format] : null,
            'moves' => $this->moves($post, $user, $company),
            'permissions' => [
                'can_edit_content' => self::isProducer($user, (int) $post->company_id) && $post->channel !== 'site' && ! in_array($post->stage, self::LOCKED_STAGES, true),
                'can_approve' => self::isApprover($user, (int) $post->company_id) && $post->stage === EditorialPost::STAGE_CLIENT_REVIEW,
                'is_approver' => self::isApprover($user, (int) $post->company_id),
                'is_team' => $team,
            ],
            'settings' => [
                'content_approval_required' => (bool) $company->content_approval_required,
                'internal_review_required' => (bool) $company->internal_review_required,
            ],
        ];
    }

    // ── internos ─────────────────────────────────────────────────────────────

    private function assertNotSite(EditorialPost $post): void
    {
        if ($post->channel === 'site') {
            throw new HttpException(422, 'As publicações do Site seguem o fluxo do blog.');
        }
    }

    private function assertApprover(EditorialPost $post, User $user): void
    {
        $this->assertNotSite($post);
        if (! self::isApprover($user, (int) $post->company_id)) {
            throw new HttpException(403, 'Só o administrador ou um aprovador da empresa pode aprovar, e nunca em sessão como cliente.');
        }
    }

    /** @return array{0: EditorialPost, 1: EditorialPostVersion} */
    private function lockForReview(EditorialPost $post): array
    {
        $post = EditorialPost::lockForUpdate()->findOrFail($post->id);
        if ($post->stage !== EditorialPost::STAGE_CLIENT_REVIEW) {
            throw new HttpException(422, 'Esta publicação não está à espera de aprovação.');
        }
        $version = $post->currentVersion;
        if (! $version || $version->status !== EditorialPostVersion::SENT) {
            throw new HttpException(422, 'Não há uma versão enviada para aprovar.');
        }

        return [$post, $version];
    }

    /** null se a pessoa pode concluir a revisão interna; senão, o motivo. */
    private function internalReviewBlocker(EditorialPost $post, User $user, bool $required): ?string
    {
        if (! $required) {
            return null;
        }
        // Quem fez a produção: a pessoa real que editou a versão por último.
        $v = $post->currentVersion;
        $authorId = $v ? ($v->updated_by_impersonator_id ?? $v->updated_by_user_id ?? $v->authorPersonId()) : null;

        return $authorId !== null && $authorId === self::personId($user)
            ? 'A revisão interna tem de ser feita por outra pessoa.'
            : null;
    }

    private function validateContent(EditorialPost $post, array $data): array
    {
        $clean = validator($data, [
            'caption'       => ['nullable', 'string', 'max:2200'],
            'hashtags'      => ['nullable', 'array', 'max:30'],
            'hashtags.*'    => ['nullable', 'string', 'max:60'],
            'cta'           => ['nullable', 'string', 'max:300'],
            'first_comment' => ['nullable', 'string', 'max:2200'],
            'media_format'  => ['nullable', Rule::in(EditorialPost::MEDIA_FORMATS[$post->channel] ?? [])],
        ], ['media_format.in' => 'Escolha um formato válido para esta rede.'])->validate();

        $out = [];
        foreach (['caption', 'cta', 'first_comment'] as $f) {
            if (array_key_exists($f, $clean)) {
                $out[$f] = AiText::plain($clean[$f] ?? '', $f === 'cta' ? 300 : 2200, true) ?: null;
            }
        }
        if (array_key_exists('hashtags', $clean)) {
            $out['hashtags'] = AiText::hashtags($clean['hashtags'] ?? []);
        }
        if (array_key_exists('media_format', $clean)) {
            $out['media_format'] = $clean['media_format'] ?: null;
        }

        return $out;
    }

    private function fromCreative(EditorialPost $post): array
    {
        $c = EditorialPostCreative::where('editorial_post_id', $post->id)->first();

        return [
            'caption' => $c?->caption, 'hashtags' => $c?->hashtags, 'cta' => $c?->cta,
            'first_comment' => null, 'media_format' => $c?->media_format ?? $post->media_format,
        ];
    }

    private function personName(User $user): string
    {
        $imp = self::impersonatorId($user);
        if ($imp) {
            return (User::find($imp)?->name ?? 'Equipa') . ' (equipa XPLENDOR)';
        }

        return $user->role === 'root' ? "{$user->name} (equipa XPLENDOR)" : (string) $user->name;
    }

    private function peopleNames(EditorialPost $post): array
    {
        $ids = collect([
            EditorialPostEvent::where('editorial_post_id', $post->id)->pluck('user_id'),
            EditorialPostEvent::where('editorial_post_id', $post->id)->pluck('impersonator_user_id'),
            EditorialPostVersion::where('editorial_post_id', $post->id)->pluck('created_by_user_id'),
            EditorialPostVersion::where('editorial_post_id', $post->id)->pluck('impersonator_user_id'),
        ])->flatten()->filter()->unique()->all();

        return User::whereIn('id', $ids)->get(['id', 'name', 'role'])->keyBy('id')->all();
    }

    /** "Ana (equipa XPLENDOR)" quando foi a equipa; senão o nome do utilizador. */
    private function label(array $people, ?int $userId, ?int $impersonatorId): ?string
    {
        if ($impersonatorId) {
            return ($people[$impersonatorId]->name ?? 'Equipa') . ' (equipa XPLENDOR)';
        }
        $u = $userId ? ($people[$userId] ?? null) : null;
        if (! $u) {
            return null;
        }

        return $u->role === 'root' ? "{$u->name} (equipa XPLENDOR)" : (string) $u->name;
    }
}
