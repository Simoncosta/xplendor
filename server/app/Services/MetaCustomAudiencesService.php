<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\MetaCustomAudience;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fotografia diária dos públicos personalizados Meta de uma empresa.
 * Corre no sync diário (a seguir aos insights) e NUNCA o faz falhar: se a ligação
 * não tiver permissão para ler públicos, regista 'no_permission' e segue; o motor
 * de recomendações mostra esse estado com honestidade.
 *
 * Só metadados da Meta (nome, tipo, tamanho aproximado, última atualização). Nenhum
 * dado pessoal.
 */
class MetaCustomAudiencesService
{
    public const STATUS_OK = 'ok';
    public const STATUS_NO_PERMISSION = 'no_permission';
    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly MetaAdsService $metaAds) {}

    public function sync(CompanyIntegration $integration): array
    {
        $accountId = MetaAccountInsightsService::normalizeAccountId($integration->account_id);
        if ($integration->platform !== 'meta' || $accountId === null
            || in_array($integration->status, ['revoked', 'expired'], true) || $integration->isTokenExpired()) {
            return ['result' => 'skipped'];
        }

        $res = $this->metaAds->getCustomAudiences((string) $integration->access_token, $accountId);

        if (! $res['ok']) {
            $status = $res['no_permission'] ? self::STATUS_NO_PERMISSION : self::STATUS_FAILED;
            $integration->update([
                'audiences_sync_status' => $status,
                'audiences_error'       => mb_substr((string) $res['error'], 0, 500),
            ]);

            // A fotografia anterior fica como está (não se apaga por causa de um erro).
            return ['result' => $status];
        }

        $now = now();
        DB::transaction(function () use ($integration, $accountId, $res, $now) {
            // Substitui a fotografia da empresa (inclui públicos de uma conta antiga).
            MetaCustomAudience::where('company_id', $integration->company_id)->delete();

            foreach (array_chunk($res['rows'], 200) as $chunk) {
                MetaCustomAudience::insert(array_map(fn ($a) => $a + [
                    'company_id' => $integration->company_id,
                    'account_id' => $accountId,
                    'name'       => $a['name'] !== null ? mb_substr($a['name'], 0, 255) : null,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        $integration->update([
            'audiences_sync_status' => self::STATUS_OK,
            'audiences_synced_at'   => $now,
            'audiences_error'       => null,
        ]);

        return ['result' => self::STATUS_OK, 'count' => count($res['rows'])];
    }
}
