<?php

declare(strict_types=1);

namespace App\Services\Agency\Meta;

use App\Models\AgencyMetaConnection;
use App\Services\Social\MetaSocialClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Parceiro 3: os portfólios da Meta que a agência vê (os seus e os dos clientes que a convidaram) e
 * os ativos de cada um: Páginas (com o Instagram ligado), contas de anúncios e contas de Instagram,
 * cada um marcado como da agência ou do cliente. Só leitura; nada se liga a uma empresa aqui. Lê com o
 * token da ligação da agência e guarda a leitura 10 minutos (o "Atualizar" lê de novo). Um token
 * expirado ou sem permissão muda o estado da ligação, para o ecrã pedir para voltar a ligar.
 */
class AgencyPortfolioService
{
    public const CACHE_MINUTES = 10;

    public function __construct(private readonly MetaBusinessClientInterface $meta) {}

    /** @return array{connected: bool, ok: bool, error: ?string, kind: ?string, read_at: ?string, businesses: list<array>} */
    public function list(int $agencyId, bool $refresh = false): array
    {
        $connection = AgencyMetaConnection::where('agency_company_id', $agencyId)->first();
        if (! $connection) {
            return ['connected' => false, 'ok' => false, 'error' => 'A agência ainda não ligou a Meta.', 'kind' => null, 'read_at' => null, 'businesses' => []];
        }
        $key = "agency-meta-portfolios:{$agencyId}:{$connection->id}";
        if ($refresh) {
            Cache::forget($key);
        }
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $out = $this->read($connection);
        if ($out['ok']) {
            Cache::put($key, $out, now()->addMinutes(self::CACHE_MINUTES));
        }

        return $out;
    }

    private function read(AgencyMetaConnection $connection): array
    {
        $token = (string) $connection->access_token;
        $list = $this->meta->businesses($token);
        if (! $list['ok']) {
            return $this->failed($connection, $list);
        }
        $businesses = [];
        foreach ($list['businesses'] as $b) {
            $assets = $this->meta->assets($token, $b['id']);
            if (! $assets['ok']) {
                return $this->failed($connection, $assets);
            }
            $businesses[] = $b + ['pages' => $assets['pages'], 'ad_accounts' => $assets['ad_accounts'], 'instagram' => $assets['instagram']];
        }
        if ($connection->status !== AgencyMetaConnection::STATUS_ACTIVE) {
            $connection->update(['status' => AgencyMetaConnection::STATUS_ACTIVE]);
        }

        return ['connected' => true, 'ok' => true, 'error' => null, 'kind' => null, 'read_at' => now()->toIso8601String(), 'businesses' => $businesses];
    }

    private function failed(AgencyMetaConnection $connection, array $r): array
    {
        $kind = (string) ($r['kind'] ?? MetaSocialClient::ERR_FAILED);
        if ($kind === MetaSocialClient::ERR_EXPIRED) {
            $connection->update(['status' => AgencyMetaConnection::STATUS_EXPIRED]);
        } elseif ($kind === MetaSocialClient::ERR_PERMISSION) {
            $connection->update(['status' => AgencyMetaConnection::STATUS_PERMISSION]);
        }
        Log::warning('[Agência] Leitura dos portfólios da Meta falhou', ['agencia' => $connection->agency_company_id, 'tipo' => $kind]);
        $error = match ($kind) {
            MetaSocialClient::ERR_EXPIRED => 'O acesso da agência à Meta expirou. Um administrador da agência tem de voltar a ligar.',
            MetaSocialClient::ERR_PERMISSION => 'A Meta recusou a leitura por falta de permissão. Volte a ligar e aceite todas as permissões.',
            default => 'A Meta não respondeu. Tente novamente dentro de momentos.',
        };

        return ['connected' => true, 'ok' => false, 'error' => $error, 'kind' => $kind, 'read_at' => null, 'businesses' => []];
    }
}
