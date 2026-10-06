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
use App\Models\MediaAsset;
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
 *  · Produção (mover, editar): a equipa XPLENDOR (root ou em sessão como cliente) e, em
 *    "Produção própria" (por omissão), os utilizadores da empresa. Em "Produção pela equipa
 *    XPLENDOR" os utilizadores do cliente só comentam, aprovam e pedem alterações.
 *  · Comentar: os utilizadores da empresa e a equipa.
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
    public const MODE_SELF = 'self';
    public const MODE_TEAM = 'team';
    public const MODES = [self::MODE_SELF, self::MODE_TEAM];

    public const MSG_TEAM_PRODUCES = 'Nesta empresa a produção é feita pela equipa XPLENDOR: pode comentar, aprovar ou pedir alterações.';

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

    /** Utilizador da empresa ou equipa XPLENDOR (ver e comentar). */
    public static function isMember(User $user, int $companyId): bool
    {
        return $user->role === 'root' || (int) $user->company_id === $companyId;
    }

    /** Pode produzir (editar conteúdo, mudar etapas, planear): a equipa sempre; o cliente só em "Produção própria". */
    public static function isProducer(User $user, int $companyId): bool
    {
        if (! self::isMember($user, $companyId)) {
            return false;
        }

        return self::isTeam($user) || self::productionMode($companyId) === self::MODE_SELF;
    }

    public static function productionMode(int $companyId): string
    {
        $mode = (string) (Company::whereKey($companyId)->value('content_production_mode') ?? self::MODE_SELF);

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_SELF;
    }

    /** 403 com a explicação quando o cliente gerido pela equipa tenta produzir. */
    public static function assertProducer(User $user, int $companyId): void
    {
        if (self::isProducer($user, $companyId)) {
            return;
        }
        throw new HttpException(403, self::isMember($user, $companyId) ? self::MSG_TEAM_PRODUCES : 'Acesso negado.');
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
        if ($post->channel === 'site' || ! self::isMember($user, (int) $post->company_id)) {
            return [];
        }
        $company ??= Company::findOrFail($post->company_id);
        if ($company->content_production_mode === self::MODE_TEAM && ! self::isTeam($user)) {
            return []; // cliente gerido pela equipa: não muda etapas
        }
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
            // F3d: publicar exige o link e a hora real (ação "Marcar como publicada").
            EditorialPost::STAGE_SCHEDULED => [EditorialPost::STAGE_PUBLISHED => self::MSG_MARK_PUBLISHED],
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
        self::assertProducer($user, (int) $post->company_id);

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
            if (in_array($to, self::SEND_STAGES, true) && ($mediaErrors = self::mediaValidation($version)['errors'])) {
                throw new HttpException(422, 'Corrija os ficheiros antes de enviar: ' . $mediaErrors[0]);
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
            $message = $message ? trim($message) : null;
            $this->applyDecision($post, $version, EditorialPostReview::APPROVED, $message,
                ['via' => 'app', 'user_id' => $user->id, 'reviewer_name' => $user->name], $user,
                'Aprovada a versão ' . $version->number . ($message ? ': ' . $message : '.'));

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
            $this->applyDecision($post, $version, EditorialPostReview::CHANGES_REQUESTED, $message,
                ['via' => 'app', 'user_id' => $user->id, 'reviewer_name' => $user->name], $user,
                'Alterações pedidas na versão ' . $version->number . ': ' . $message);

            return $post->fresh();
        });
    }

    // ── Aprovação pelo link (F3c, sem conta) ─────────────────────────────────

    public const MSG_MARK_PUBLISHED = 'Use "Marcar como publicada", com o link e a hora da publicação.';

    public const MSG_LINK_OUTDATED = 'A equipa atualizou esta publicação. Aguarde que a volte a enviar neste link.';
    public const MSG_LINK_DECIDED = 'Já foi registada uma decisão para esta versão.';

    /**
     * Aprovar ou pedir alterações pelo link de aprovação, sobre a versão enviada no lote.
     * Uma decisão por versão; se a equipa mudou a publicação depois do envio, 409.
     * Chamar dentro de uma transação (o "Aprovar tudo" junta várias numa só).
     */
    public function decideViaLink(EditorialPost $post, int $versionId, int $linkId, string $decision, string $name, ?string $message, ?string $device): EditorialPost
    {
        $name = trim($name);
        $message = $message !== null ? trim($message) : null;
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Indique o seu nome.']]);
        }
        if ($decision === EditorialPostReview::CHANGES_REQUESTED && ($message === null || $message === '')) {
            throw ValidationException::withMessages(['message' => ['Escreva o que deve ser alterado.']]);
        }

        return DB::transaction(function () use ($post, $versionId, $linkId, $decision, $name, $message, $device) {
            $post = EditorialPost::lockForUpdate()->findOrFail($post->id);
            if (EditorialPostReview::where('version_id', $versionId)->exists()) {
                throw new HttpException(409, self::MSG_LINK_DECIDED);
            }
            $version = $post->currentVersion;
            if ($post->stage !== EditorialPost::STAGE_CLIENT_REVIEW || ! $version || $version->id !== $versionId || $version->status !== EditorialPostVersion::SENT) {
                throw new HttpException(409, self::MSG_LINK_OUTDATED);
            }

            $approved = $decision === EditorialPostReview::APPROVED;
            $this->applyDecision($post, $version, $decision, $message ?: null,
                ['via' => 'link', 'review_link_id' => $linkId, 'user_id' => null, 'reviewer_name' => mb_substr($name, 0, 120), 'device' => $device], null,
                ($approved ? 'Aprovada a versão ' : 'Alterações pedidas na versão ') . $version->number . " no link de aprovação, por {$name}"
                    . ($message ? ': ' . $message : '.'));

            return $post->fresh();
        });
    }

    /** Comentário partilhado escrito no link de aprovação (sem conta). */
    public function commentViaLink(EditorialPost $post, int $versionId, int $linkId, string $name, string $body): EditorialPostComment
    {
        $name = trim($name);
        $body = trim($body);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Indique o seu nome.']]);
        }
        if ($body === '') {
            throw ValidationException::withMessages(['body' => ['Escreva o comentário.']]);
        }
        $comment = EditorialPostComment::create([
            'company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'version_id' => $versionId,
            'user_id' => null, 'impersonator_user_id' => null, 'review_link_id' => $linkId,
            'author_name' => mb_substr($name, 0, 120), 'body' => mb_substr($body, 0, 3000), 'visibility' => EditorialPostComment::SHARED,
        ]);
        $this->recordEvent($post, null, 'comment', null, null, $versionId, "Comentário no link de aprovação, por {$name}.");

        return $comment;
    }

    /** Grava a decisão e muda a versão e a etapa (aprovada: Programado; alterações: Produção). */
    private function applyDecision(EditorialPost $post, EditorialPostVersion $version, string $decision, ?string $message, array $who, ?User $eventUser, string $eventMessage): void
    {
        $approved = $decision === EditorialPostReview::APPROVED;
        try {
            EditorialPostReview::create($who + [
                'company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'version_id' => $version->id,
                'decision' => $decision, 'message' => $message,
                'approved_version_id' => $approved ? $version->id : null, 'created_at' => now(),
            ]);
        } catch (QueryException) {
            throw new HttpException(409, 'Esta versão já foi aprovada.');
        }

        if ($approved) {
            $version->forceFill(['status' => EditorialPostVersion::APPROVED])->save();
            $post->forceFill([
                'stage' => EditorialPost::STAGE_SCHEDULED, 'stage_changed_at' => now(),
                'approved_version_id' => $version->id, 'changes_requested_at' => null,
            ])->save();
        } else {
            $version->forceFill(['status' => EditorialPostVersion::CHANGES_REQUESTED])->save();
            $post->forceFill(['stage' => EditorialPost::STAGE_PRODUCTION, 'stage_changed_at' => now(), 'changes_requested_at' => now()])->save();
        }
        // Sem utilizador (link): o histórico não deve cair no utilizador autenticado.
        $this->recordEvent($post, $eventUser, 'review', EditorialPost::STAGE_CLIENT_REVIEW,
            $approved ? EditorialPost::STAGE_SCHEDULED : EditorialPost::STAGE_PRODUCTION, $version->id, $eventMessage);
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
        self::assertProducer($user, (int) $post->company_id);
        $data = $this->validateContent($post, $data);

        return DB::transaction(function () use ($post, $user, $data) {
            $version = $this->draftVersion($post, $user, $data);
            $version->forceFill($data + $this->actorFields($user))->save();

            return $version->fresh();
        });
    }

    /**
     * Media da versão em edição: os itens por ordem (carrossel: 2 a 10, imagens e vídeos) e
     * a capa do vídeo. Os media têm de ser da empresa. Numa versão congelada, cria a seguinte.
     */
    public function setMedia(EditorialPost $post, User $user, array $itemIds, ?int $coverId): EditorialPostVersion
    {
        $this->assertNotSite($post);
        self::assertProducer($user, (int) $post->company_id);
        $itemIds = array_values(array_map('intval', $itemIds));
        if (count($itemIds) > 10) {
            throw ValidationException::withMessages(['items' => ['No máximo 10 ficheiros por publicação.']]);
        }
        if (count($itemIds) !== count(array_unique($itemIds))) {
            throw ValidationException::withMessages(['items' => ['O mesmo ficheiro aparece duas vezes.']]);
        }
        $ids = array_filter([...$itemIds, $coverId]);
        $assets = MediaAsset::where('company_id', $post->company_id)->whereIn('id', $ids)->get()->keyBy('id');
        if ($assets->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => ['Ficheiro não encontrado.']]);
        }
        if ($coverId && $assets[$coverId]->kind !== MediaAsset::IMAGE) {
            throw ValidationException::withMessages(['cover' => ['A capa tem de ser uma imagem.']]);
        }

        return DB::transaction(function () use ($post, $user, $itemIds, $coverId) {
            $version = $this->draftVersion($post, $user);
            DB::table('editorial_post_version_media')->where('version_id', $version->id)->delete();
            $now = now();
            $rows = array_map(fn ($id, $i) => ['version_id' => $version->id, 'media_asset_id' => $id, 'position' => $i, 'role' => 'item', 'created_at' => $now, 'updated_at' => $now], $itemIds, array_keys($itemIds));
            if ($coverId) {
                $rows[] = ['version_id' => $version->id, 'media_asset_id' => $coverId, 'position' => 0, 'role' => 'cover', 'created_at' => $now, 'updated_at' => $now];
            }
            if ($rows) {
                DB::table('editorial_post_version_media')->insert($rows);
            }
            $version->forceFill($this->actorFields($user))->save();

            return $version->fresh();
        });
    }

    /**
     * A versão em edição (dentro da transação, com a publicação bloqueada): a atual se ainda
     * não está congelada; senão a seguinte, com o texto e os media copiados. Se estava em
     * Aprovação ou Programado, a publicação volta a Produção (nova aprovação).
     */
    private function draftVersion(EditorialPost $post, User $user, array $data = []): EditorialPostVersion
    {
        $post = EditorialPost::lockForUpdate()->findOrFail($post->id);
        if (in_array($post->stage, self::LOCKED_STAGES, true)) {
            throw new HttpException(409, 'Uma publicação já publicada não se altera.');
        }

        $current = $post->currentVersion;
        if ($current && ! $current->isFrozen()) {
            return $current;
        }

        // Primeira versão (a partir do criativo aceite) ou a seguinte a uma congelada.
        $base = $current ? $current->only(EditorialPostVersion::CONTENT_FIELDS) : $this->fromCreative($post);
        $impersonator = self::impersonatorId($user);
        $version = EditorialPostVersion::create($data + $base + $this->actorFields($user) + [
            'company_id' => $post->company_id, 'editorial_post_id' => $post->id,
            'number' => (int) EditorialPostVersion::where('editorial_post_id', $post->id)->max('number') + 1,
            'status' => EditorialPostVersion::DRAFT,
            'created_by_user_id' => $user->id, 'impersonator_user_id' => $impersonator,
        ]);
        if ($current) {
            $now = now();
            $copy = DB::table('editorial_post_version_media')->where('version_id', $current->id)->get()
                ->map(fn ($r) => ['version_id' => $version->id, 'media_asset_id' => $r->media_asset_id, 'position' => $r->position, 'role' => $r->role, 'created_at' => $now, 'updated_at' => $now])->all();
            if ($copy) {
                DB::table('editorial_post_version_media')->insert($copy);
            }
            if (in_array($current->status, [EditorialPostVersion::SENT, EditorialPostVersion::CHANGES_REQUESTED], true)) {
                $current->forceFill(['status' => EditorialPostVersion::SUPERSEDED])->save();
            }
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
    }

    private function actorFields(User $user): array
    {
        return ['updated_by_user_id' => $user->id, 'updated_by_impersonator_id' => self::impersonatorId($user)];
    }

    // ── Validação dos media por formato ──────────────────────────────────────

    /**
     * Erros (bloqueiam o envio) e avisos dos media de uma versão, pelo formato escolhido.
     * Sem formato escolhido, só avisa. Limites da Meta conhecidos; os contraditórios na
     * documentação ficam pelo valor mais conservador.
     *
     * @return array{errors: string[], warnings: string[]}
     */
    public static function mediaValidation(?EditorialPostVersion $version): array
    {
        if (! $version) {
            return ['errors' => [], 'warnings' => []];
        }
        $rows = DB::table('editorial_post_version_media')->where('version_id', $version->id)->orderBy('position')->get();
        $assets = MediaAsset::whereIn('id', $rows->pluck('media_asset_id'))->get()->keyBy('id');
        $items = $rows->where('role', 'item')->map(fn ($r) => $assets[$r->media_asset_id] ?? null)->filter()->values();
        $cover = $rows->firstWhere('role', 'cover');
        $errors = [];
        $warnings = [];

        foreach ($items as $i => $a) {
            if ($a->status === MediaAsset::PROCESSING) {
                $errors[] = 'O ficheiro ' . ($i + 1) . ' ainda está a ser processado.';
            } elseif ($a->status === MediaAsset::REJECTED) {
                $errors[] = 'O ficheiro ' . ($i + 1) . ' não é válido: ' . $a->error;
            }
        }

        $format = $version->media_format;
        if (! $format) {
            if ($items->isNotEmpty()) {
                $warnings[] = 'Escolha o formato para validar os ficheiros.';
            }

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $n = $items->count();
        $images = $items->where('kind', MediaAsset::IMAGE)->count();
        $videos = $items->where('kind', MediaAsset::VIDEO)->count();
        $video = $items->firstWhere('kind', MediaAsset::VIDEO);
        $sec = $video ? $video->duration_ms / 1000 : 0;
        $isVertical = fn (MediaAsset $a) => $a->ratio() !== null && abs($a->ratio() - 9 / 16) < 0.02;
        $one = fn (string $what) => $n !== 1 ? "Este formato leva {$what}." : null;

        $rules = match ($format) {
            'ig_feed_image' => [$one('uma imagem'), $videos ? 'Este formato leva uma imagem, não vídeo.' : null,
                $n === 1 && $images && ($items[0]->ratio() < 0.8 || $items[0]->ratio() > 1.91) ? 'A proporção tem de estar entre 4:5 (vertical) e 1,91:1 (horizontal).' : null],
            'ig_carousel' => [$n < 2 || $n > 10 ? 'O carrossel leva entre 2 e 10 ficheiros.' : null,
                $items->first(fn ($a) => $a->kind === MediaAsset::VIDEO && $a->duration_ms > 60000) ? 'Os vídeos do carrossel podem ter até 60 segundos.' : null],
            'ig_reel' => [$one('um vídeo'), $images ? 'Um Reel leva um vídeo.' : null,
                $video && ($sec < 3 || $sec > 900) ? 'Um Reel tem de ter entre 3 segundos e 15 minutos.' : null],
            'ig_story', 'fb_story' => [$one('uma imagem ou um vídeo'),
                $video && $sec > 60 ? 'Os vídeos das Stories podem ter até 60 segundos.' : null],
            'fb_post' => [$n > 1 ? 'Uma publicação de texto ou ligação leva no máximo uma imagem.' : null,
                $videos ? 'Para vídeo, escolha "Vídeo" ou "Reel".' : null],
            'fb_photos' => [$n < 1 || $n > 10 ? 'Leva entre 1 e 10 fotografias.' : null, $videos ? 'Só fotografias neste formato.' : null],
            'fb_video' => [$one('um vídeo'), $images ? 'Este formato leva um vídeo.' : null],
            'fb_reel' => [$one('um vídeo'), $images ? 'Um Reel leva um vídeo.' : null,
                $video && ($sec < 3 || $sec > 90) ? 'Um Reel do Facebook tem de ter entre 3 e 90 segundos.' : null],
            default => [],
        };
        $errors = array_merge($errors, array_values(array_filter($rules)));

        // Avisos: proporções recomendadas e o recorte do carrossel.
        if (in_array($format, ['ig_reel', 'ig_story', 'fb_reel', 'fb_story'], true) && $n === 1 && ! $isVertical($items[0])) {
            $warnings[] = 'Recomendado 9:16 (vertical, 1080 x 1920). Fora disso a rede corta ou põe margens.';
        }
        if ($format === 'ig_carousel' && $n >= 2) {
            $warnings[] = 'No carrossel, todos os ficheiros são cortados pela proporção do primeiro.';
        }
        if ($video && $video->codec && ! in_array($video->codec, ['h264', 'hevc'], true)) {
            $warnings[] = "O vídeo usa o codec {$video->codec}; o recomendado é H.264.";
        }
        if ($cover && ! in_array($format, ['ig_reel', 'fb_reel', 'fb_video'], true)) {
            $warnings[] = 'A capa só é usada em Reels e vídeos.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** Media de uma versão para os ecrãs (com URLs assinados). */
    public static function presentMedia(?EditorialPostVersion $version): array
    {
        if (! $version) {
            return ['items' => [], 'cover' => null];
        }
        $rows = DB::table('editorial_post_version_media')->where('version_id', $version->id)->orderBy('role')->orderBy('position')->get();
        $assets = MediaAsset::whereIn('id', $rows->pluck('media_asset_id'))->get()->keyBy('id');
        $cover = $rows->firstWhere('role', 'cover');

        return [
            'items' => $rows->where('role', 'item')->map(fn ($r) => isset($assets[$r->media_asset_id]) ? $assets[$r->media_asset_id]->present() : null)->filter()->values()->all(),
            'cover' => $cover && isset($assets[$cover->media_asset_id]) ? $assets[$cover->media_asset_id]->present() : null,
        ];
    }

    // ── Comentários ──────────────────────────────────────────────────────────

    public function addComment(EditorialPost $post, User $user, string $body, string $visibility): EditorialPostComment
    {
        if (! self::isMember($user, (int) $post->company_id)) {
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
        $this->recordEvent($post, $user ?? Auth::user(), $type, $from, $to, $versionId, $message);
    }

    /** Como event(), mas sem utilizador quando não há (ações do link de aprovação). */
    private function recordEvent(EditorialPost $post, ?User $user, string $type, ?string $from, ?string $to, ?int $versionId, ?string $message): void
    {
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
                'account_name' => $company->trade_name ?: $company->fiscal_name,
                'publish_time' => $post->publish_time, 'pillar' => $post->pillar, 'overdue' => $post->isOverdue(),
            ],
            'versions' => $versions->map(fn (EditorialPostVersion $v) => [
                'id' => $v->id, 'number' => $v->number, 'status' => $v->status, 'frozen' => $v->isFrozen(),
                'caption' => $v->caption, 'hashtags' => $v->hashtags ?? [], 'cta' => $v->cta, 'first_comment' => $v->first_comment,
                'media_format' => $v->media_format,
                'media' => self::presentMedia($v),
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
            'media_validation' => self::mediaValidation($versions->firstWhere('id', $post->current_version_id)),
            'creative' => $creative ? ['caption' => $creative->caption, 'hashtags' => $creative->hashtags ?? [], 'cta' => $creative->cta, 'media_format' => $creative->media_format] : null,
            'moves' => $this->moves($post, $user, $company),
            // F3d: publicação (link, hora real e quem marcou) e resultados à mão.
            'publishing' => [
                'url' => $post->published_url,
                'published_at' => optional($post->published_at)->toIso8601String(),
                'by' => $post->published_by_user_id ? $this->label($people + User::whereIn('id', array_filter([$post->published_by_user_id, $post->published_by_impersonator_id]))->get(['id', 'name', 'role'])->keyBy('id')->all(),
                    $post->published_by_user_id, $post->published_by_impersonator_id) : null,
                'due_at' => $post->dueAt()->toIso8601String(),
                'can_mark' => self::isProducer($user, (int) $post->company_id) && $post->channel !== 'site'
                    && in_array($post->stage, [EditorialPost::STAGE_SCHEDULED, EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS], true),
            ],
            'results' => $post->channel !== 'site' && in_array($post->stage, [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS], true)
                ? EditorialPublishingService::metrics($post) + [
                    'worked' => $post->analysis_worked, 'change' => $post->analysis_change,
                    'can_record' => self::isProducer($user, (int) $post->company_id),
                ] : null,
            'permissions' => [
                'can_edit_content' => self::isProducer($user, (int) $post->company_id) && $post->channel !== 'site' && ! in_array($post->stage, self::LOCKED_STAGES, true),
                'can_approve' => self::isApprover($user, (int) $post->company_id) && $post->stage === EditorialPost::STAGE_CLIENT_REVIEW,
                'is_approver' => self::isApprover($user, (int) $post->company_id),
                'is_team' => $team,
                'can_produce' => self::isProducer($user, (int) $post->company_id),
            ],
            'settings' => [
                'content_approval_required' => (bool) $company->content_approval_required,
                'internal_review_required' => (bool) $company->internal_review_required,
                'production_mode' => self::productionMode($company->id),
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
