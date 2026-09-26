<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\EditorialOwnAnchor;
use App\Models\EditorialPost;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Linha Editorial (Publicações P1): CRUD dos posts. Serviço dedicado; reutiliza
 * o EditorialLineService como fonte única da regra "mês aberto + na janela" (assertDateEditable)
 * e da validação de âncora herdada (inheritedAnchorOrFail). Todas as operações validam
 * tenancy + mês aberto. Espaço de id distinto das âncoras.
 */
class EditorialPostService
{
    public function __construct(private readonly EditorialLineService $line) {}

    /** Cria uma publicação (Modelo C: âncora opcional). Devolve o calendar() atualizado. */
    public function createPost(Company $company, array $data): array
    {
        $clean = $this->validateInput($data);
        $this->assertLinkable($company, $clean['anchor_id'] ?? null, $clean['own_anchor_id'] ?? null);
        $this->line->assertDateEditable($company, $clean['publish_date']);

        EditorialPost::create(array_merge($clean, ['company_id' => $company->id]));

        return $this->line->calendar($company);
    }

    /** Edita uma publicação. Mês aberto exigido na data ANTIGA E na NOVA (se mudar de mês). */
    public function updatePost(Company $company, int $postId, array $data): array
    {
        $post = EditorialPost::where('company_id', $company->id)->find($postId);
        if (! $post) {
            throw ValidationException::withMessages(['post' => ['Publicação não encontrada.']]);
        }

        $clean = $this->validateInput($data);
        $this->assertLinkable($company, $clean['anchor_id'] ?? null, $clean['own_anchor_id'] ?? null);

        $this->line->assertDateEditable($company, $post->publish_date->toDateString()); // data antiga
        $this->line->assertDateEditable($company, $clean['publish_date']);              // data nova

        $post->update($clean);

        return $this->line->calendar($company);
    }

    /** Apaga uma publicação (só em mês aberto). Tenancy: só posts da empresa. */
    public function deletePost(Company $company, int $postId): array
    {
        $post = EditorialPost::where('company_id', $company->id)->find($postId);
        if (! $post) {
            throw ValidationException::withMessages(['post' => ['Publicação não encontrada.']]);
        }

        $this->line->assertDateEditable($company, $post->publish_date->toDateString());
        $post->delete();

        return $this->line->calendar($company);
    }

    // ── helpers ──

    /** Valida campos por enum + formato; devolve só as chaves relevantes (com links nulos por omissão). */
    private function validateInput(array $data): array
    {
        $validated = Validator::make($data, [
            'title'         => ['required', 'string', 'max:255'],
            'publish_date'  => ['required', 'date'],
            'format'        => ['required', Rule::in(EditorialPost::FORMATS)],
            'status'        => ['required', Rule::in(EditorialPost::STATUSES)],
            'channel'       => ['required', Rule::in(EditorialPost::CHANNELS)],
            'keyword'       => ['nullable', 'string', 'max:255'],
            'anchor_id'     => ['nullable', 'integer'],
            'own_anchor_id' => ['nullable', 'integer'],
        ])->validate();

        // Normaliza a data para Y-m-d.
        $validated['publish_date'] = \Carbon\CarbonImmutable::parse($validated['publish_date'])->toDateString();

        return array_merge(
            ['keyword' => null, 'anchor_id' => null, 'own_anchor_id' => null],
            $validated
        );
    }

    /**
     * Valida a ligação opcional: no máximo UMA âncora; se herdada, tem de ser herdada por
     * esta empresa; se própria, tem de pertencer à empresa. (Espaços de id não se misturam.)
     */
    private function assertLinkable(Company $company, ?int $anchorId, ?int $ownAnchorId): void
    {
        if ($anchorId !== null && $ownAnchorId !== null) {
            throw ValidationException::withMessages([
                'anchor' => ['Liga a publicação a UMA âncora (herdada ou própria), não a ambas.'],
            ]);
        }

        if ($anchorId !== null) {
            $this->line->inheritedAnchorOrFail($company, $anchorId); // 422 se não herdada
        }

        if ($ownAnchorId !== null) {
            $exists = EditorialOwnAnchor::where('company_id', $company->id)->whereKey($ownAnchorId)->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['own_anchor' => ['Âncora própria inválida.']]);
            }
        }
    }
}
