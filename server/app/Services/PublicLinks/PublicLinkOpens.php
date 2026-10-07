<?php

declare(strict_types=1);

namespace App\Services\PublicLinks;

use App\Support\BotUserAgent;
use App\Support\TeamDeviceMarker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Aberturas dos links públicos sem login (orçamento e aprovação de conteúdos):
 *
 *  · só conta o sinal enviado pela página depois de carregar e estar visível;
 *  · robôs (pré-visualizações de links, verificadores do email), a equipa XPLENDOR e a agência
 *    que gere a empresa do link não contam;
 *  · a mesma visita (o mesmo browser) dentro de 30 minutos é a mesma abertura;
 *  · o IP não é guardado: só um identificador aleatório do browser em hash e o dispositivo;
 *  · avisos limitados: na primeira abertura e numa nova, no máximo um a cada N horas.
 *
 * Quem chama decide o que fazer com o aviso (alert) e grava nos seus próprios modelos.
 */
class PublicLinkOpens
{
    public const SAME_VISIT_MINUTES = 30;

    /**
     * @param  Model  $counter  linha com open_count, first_opened_at, last_opened_at e last_open_alert_at (bloqueada aqui)
     * @param  class-string<Model>  $openModel  tabela das aberturas
     * @param  array<string, mixed>  $scope  colunas que identificam o link nas aberturas
     * @param  array<string, mixed>  $extra  colunas a acrescentar a uma abertura nova
     * @param  int|null  $companyId  a empresa do link: as aberturas da agência que a gere também não contam
     * @return array{counted: bool, reason: ?string, alert: bool, first: bool, device: ?string, open_count: int}
     */
    public function record(Request $request, string $visitorScope, Model $counter, string $openModel, array $scope, array $extra, int $alertEveryHours, ?int $companyId = null): array
    {
        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'team_marker' => ['nullable', 'string', 'max:300'],
        ]);
        $userAgent = (string) $request->userAgent();
        if (BotUserAgent::isBot($userAgent)) {
            return $this->skipped('bot');
        }
        $viewer = auth('sanctum')->user();
        $marked = TeamDeviceMarker::user($data['team_marker'] ?? null);
        foreach (array_filter([$viewer, $marked]) as $person) {
            if ($person->role === 'root' || ($companyId && self::isManagingAgency($person, $companyId))) {
                return $this->skipped('team');
            }
        }

        $visitorHash = hash('sha256', $visitorScope . '|' . $data['visitor_id']);
        $device = BotUserAgent::device($userAgent);

        return DB::transaction(function () use ($counter, $openModel, $scope, $extra, $visitorHash, $device, $alertEveryHours) {
            $row = $counter->newQuery()->whereKey($counter->getKey())->lockForUpdate()->firstOrFail();
            $now = now();
            $same = $openModel::query()->where($scope)->where('visitor_hash', $visitorHash)
                ->where('last_seen_at', '>=', $now->copy()->subMinutes(self::SAME_VISIT_MINUTES))
                ->orderByDesc('last_seen_at')->first();
            if ($same) {
                $same->update(['last_seen_at' => $now]);

                return ['counted' => false, 'reason' => 'same_visit', 'alert' => false, 'first' => false, 'device' => $device, 'open_count' => (int) $row->open_count];
            }

            $openModel::create($scope + $extra + ['visitor_hash' => $visitorHash, 'device' => $device, 'opened_at' => $now, 'last_seen_at' => $now]);
            $first = (int) $row->open_count === 0;
            $alert = ! $row->last_open_alert_at || $row->last_open_alert_at->lte($now->copy()->subHours($alertEveryHours));
            $row->forceFill([
                'open_count' => (int) $row->open_count + 1,
                'first_opened_at' => $row->first_opened_at ?? $now,
                'last_opened_at' => $now,
                'last_open_alert_at' => $alert ? $now : $row->last_open_alert_at,
            ])->save();

            return ['counted' => true, 'reason' => null, 'alert' => $alert, 'first' => $first, 'device' => $device, 'open_count' => (int) $row->open_count];
        });
    }

    /** Pessoa da agência que gere a empresa do link (relação ativa). */
    private static function isManagingAgency(\App\Models\User $person, int $companyId): bool
    {
        return $person->company_id !== null && \App\Models\CompanyManagement::active()
            ->where('managed_company_id', $companyId)->where('agency_company_id', (int) $person->company_id)->exists();
    }

    private function skipped(string $reason): array
    {
        return ['counted' => false, 'reason' => $reason, 'alert' => false, 'first' => false, 'device' => null, 'open_count' => 0];
    }
}
