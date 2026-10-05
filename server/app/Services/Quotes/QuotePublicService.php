<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\QuoteOpen;
use App\Models\QuotePublicLink;
use App\Models\QuoteResponse;
use App\Models\QuoteVersion;
use App\Support\BotUserAgent;
use App\Support\TeamDeviceMarker;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Página pública de um orçamento (link de uma versão enviada, sem login).
 *
 *  · Só mostra a versão do link, exatamente como foi congelada (a mesma do PDF).
 *  · Estado honesto: em aberto, expirado, existe versão mais recente, em revisão,
 *    aceite ou recusado. Aceitar, recusar e pedir alterações só valem na versão em
 *    vigor, enviada e dentro da validade (dia de Lisboa).
 *  · Aberturas: só o sinal enviado pela página depois de carregar; robôs e equipa não
 *    contam; a mesma visita (mesmo browser) dentro de 30 minutos é a mesma abertura;
 *    aviso à equipa na primeira abertura e numa nova, no máximo um a cada 6 horas.
 */
class QuotePublicService
{
    public const STATE_OPEN = 'open';
    public const STATE_EXPIRED = 'expired';
    public const STATE_SUPERSEDED = 'superseded';
    public const STATE_UNDER_REVISION = 'under_revision';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_REFUSED = 'refused';

    public const ALERT_EVERY_HOURS = 6;

    private const STATE_MESSAGES = [
        self::STATE_EXPIRED => 'Este orçamento expirou.',
        self::STATE_SUPERSEDED => 'Existe uma versão mais recente deste orçamento.',
        self::STATE_UNDER_REVISION => 'Este orçamento está a ser revisto pela equipa.',
        self::STATE_ACCEPTED => 'Este orçamento já foi aceite.',
        self::STATE_REFUSED => 'Este orçamento já foi recusado.',
    ];

    public function __construct(
        private readonly QuotePdfPresenter $presenter,
        private readonly QuoteOnboardingService $onboarding,
    ) {}

    /** O link do token, ou 404 (sem distinguir inexistente de revogado). */
    public function resolve(string $token): QuotePublicLink
    {
        $link = QuotePublicLink::findByToken($token);
        if (! $link) {
            throw new HttpException(404, 'Orçamento não encontrado.');
        }

        return $link->load(['quote', 'version']);
    }

    public function state(Quote $quote, QuoteVersion $version): string
    {
        $acceptedHere = QuoteResponse::where('accepted_version_id', $version->id)->exists();
        if ($acceptedHere || ($quote->status === 'accepted' && (int) $quote->version === (int) $version->version)) {
            return self::STATE_ACCEPTED;
        }
        if ((int) $quote->version > (int) $version->version) {
            return QuoteVersion::where('quote_id', $quote->id)->where('version', '>', $version->version)->exists()
                ? self::STATE_SUPERSEDED
                : self::STATE_UNDER_REVISION;
        }
        if ($quote->status === 'refused') {
            return self::STATE_REFUSED;
        }
        $today = CarbonImmutable::now(Quote::TIMEZONE)->toDateString();
        if ($quote->status === 'expired' || ($quote->status === 'sent' && $quote->valid_until && $quote->valid_until->toDateString() < $today)) {
            return self::STATE_EXPIRED;
        }

        return $quote->status === 'sent' ? self::STATE_OPEN : self::STATE_UNDER_REVISION;
    }

    /** O que a página pública mostra: a versão congelada, o estado e a resposta (se houver). */
    public function payload(QuotePublicLink $link, bool $preview = false): array
    {
        $quote = $link->quote;
        $version = $link->version;
        $snapshot = $version->snapshot;
        $state = $this->state($quote, $version);

        $document = $this->presenter->present($snapshot);
        unset($document['assets']);
        $document['acceptance_note'] = null;

        $accepted = QuoteResponse::where('accepted_version_id', $version->id)->first();
        $legal = config('legal');

        return [
            'preview' => $preview,
            'state' => $state,
            'state_message' => self::STATE_MESSAGES[$state] ?? null,
            'can_respond' => ! $preview && $state === self::STATE_OPEN,
            'number' => $version->number,
            'version' => (int) $version->version,
            'valid_until' => $version->valid_until?->toDateString(),
            'document' => $document,
            'lines' => array_values(array_map(fn ($l) => $l + ['line_total' => $this->lineTotal($snapshot, $l['key'])], QuoteCalculator::keyedLines($snapshot))),
            'buckets' => $snapshot['buckets'] ?? null,
            'global_discount' => $snapshot['global_discount'] ?? null,
            'has_optional' => collect(QuoteCalculator::keyedLines($snapshot))->contains(fn ($l) => $l['is_optional']),
            'changes_requested' => QuoteResponse::where('quote_version_id', $version->id)->where('type', QuoteResponse::CHANGES_REQUESTED)->exists(),
            'acceptance' => $accepted ? [
                'name' => $accepted->name,
                'accepted_at' => $accepted->created_at?->toIso8601String(),
                'accepted_keys' => $accepted->accepted_line_keys,
                'buckets' => $accepted->selection['buckets'] ?? null,
                'discount' => $accepted->selection['discount'] ?? null,
            ] : null,
            'contact' => ['brand' => $legal['brand'] ?? 'XPLENDOR', 'email' => $legal['email'] ?? null, 'website' => $legal['website'] ?? null],
        ];
    }

    /** PDF congelado da versão do link. */
    public function pdf(QuotePublicLink $link): string
    {
        return app(\App\Services\QuoteService::class)->versionPdf($link->version);
    }

    // ── Aberturas ──────────────────────────────────────────────────────────

    /**
     * Sinal de abertura (enviado pela página depois de carregar). Devolve se contou e,
     * se não, porquê (robô, equipa ou a mesma visita).
     */
    public function recordOpen(QuotePublicLink $link, Request $request): array
    {
        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'team_marker' => ['nullable', 'string', 'max:300'],
        ]);
        $userAgent = (string) $request->userAgent();
        if (BotUserAgent::isBot($userAgent)) {
            return ['counted' => false, 'reason' => 'bot'];
        }
        $viewer = auth('sanctum')->user();
        if (TeamDeviceMarker::verify($data['team_marker'] ?? null) || ($viewer && $viewer->role === 'root')) {
            return ['counted' => false, 'reason' => 'team'];
        }

        $visitorHash = hash('sha256', $link->id . '|' . $data['visitor_id']);
        $device = BotUserAgent::device($userAgent);

        [$counted, $alert, $first] = DB::transaction(function () use ($link, $visitorHash, $device) {
            $quote = Quote::whereKey($link->quote_id)->lockForUpdate()->firstOrFail();
            $now = now();
            $same = QuoteOpen::where('quote_public_link_id', $link->id)->where('visitor_hash', $visitorHash)
                ->where('last_seen_at', '>=', $now->copy()->subMinutes(QuoteOpen::SAME_VISIT_MINUTES))
                ->orderByDesc('last_seen_at')->first();
            if ($same) {
                $same->update(['last_seen_at' => $now]);

                return [false, false, false];
            }

            QuoteOpen::create([
                'quote_id' => $quote->id, 'quote_version_id' => $link->quote_version_id, 'quote_public_link_id' => $link->id,
                'visitor_hash' => $visitorHash, 'device' => $device, 'opened_at' => $now, 'last_seen_at' => $now,
            ]);
            $first = $quote->open_count === 0;
            $alert = ! $quote->last_open_alert_at || $quote->last_open_alert_at->lte($now->copy()->subHours(self::ALERT_EVERY_HOURS));
            $quote->forceFill([
                'open_count' => $quote->open_count + 1,
                'first_opened_at' => $quote->first_opened_at ?? $now,
                'last_opened_at' => $now,
                'last_open_alert_at' => $alert ? $now : $quote->last_open_alert_at,
            ])->save();

            return [true, $alert, $first];
        });

        if ($alert) {
            $quote = $link->quote->fresh();
            $device = $device === QuoteOpen::DEVICE_MOBILE ? 'num telemóvel' : 'num computador';
            $this->onboarding->alertTeam($quote, 'opportunity',
                $first ? "Orçamento aberto pela primeira vez: {$quote->displayNumber()}" : "Orçamento aberto de novo: {$quote->displayNumber()}",
                "{$quote->client_name} abriu o orçamento {$device}. Aberto {$quote->open_count} " . ($quote->open_count === 1 ? 'vez' : 'vezes') . '.',
                'low');
        }

        return ['counted' => $counted, 'reason' => $counted ? null : 'same_visit'];
    }

    // ── Respostas do cliente ───────────────────────────────────────────────

    public function accept(QuotePublicLink $link, Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'terms_accepted' => ['accepted'],
            'optional_keys' => ['present', 'array', 'max:100'],
            'optional_keys.*' => ['integer', 'min:0'],
        ], [
            'name.required' => 'Indique o seu nome.',
            'email.required' => 'Indique o seu email.',
            'email.email' => 'Indique um email válido.',
            'terms_accepted.accepted' => 'Para aceitar, confirme que leu e aceita as condições.',
        ]);

        $result = DB::transaction(function () use ($link, $data, $request) {
            [$quote, $version] = $this->lockOpen($link);
            $selection = QuoteCalculator::computeSelection($version->snapshot, $data['optional_keys']);
            $afterChanges = QuoteResponse::where('quote_version_id', $version->id)->where('type', QuoteResponse::CHANGES_REQUESTED)->exists();

            try {
                QuoteResponse::create([
                    'quote_id' => $quote->id, 'quote_version_id' => $version->id, 'accepted_version_id' => $version->id,
                    'type' => QuoteResponse::ACCEPTED, 'name' => trim($data['name']), 'email' => mb_strtolower(trim($data['email'])),
                    'terms_accepted' => true, 'accepted_line_keys' => $selection['accepted_keys'],
                    'selection' => ['buckets' => $selection['buckets'], 'discount' => $selection['discount'], 'excluded_keys' => $selection['excluded_keys'],
                        'lines' => array_map(fn ($l) => ['key' => $l['key'], 'name' => $l['name'], 'billing_type' => $l['billing_type'], 'line_total' => $l['line_total']], $selection['lines'])],
                    'after_changes_request' => $afterChanges, 'device' => BotUserAgent::device($request->userAgent()),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new HttpException(409, self::STATE_MESSAGES[self::STATE_ACCEPTED]);
            }

            $quote->update([
                'status' => 'accepted', 'decided_at' => now(),
                'accepted_total_monthly' => $selection['buckets']['monthly']['total'],
                'accepted_total_one_off' => $selection['buckets']['one_off']['total'],
            ]);

            return [$quote, $selection, $afterChanges, count(QuoteCalculator::keyedLines($version->snapshot))];
        });

        [$quote, $selection, $afterChanges, $totalLines] = $result;
        $this->onboarding->onAccepted($quote, $selection['lines'],
            ['monthly' => $selection['buckets']['monthly']['total'], 'one_off' => $selection['buckets']['one_off']['total']], $afterChanges, $totalLines);

        return $this->payload($link->fresh(['quote', 'version']));
    }

    public function refuse(QuotePublicLink $link, Request $request): array
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $quote = DB::transaction(function () use ($link, $data, $request) {
            [$quote, $version] = $this->lockOpen($link);
            QuoteResponse::create([
                'quote_id' => $quote->id, 'quote_version_id' => $version->id, 'type' => QuoteResponse::REFUSED,
                'message' => isset($data['reason']) ? trim($data['reason']) : null, 'device' => BotUserAgent::device($request->userAgent()),
            ]);
            $quote->update(['status' => 'refused', 'decided_at' => now()]);

            return $quote;
        });

        $reason = isset($data['reason']) && trim($data['reason']) !== '' ? ' Motivo: ' . mb_substr(trim($data['reason']), 0, 300) : ' Sem motivo indicado.';
        $this->onboarding->alertTeam($quote, 'warning', "Orçamento recusado: {$quote->displayNumber()}", "{$quote->client_name} recusou o orçamento.{$reason}", 'high');

        return $this->payload($link->fresh(['quote', 'version']));
    }

    public function requestChanges(QuotePublicLink $link, Request $request): array
    {
        $data = $request->validate(
            ['message' => ['required', 'string', 'min:3', 'max:3000']],
            ['message.required' => 'Escreva o que gostaria de alterar.', 'message.min' => 'Escreva o que gostaria de alterar.']
        );

        $quote = DB::transaction(function () use ($link, $data, $request) {
            [$quote, $version] = $this->lockOpen($link);
            QuoteResponse::create([
                'quote_id' => $quote->id, 'quote_version_id' => $version->id, 'type' => QuoteResponse::CHANGES_REQUESTED,
                'message' => trim($data['message']), 'device' => BotUserAgent::device($request->userAgent()),
            ]);
            $quote->forceFill(['changes_requested_at' => now()])->save();

            return $quote;
        });

        $this->onboarding->alertTeam($quote, 'warning', "Pedido de alterações: {$quote->displayNumber()}",
            "{$quote->client_name} pediu alterações: " . mb_substr(trim($data['message']), 0, 400), 'high');

        return $this->payload($link->fresh(['quote', 'version']));
    }

    /**
     * Bloqueia o orçamento e confirma que a versão do link é a que está em vigor, enviada
     * e dentro da validade. Senão, 409 com a mensagem do estado.
     */
    private function lockOpen(QuotePublicLink $link): array
    {
        $quote = Quote::whereKey($link->quote_id)->lockForUpdate()->firstOrFail();
        $version = QuoteVersion::findOrFail($link->quote_version_id);
        $state = $this->state($quote, $version);
        if ($state !== self::STATE_OPEN) {
            throw new HttpException(409, self::STATE_MESSAGES[$state] ?? 'Este orçamento já não aceita respostas.');
        }

        return [$quote, $version];
    }

    private function lineTotal(array $snapshot, int $key): ?float
    {
        foreach (array_values($snapshot['lines'] ?? []) as $i => $l) {
            if ((int) ($l['key'] ?? $i) === $key) {
                return isset($l['line_total']) ? (float) $l['line_total'] : null;
            }
        }

        return null;
    }
}
