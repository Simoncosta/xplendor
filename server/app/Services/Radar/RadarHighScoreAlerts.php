<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Access\Access;
use App\Mail\RadarHighScoreMail;
use App\Models\Company;
use App\Models\RadarRelevance;
use App\Models\RadarSignal;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\AlertService;
use App\Services\CompanyModuleService;
use App\Settings\Settings;
use App\Support\ExternalCalls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Bússola 7: os avisos dos sinais de nota alta. Um sinal do Radar por tratar, ainda na janela, cuja
 * relevância dada pela IA ao ramo da empresa (radar_relevances.score, de 0 a 100) é igual ou acima do
 * limiar (Admin › Radar; 85 por omissão; decisão de 24/10) gera um aviso no sino (tipo radar_high_score)
 * e um e-mail a quem vê a Bússola e não desligou estes avisos. Com vários, avisa o de maior pontuação do
 * encaixe (radar_signals.score: a relevância mais a zona e a marca). No máximo um por dia e por empresa
 * (dia de Lisboa), só das 9 às 20 horas; um sinal da noite espera pelas 9. O e-mail segue a guarda das
 * chamadas externas (no dev fica por enviar).
 */
class RadarHighScoreAlerts
{
    public const FROM_HOUR = 9;
    public const TO_HOUR = 20;

    public function __construct(
        private readonly AlertService $alerts,
        private readonly CompanyModuleService $modules,
        private readonly Access $access,
    ) {}

    public static function minScore(): int
    {
        return (int) Settings::platform('radar.alert_min_score');
    }

    public static function inWindow(CarbonImmutable $now): bool
    {
        $h = (int) $now->setTimezone('Europe/Lisbon')->hour;

        return $h >= self::FROM_HOUR && $h < self::TO_HOUR;
    }

    /** Os avisos de hoje (dia de Lisboa), em todas as empresas. */
    public static function sentToday(?CarbonImmutable $now = null): int
    {
        return RadarSignal::whereNotNull('alerted_at')->where('alerted_at', '>=', self::startOfDay($now ?? CarbonImmutable::now()))->count();
    }

    /** @return array{alerts: int, emails: int, motivo: ?string} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['alerts' => 0, 'emails' => 0, 'motivo' => null];
        if (! self::inWindow($now)) {
            return ['motivo' => 'Fora do horário dos avisos (9 às 20 horas, Lisboa).'] + $out;
        }
        $min = self::minScore();
        $companies = RadarSignal::where('status', RadarSignal::NEW)->whereNull('alerted_at')
            ->where('window_until', '>', $now)->distinct()->pluck('company_id');
        foreach ($companies as $companyId) {
            $companyId = (int) $companyId;
            $slug = Company::with('contentSector')->find($companyId)?->contentSector?->slug;
            if ($slug === null || ! $this->modules->isEnabled($companyId, 'radar')
                || RadarSignal::where('company_id', $companyId)->where('alerted_at', '>=', self::startOfDay($now))->exists()) {
                continue;
            }
            // O limiar vê a relevância da IA para o ramo; a ordem é a do encaixe (o melhor sinal primeiro).
            $signal = RadarSignal::with('topic')->where('company_id', $companyId)->where('status', RadarSignal::NEW)->whereNull('alerted_at')
                ->whereIn('topic_id', RadarRelevance::select('topic_id')->where('sector_slug', $slug)->where('score', '>=', $min))
                ->where('window_until', '>', $now)->orderByDesc('score')->orderBy('created_at')->first();
            if ($signal === null || $signal->topic === null) {
                continue;
            }
            // Só um processo marca o sinal (o aviso não se repete se a tarefa correr duas vezes).
            if (RadarSignal::whereKey($signal->id)->whereNull('alerted_at')->update(['alerted_at' => $now]) !== 1) {
                continue;
            }
            $company = Company::find($companyId);
            $until = CarbonImmutable::parse($signal->window_until)->setTimezone('Europe/Lisbon');
            $title = mb_substr('Radar: está a subir ' . $signal->topic->title, 0, 255);
            $message = mb_substr(trim($signal->why) . ' Vale até ' . $until->format('d/m') . ' às ' . $until->format('H:i') . '.', 0, 900);
            $this->alerts->createSystemAlert($companyId, 'opportunity', $title, $message, 'medium', '/marketing/bussola', kind: UserNotificationPreference::RADAR_HIGH_SCORE);
            $out['alerts']++;
            if ($company && ExternalCalls::allowed($company)) {
                foreach ($this->recipients($company) as $user) {
                    Mail::to($user->email)->queue(new RadarHighScoreMail($user->name, (string) ($company->trade_name ?: $company->fiscal_name),
                        $signal->topic->title, trim($signal->why), $until->format('d/m/Y H:i'), rtrim((string) config('app.frontend_url'), '/') . '/marketing/bussola'));
                    $out['emails']++;
                }
            }
            Log::info('[Radar] Aviso de nota alta', ['empresa' => $companyId, 'sinal' => $signal->id, 'pontuacao' => $signal->score, 'emails' => $out['emails']]);
        }

        return $out;
    }

    /** Quem vê a Bússola na empresa, com e-mail, e não desligou estes avisos. @return list<User> */
    public function recipients(Company $company): array
    {
        $off = DB::table('user_notification_preferences')->where('radar_high_score', false)->pluck('user_id')->flip();

        return User::where('company_id', $company->id)->where('role', '!=', 'root')->whereNotNull('email')->orderBy('id')->get()
            ->filter(fn (User $u) => ! $off->has($u->id) && $this->access->can($u, $company->id, 'bussola.ver')->allowed)
            ->values()->all();
    }

    private static function startOfDay(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone('Europe/Lisbon')->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
    }
}
