<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Models\CompanyIntegration;
use App\Models\CompanyManagement;
use App\Models\CompanySetupLink;
use App\Models\CompanySetupLinkOpen;
use App\Models\SocialConnection;
use App\Services\ContentReview\ContentReviewPresenter;
use App\Services\Ga4\Ga4AccessChecker;
use App\Services\Ga4\Ga4ConnectionService;
use App\Services\Integrations\MetaAdsConnectionService;
use App\Services\PublicLinks\PublicLinkOpens;
use App\Services\Social\SocialConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * O lado do cliente do link de configuração (sem conta): mostra a página, regista as
 * aberturas e conduz cada passo, reutilizando os fluxos e as tabelas existentes (redes
 * sociais, anúncios da Meta, GA4). Cada passo pode ser refeito enquanto o link for válido.
 * Quando todos os passos escolhidos ficam feitos pela primeira vez, avisa a agência (ou a
 * empresa) e marca as tarefas do ticket de arranque pela chave.
 */
class SetupPublicService
{
    public const PRIVACY_URL = 'https://xplendor.tech/politica-de-privacidade/';
    public const STALL_MINUTES = 30;

    public function __construct(
        private readonly SocialConnectionService $social,
        private readonly MetaAdsConnectionService $ads,
        private readonly Ga4AccessChecker $ga4Checker,
        private readonly Ga4ConnectionService $ga4,
        private readonly PublicLinkOpens $opens,
        private readonly SetupLinkNotifier $notify,
        private readonly OnboardingTaskMarker $tasks,
    ) {}

    public function resolve(string $token): CompanySetupLink
    {
        $link = CompanySetupLink::findByToken($token);
        if (! $link) {
            throw new HttpException(404, 'Link não encontrado.');
        }

        return $link;
    }

    // ── Página ───────────────────────────────────────────────────────────────

    public function present(CompanySetupLink $link): array
    {
        $company = $link->company;
        $agency = $this->agency($link);
        $base = [
            'state' => $link->state(),
            'company' => ContentReviewPresenter::companyIdentity($company),
            'agency' => $agency ? ContentReviewPresenter::companyIdentity($agency) : null,
            'privacy_url' => self::PRIVACY_URL,
        ];
        if (! $link->isOpen()) {
            return $base + ['message' => $link->state() === CompanySetupLink::STATE_EXPIRED
                ? 'Este link de configuração expirou. Peça um link novo ' . $this->sender($link) . '.'
                : 'Este link de configuração já não está ativo. Peça um link novo ' . $this->sender($link) . '.'];
        }

        return $base + [
            'expires_at' => $link->expires_at?->toIso8601String(),
            'completed' => $link->allStepsDone(),
            'steps' => array_map(fn ($k) => SetupLinkService::presentStep($link, $k), $link->stepKeys()),
            'ga4' => $link->hasStep(CompanySetupLink::STEP_GA4) ? ['sa_email' => config('services.ga4.sa_email') ?: null] : null,
        ];
    }

    public function open(Request $request, CompanySetupLink $link): array
    {
        return $this->opens->record($request, 'setup|' . $link->id, $link, CompanySetupLinkOpen::class,
            ['company_setup_link_id' => $link->id], [], 24 * 365, $link->company_id);
    }

    // ── Facebook e Instagram ─────────────────────────────────────────────────

    public function socialAuthUrl(CompanySetupLink $link): string
    {
        $this->assertStep($link, CompanySetupLink::STEP_SOCIAL);
        $this->touchStarted($link, CompanySetupLink::STEP_SOCIAL);

        return $this->social->authUrl($link->company_id, null, $link->id);
    }

    public function socialCandidates(CompanySetupLink $link): array
    {
        $this->assertStep($link, CompanySetupLink::STEP_SOCIAL);
        $this->assertSocialFromLink($link);

        return $this->social->candidates($link->company_id);
    }

    public function socialSave(CompanySetupLink $link, array $data): array
    {
        $this->assertStep($link, CompanySetupLink::STEP_SOCIAL);
        $this->assertSocialFromLink($link);
        $connection = $this->social->saveSelection($link->company_id, $data['facebook'], $data['instagram'], $data['primary_facebook'] ?? null, $data['primary_instagram'] ?? null);

        $detail = [
            'facebook' => $connection->accounts->where('platform', 'facebook')->pluck('name')->values()->all(),
            'instagram' => $connection->accounts->where('platform', 'instagram')->map(fn ($a) => $a->username ? '@' . $a->username : $a->name)->values()->all(),
        ];

        return $this->markDone($link, CompanySetupLink::STEP_SOCIAL, $detail);
    }

    // ── Anúncios da Meta ─────────────────────────────────────────────────────

    public function adsAuthUrl(CompanySetupLink $link): string
    {
        $this->assertStep($link, CompanySetupLink::STEP_META_ADS);
        $this->touchStarted($link, CompanySetupLink::STEP_META_ADS);

        return $this->ads->authUrl($link->company_id, null, $link->id);
    }

    public function adsAccounts(CompanySetupLink $link): array
    {
        $this->assertStep($link, CompanySetupLink::STEP_META_ADS);
        $integration = $this->assertAdsFromLink($link);

        return ['accounts' => $this->ads->adAccounts($link->company_id), 'selected' => $integration->account_id];
    }

    public function adsSave(CompanySetupLink $link, string $accountId): array
    {
        $this->assertStep($link, CompanySetupLink::STEP_META_ADS);
        $this->assertAdsFromLink($link);
        $integration = $this->ads->setAccount($link->company_id, $accountId, null, $link->id, mustBeListed: true);
        $name = collect($this->ads->adAccounts($link->company_id))->firstWhere('id', $integration->account_id)['name'] ?? null;

        return $this->markDone($link, CompanySetupLink::STEP_META_ADS, ['account_id' => $integration->account_id, 'account_name' => $name]);
    }

    // ── Google Analytics 4 ───────────────────────────────────────────────────

    /** Testa o acesso e só grava a propriedade se a conta de serviço a conseguir ler. */
    public function ga4Verify(CompanySetupLink $link, string $propertyId): array
    {
        $this->assertStep($link, CompanySetupLink::STEP_GA4);
        if (! preg_match('/^\d{6,15}$/', $propertyId)) {
            throw new HttpException(422, 'Indique o ID da propriedade: só números (por exemplo, 398765432).');
        }
        $check = $this->ga4Checker->check((int) $propertyId);
        if (! $check['ok']) {
            $this->markFailure($link, CompanySetupLink::STEP_GA4, CompanySetupLink::ERROR, $check['message']);
            throw new HttpException(422, $check['message']);
        }
        $this->ga4->connect($link->company_id, $propertyId, null, $link->id);

        return $this->markDone($link, CompanySetupLink::STEP_GA4, ['property_id' => $propertyId]) + ['message' => $check['message']];
    }

    // ── Regresso do Facebook ─────────────────────────────────────────────────

    /**
     * Para onde volta o browser depois do diálogo da Meta: a página pública, com o resultado
     * na query e o token só no fragmento. A app não aprovada é registada e avisada aqui.
     */
    public function afterOAuth(int $linkId, string $step, string $signal, string $appBase): string
    {
        $link = CompanySetupLink::find($linkId);
        if (! $link) {
            return $appBase . '/configurar';
        }
        $back = fn (string $result) => $appBase . '/configurar?' . http_build_query(['passo' => $step, 'resultado' => $result]) . '#' . $link->token();
        if (! $link->isOpen()) {
            return $back('fechado');
        }

        [$kind, $reason] = array_pad(explode(':', $signal, 2), 2, null);
        if ($kind !== 'error') {
            return $back('escolher');
        }

        return match ($reason) {
            'not_approved' => $this->notApproved($link, $step, $back),
            'denied', 'scopes' => $this->failedAndBack($link, $step, 'A autorização foi cancelada ou não foram dadas todas as permissões pedidas. Tente de novo e aceite as permissões (são só de leitura).', 'cancelado', $back),
            default => $this->failedAndBack($link, $step, 'Não foi possível concluir a ligação ao Facebook. Tente de novo dentro de alguns minutos.', 'erro', $back),
        };
    }

    // ── Passo iniciado e não concluído (30 minutos) ──────────────────────────

    /** Um só aviso por passo e por link. @return int avisos enviados */
    public function notifyStalled(): int
    {
        $sent = 0;
        $limit = now()->subMinutes(self::STALL_MINUTES);
        CompanySetupLink::whereNull('revoked_at')->where('expires_at', '>', now())->whereNull('completed_at')
            ->orderBy('id')->each(function (CompanySetupLink $link) use ($limit, &$sent) {
                foreach ([CompanySetupLink::STEP_SOCIAL, CompanySetupLink::STEP_META_ADS] as $step) {
                    $s = $link->step($step);
                    if (! $link->hasStep($step) || ($s['status'] ?? null) !== CompanySetupLink::PENDING || empty($s['started_at'])
                        || ! empty($s['stalled_notified_at']) || \Carbon\Carbon::parse($s['started_at'])->gt($limit)) {
                        continue;
                    }
                    $claimed = DB::transaction(function () use ($link, $step) {
                        $row = CompanySetupLink::whereKey($link->id)->lockForUpdate()->first();
                        if (! $row || ! empty($row->step($step)['stalled_notified_at'])) {
                            return false;
                        }
                        $row->patchStep($step, ['stalled_notified_at' => now()->toIso8601String()]);
                        $row->save();

                        return true;
                    });
                    if ($claimed) {
                        $this->notify->stalled($link, $step);
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    // ── Auxiliares ───────────────────────────────────────────────────────────

    private function assertStep(CompanySetupLink $link, string $step): void
    {
        if (! $link->isOpen()) {
            throw new HttpException(409, $link->state() === CompanySetupLink::STATE_EXPIRED
                ? 'Este link de configuração expirou. Peça um link novo ' . $this->sender($link) . '.'
                : 'Este link de configuração já não está ativo. Peça um link novo ' . $this->sender($link) . '.');
        }
        if (! $link->hasStep($step)) {
            throw new HttpException(404, 'Este passo não faz parte deste link.');
        }
    }

    private function assertSocialFromLink(CompanySetupLink $link): void
    {
        $ok = SocialConnection::where('company_id', $link->company_id)->where('setup_link_id', $link->id)
            ->where('status', '!=', SocialConnection::STATUS_REVOKED)->exists();
        if (! $ok) {
            throw new HttpException(409, 'Comece por "Ligar com o Facebook".');
        }
    }

    private function assertAdsFromLink(CompanySetupLink $link): CompanyIntegration
    {
        $integration = CompanyIntegration::where('company_id', $link->company_id)->where('platform', 'meta')->where('setup_link_id', $link->id)
            ->where('status', '!=', 'revoked')->first();
        if (! $integration) {
            throw new HttpException(409, 'Comece por "Autorizar os anúncios".');
        }

        return $integration;
    }

    private function touchStarted(CompanySetupLink $link, string $step): void
    {
        DB::transaction(function () use ($link, $step) {
            $row = CompanySetupLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            $row->patchStep($step, ['started_at' => now()->toIso8601String()]);
            $row->save();
        });
    }

    /** Passo feito: estado, tarefas do ticket de arranque e, na primeira conclusão de todos, o aviso. */
    private function markDone(CompanySetupLink $link, string $step, array $detail): array
    {
        $completedNow = DB::transaction(function () use ($link, $step, $detail) {
            $row = CompanySetupLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            $row->patchStep($step, ['status' => CompanySetupLink::DONE, 'done_at' => now()->toIso8601String(), 'error' => null, 'detail' => $detail]);
            $first = $row->completed_at === null && $row->allStepsDone();
            if ($first) {
                $row->completed_at = now();
            }
            $row->save();

            return $first;
        });

        $this->tasks->markDone($link->company_id, CompanySetupLink::STEP_TASK_KEYS[$step], $link->support_ticket_id);
        $link->refresh();
        if ($completedNow) {
            $this->notify->completed($link);
        }

        return ['step' => SetupLinkService::presentStep($link, $step), 'completed' => $link->allStepsDone()];
    }

    /** Falha num passo (não desfaz um passo já feito: a ligação anterior continua). */
    private function markFailure(CompanySetupLink $link, string $step, string $status, string $message): bool
    {
        return DB::transaction(function () use ($link, $step, $status, $message) {
            $row = CompanySetupLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            $current = $row->step($step);
            $notify = $status === CompanySetupLink::NOT_APPROVED && empty($current['not_approved_notified_at']);
            $fields = ['error' => $message];
            if (($current['status'] ?? null) !== CompanySetupLink::DONE) {
                $fields['status'] = $status;
            }
            if ($notify) {
                $fields['not_approved_notified_at'] = now()->toIso8601String();
            }
            $row->patchStep($step, $fields);
            $row->save();

            return $notify;
        });
    }

    private function notApproved(CompanySetupLink $link, string $step, callable $back): string
    {
        $message = 'A ligação ao Facebook ainda não está disponível para a sua conta. ' . ($this->agency($link) ? 'A sua agência foi avisada.' : 'A empresa que lhe enviou o link foi avisada.');
        if ($this->markFailure($link, $step, CompanySetupLink::NOT_APPROVED, $message)) {
            $this->notify->notApproved($link->fresh(), $step);
        }

        return $back('indisponivel');
    }

    private function failedAndBack(CompanySetupLink $link, string $step, string $message, string $result, callable $back): string
    {
        $this->markFailure($link, $step, CompanySetupLink::ERROR, $message);

        return $back($result);
    }

    private function agency(CompanySetupLink $link): ?\App\Models\Company
    {
        return CompanyManagement::active()->where('managed_company_id', $link->company_id)->with('agency')->first()?->agency;
    }

    private function sender(CompanySetupLink $link): string
    {
        return $this->agency($link) ? 'à sua agência' : 'a quem lho enviou';
    }
}
