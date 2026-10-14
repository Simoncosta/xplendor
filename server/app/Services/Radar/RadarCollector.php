<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\RadarItem;
use App\Models\RadarRun;
use App\Services\Radar\Filter\RadarSafetyFilter;
use App\Services\Radar\Sources\RadarFetchException;
use App\Services\Radar\Sources\RadarItemDto;
use App\Services\Radar\Sources\RadarParseResult;
use App\Services\Radar\Sources\RadarSource;
use App\Services\Radar\Sources\RadarSources;
use App\Services\Radar\Sources\RobotsGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Radar: a recolha das fontes públicas (R1).
 *  · Um pedido por fonte, com o pedido condicional da última recolha boa (304: nada novo).
 *  · O teto de 4 pedidos por fonte e por hora soma os pedidos das recolhas da última hora
 *    (também os falhados, os 304, as simulações e a leitura do robots.txt); acima disso a fonte
 *    não é lida, salvo com force. As leituras de uma amostra gravada (fixture) não contam.
 *  · Uma fonte em falha não pára as outras; cada recolha fica em radar_runs.
 *  · Cada item novo passa pelo filtro de segurança e, a gravar, entra numa tendência.
 *  · Simulação por omissão: faz o pedido, mostra o que entraria, e só regista a recolha (para
 *    o teto); nenhum item nem tendência é gravado.
 * Os registos (log) só levam contagens, nunca títulos.
 */
class RadarCollector
{
    public function __construct(
        private readonly RadarSources $sources,
        private readonly RadarSafetyFilter $filter,
        private readonly RadarTopics $topics,
        private readonly RobotsGuard $robots,
    ) {}

    /**
     * @param  array{force?: bool, fixture?: ?string, scheduled?: bool}  $options
     * @return array<string, array<string, mixed>> os totais por fonte: lidos, novos, repetidos,
     *                                             fora_do_ambito, filtrados (por motivo), pedidos, estado, erro
     */
    /** O interruptor geral (config/radar.php, RADAR_ENABLED). */
    public static function enabled(): bool
    {
        return (bool) config('radar.enabled', false);
    }

    public function collect(?string $source = null, bool $execute = false, array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $fixture = isset($options['fixture']) && $options['fixture'] !== '' ? (string) $options['fixture'] : null;
        $scheduled = (bool) ($options['scheduled'] ?? false);
        if ($fixture !== null && $source === null) {
            throw new InvalidArgumentException('Uma amostra gravada (--fixture) lê-se para uma fonte de cada vez.');
        }
        if (! self::enabled() && ! $force && $fixture === null) {
            // RADAR_ENABLED=false: nenhum pedido a nenhuma fonte (só o --force do comando passa).
            Log::info('[Radar] Recolha não feita: Radar desligado (RADAR_ENABLED=false)');

            return [];
        }
        $list = $source !== null ? [$source => $this->sources->get($source)] : $this->sources->all();

        $results = [];
        foreach ($list as $key => $src) {
            $explicit = $source !== null;
            try {
                $results[$key] = $this->collectOne($src, $execute, $force, $fixture, $scheduled, $explicit);
            } catch (\Throwable $e) {
                // Uma falha inesperada numa fonte não pára as outras.
                $results[$key] = $this->result($key, ['estado' => 'erro', 'erro' => mb_substr('Falha inesperada: ' . class_basename($e), 0, 500)]);
                Log::warning('[Radar] Falha inesperada na recolha', ['fonte' => $key, 'tipo' => class_basename($e)]);
            }
        }
        Log::info('[Radar] Recolha', [
            'executar' => $execute,
            'fontes' => array_map(fn ($r) => array_intersect_key($r, array_flip(['estado', 'lidos', 'novos', 'repetidos', 'pedidos'])) + ['filtrados' => array_sum($r['filtrados'])], $results),
        ]);

        return $results;
    }

    private function collectOne(RadarSource $src, bool $execute, bool $force, ?string $fixture, bool $scheduled, bool $explicit): array
    {
        $key = $src->key();
        if (! $src->enabled() && ! ($explicit && $force)) {
            return $this->result($key, ['estado' => 'desligada', 'erro' => (string) ($src->config()['disabled_reason'] ?? 'Fonte desligada em config/radar.php.')]);
        }
        if ($scheduled && ! $this->isDue($src)) {
            return $this->result($key, ['estado' => 'adiada']);
        }

        if ($fixture !== null) {
            if (! is_file($fixture) || ! is_readable($fixture)) {
                throw new InvalidArgumentException("Amostra não encontrada: {$fixture}");
            }

            return $this->process($src, (string) file_get_contents($fixture), $execute, ['from_fixture' => true, 'requests' => 0, 'http_status' => null]);
        }

        // O teto por hora: os pedidos já feitos mais os desta recolha (com o robots.txt, quando toca).
        $needed = 1 + ($this->robots->needsFetch($src) ? 1 : 0);
        $used = $this->requestsLastHour($key);
        $max = (int) config('radar.max_requests_per_hour', 4);
        if (! $force && $used + $needed > $max) {
            return $this->result($key, ['estado' => 'recusado', 'erro' => "Teto de {$max} pedidos por hora atingido nesta fonte ({$used} na última hora)."]);
        }

        $startedAt = now();
        $requests = 0;
        $robots = $this->robots->check($src);
        $requests += $robots['requests'];
        if (! $robots['allowed']) {
            $this->recordRun($key, $startedAt, ['status' => 'erro', 'requests' => $requests, 'error' => $robots['error'], 'dry_run' => ! $execute]);

            return $this->result($key, ['estado' => 'erro', 'erro' => $robots['error'], 'pedidos' => $requests]);
        }

        $requests++;
        try {
            $response = $src->fetch($execute ? $this->conditionalHeaders($key) : []);
        } catch (RadarFetchException $e) {
            $this->recordRun($key, $startedAt, ['status' => 'erro', 'requests' => $requests, 'error' => $e->getMessage(), 'dry_run' => ! $execute]);

            return $this->result($key, ['estado' => 'erro', 'erro' => $e->getMessage(), 'pedidos' => $requests]);
        }
        $meta = ['requests' => $requests, 'http_status' => $response->status, 'etag' => $response->etag, 'last_modified' => $response->lastModified, 'started_at' => $startedAt];
        if ($response->notModified()) {
            $this->recordRun($key, $startedAt, ['status' => 'ok', 'dry_run' => ! $execute] + $meta);

            return $this->result($key, ['estado' => 'ok', 'pedidos' => $requests, 'http_status' => 304]);
        }
        if ($response->status < 200 || $response->status >= 300) {
            $error = "A fonte respondeu HTTP {$response->status}.";
            $this->recordRun($key, $startedAt, ['status' => 'erro', 'error' => $error, 'dry_run' => ! $execute] + $meta);

            return $this->result($key, ['estado' => 'erro', 'erro' => $error, 'pedidos' => $requests, 'http_status' => $response->status]);
        }

        return $this->process($src, $response->body, $execute, $meta);
    }

    /** Lê o corpo, filtra os itens novos e (a gravar) guarda-os e junta-os nas tendências. */
    private function process(RadarSource $src, string $body, bool $execute, array $meta): array
    {
        $key = $src->key();
        $startedAt = $meta['started_at'] ?? now();
        unset($meta['started_at']);
        try {
            $parsed = $src->parse($body);
        } catch (RadarFetchException $e) {
            $this->recordRun($key, $startedAt, ['status' => 'erro', 'error' => $e->getMessage(), 'dry_run' => ! $execute] + $meta);

            return $this->result($key, ['estado' => 'erro', 'erro' => $e->getMessage(), 'pedidos' => (int) $meta['requests'], 'http_status' => $meta['http_status'] ?? null]);
        }
        unset($body);

        $counts = $this->store($src, $parsed, $execute);
        $this->recordRun($key, $startedAt, [
            'status' => 'ok', 'dry_run' => ! $execute,
            'items_seen' => $counts['lidos'], 'items_new' => $counts['novos'], 'items_repeated' => $counts['repetidos'],
            'items_blocked' => array_sum($counts['filtrados']), 'items_out_of_scope' => $counts['fora_do_ambito'],
            'blocked_reasons' => $counts['filtrados'] ?: null,
        ] + $meta);

        return $this->result($key, ['estado' => 'ok', 'pedidos' => (int) $meta['requests'], 'http_status' => $meta['http_status'] ?? null] + $counts);
    }

    /** @return array{lidos: int, novos: int, repetidos: int, fora_do_ambito: int, filtrados: array<string, int>} */
    private function store(RadarSource $src, RadarParseResult $parsed, bool $execute): array
    {
        $key = $src->key();
        $counts = ['lidos' => count($parsed->items), 'novos' => 0, 'repetidos' => 0, 'fora_do_ambito' => $parsed->outOfScope, 'filtrados' => []];
        $byKey = [];
        foreach ($parsed->items as $dto) {
            $byKey[$dto->externalKey()] ??= $dto;
        }
        $counts['repetidos'] += count($parsed->items) - count($byKey);
        $existing = RadarItem::where('source_key', $key)->whereIn('external_key', array_keys($byKey))->pluck('external_key')->all();
        $counts['repetidos'] += count($existing);
        $now = CarbonImmutable::now();
        $expires = $now->addDays((int) config('radar.retention_days', 30));

        $work = function () use ($byKey, $existing, $key, $execute, $now, $expires, &$counts) {
            foreach ($byKey as $externalKey => $dto) {
                if (in_array($externalKey, $existing, true)) {
                    continue;
                }
                /** @var RadarItemDto $dto */
                $check = $this->filter->check($dto->title, $dto->relatedTitles(), $dto->filterCategories);
                foreach ($check['reasons'] as $reason) {
                    $counts['filtrados'][$reason] = ($counts['filtrados'][$reason] ?? 0) + 1;
                }
                if (! $execute) {
                    $counts['novos']++;
                    continue;
                }
                try {
                    $item = RadarItem::create([
                        'source_key' => $key, 'external_key' => $externalKey, 'title' => $dto->title,
                        'url' => $dto->url !== null && strlen($dto->url) <= 2048 ? $dto->url : null,
                        'source_name' => mb_substr($dto->sourceName, 0, 120), 'published_at' => $dto->publishedAt, 'fetched_at' => $now,
                        'approx_volume' => $dto->approxVolume, 'approx_volume_label' => $dto->approxVolumeLabel, 'extra' => $dto->extra,
                        'filter_verdict' => $check['verdict'], 'filter_reasons' => $check['reasons'] ?: null,
                        'filter_flags' => $check['flags'] ?: null, 'expires_at' => $expires,
                    ]);
                } catch (QueryException $e) {
                    // Outra recolha gravou o mesmo item entretanto (único por fonte e chave).
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                    $counts['repetidos']++;
                    foreach ($check['reasons'] as $reason) {
                        $counts['filtrados'][$reason]--;
                    }
                    continue;
                }
                $counts['novos']++;
                $this->topics->assign($item);
            }
        };
        $execute ? DB::transaction($work) : $work();
        $counts['filtrados'] = array_filter($counts['filtrados']);
        ksort($counts['filtrados']);

        return $counts;
    }

    /** Os pedidos feitos a esta fonte na última hora (as amostras gravadas não contam). */
    public function requestsLastHour(string $key): int
    {
        return (int) RadarRun::where('source_key', $key)->where('from_fixture', false)->where('started_at', '>', now()->subHour())->sum('requests');
    }

    /** O ETag e o Last-Modified da última recolha boa a gravar (nunca de uma simulação). */
    private function conditionalHeaders(string $key): array
    {
        $last = RadarRun::where('source_key', $key)->where('status', 'ok')->where('dry_run', false)->where('from_fixture', false)
            ->where(fn ($q) => $q->whereNotNull('etag')->orWhereNotNull('last_modified'))
            ->orderByDesc('started_at')->orderByDesc('id')->first();
        $headers = [];
        if ($last?->etag) {
            $headers['If-None-Match'] = $last->etag;
        }
        if ($last?->last_modified) {
            $headers['If-Modified-Since'] = $last->last_modified;
        }

        return $headers;
    }

    /** A tarefa de 15 em 15 minutos respeita o intervalo de cada fonte (com um minuto de folga). */
    private function isDue(RadarSource $src): bool
    {
        $last = RadarRun::where('source_key', $src->key())->where('from_fixture', false)->where('requests', '>', 0)->max('started_at');

        return $last === null || CarbonImmutable::parse($last)->lessThanOrEqualTo(now()->subMinutes($src->intervalMinutes() - 1));
    }

    private function recordRun(string $key, $startedAt, array $data): void
    {
        $data = array_intersect_key($data, array_flip([
            'status', 'dry_run', 'from_fixture', 'requests', 'http_status', 'etag', 'last_modified', 'items_seen', 'items_new',
            'items_repeated', 'items_blocked', 'items_out_of_scope', 'blocked_reasons', 'error',
        ]));
        foreach (['error' => 500, 'etag' => 255, 'last_modified' => 64] as $field => $max) {
            if (isset($data[$field])) {
                $data[$field] = mb_substr((string) $data[$field], 0, $max);
            }
        }
        RadarRun::create($data + ['source_key' => $key, 'started_at' => $startedAt, 'finished_at' => now(), 'from_fixture' => false, 'requests' => 0]);
    }

    private function result(string $key, array $data): array
    {
        return array_merge([
            'fonte' => $key, 'estado' => 'ok', 'lidos' => 0, 'novos' => 0, 'repetidos' => 0, 'fora_do_ambito' => 0, 'filtrados' => [],
            'pedidos' => 0, 'http_status' => null, 'erro' => null,
        ], $data);
    }
}
