<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Radar\Filter\Lexicon;
use App\Services\Radar\RadarCollector;
use App\Services\Radar\Sources\RadarSources;
use Illuminate\Console\Command;

/**
 * Radar: a recolha à mão, por fonte ou de todas as fontes ligadas.
 *
 *   php artisan radar:collect                          simulação (faz o pedido, não grava itens)
 *   php artisan radar:collect --execute                grava
 *   php artisan radar:collect jornal_eco --execute     só uma fonte
 *   php artisan radar:collect google_trends --fixture=tests/Fixtures/radar/google-trends/trending-rss-pt.xml
 *                                                      lê uma amostra gravada (sem rede; não conta para o teto)
 *   php artisan radar:collect jornal_eco --force       passa por cima do teto de 4 pedidos por hora
 *   php artisan radar:collect ipma_avisos --force      lê uma fonte desligada (só nomeada e com --force)
 *
 * Com o Radar desligado (RADAR_ENABLED=false, a omissão) o comando não faz nenhum pedido, salvo
 * com --force ou com --fixture.
 *
 * A simulação regista a recolha (conta para o teto por hora), mas não grava itens nem tendências.
 */
class RadarCollectCommand extends Command
{
    protected $signature = 'radar:collect
        {source? : A chave da fonte (config/radar.php); sem ela, todas as ligadas}
        {--execute : Grava os itens (sem isto, só simula)}
        {--fixture= : Lê esta amostra gravada em vez de ir à fonte}
        {--force : Passa por cima do teto por hora (e lê uma fonte desligada, se nomeada)}';

    protected $description = 'Radar: recolhe as fontes públicas (simulação por omissão; no máximo 4 pedidos por fonte e por hora).';

    public function handle(RadarCollector $collector, RadarSources $sources): int
    {
        $source = $this->argument('source');
        if ($source !== null && ! $sources->has((string) $source)) {
            $this->error("Fonte desconhecida: {$source}. Fontes: " . implode(', ', array_keys($sources->all())) . '.');

            return self::FAILURE;
        }
        $fixture = $this->option('fixture');
        if ($fixture !== null && $source === null) {
            $this->error('Indique a fonte da amostra gravada (por exemplo: radar:collect google_trends --fixture=...).');

            return self::FAILURE;
        }
        if ($fixture !== null && ! is_file((string) $fixture) && is_file(base_path((string) $fixture))) {
            $fixture = base_path((string) $fixture);
        }
        if ($fixture !== null && ! is_file((string) $fixture)) {
            $this->error("Amostra não encontrada: {$fixture}");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $this->line($execute ? 'A recolher e a gravar…' : 'SIMULAÇÃO (nenhum item é gravado). Use --execute para gravar.');
        $results = $collector->collect($source !== null ? (string) $source : null, $execute, ['force' => (bool) $this->option('force'), 'fixture' => $fixture]);

        if ($results === [] && ! RadarCollector::enabled()) {
            $this->warn('Radar desligado (RADAR_ENABLED=false): nada foi recolhido. Use --force para uma recolha à mão.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($results as $r) {
            $filtered = array_map(fn ($reason, $n) => (Lexicon::LABELS[$reason] ?? $reason) . ": {$n}", array_keys($r['filtrados']), $r['filtrados']);
            $rows[] = [$r['fonte'], $r['estado'], $r['pedidos'], $r['http_status'] ?? '', $r['lidos'], $r['novos'], $r['repetidos'], $r['fora_do_ambito'],
                $filtered ? implode('; ', $filtered) : '0', $r['erro'] ?? ''];
        }
        $this->table(['Fonte', 'Estado', 'Pedidos', 'HTTP', 'Lidos', 'Novos', 'Repetidos', 'Fora do âmbito', 'Retirados pelo filtro', 'Erro'], $rows);
        $this->line(sprintf('Total: %d lidos, %d novos, %d repetidos, %d retirados pelo filtro.',
            array_sum(array_column($results, 'lidos')), array_sum(array_column($results, 'novos')), array_sum(array_column($results, 'repetidos')),
            array_sum(array_map(fn ($r) => array_sum($r['filtrados']), $results))));

        return self::SUCCESS;
    }
}
