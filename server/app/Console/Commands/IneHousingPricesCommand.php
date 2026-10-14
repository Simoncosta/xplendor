<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IneHousingPrice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Bússola Master, R3: a referência oficial do imobiliário, o valor mediano das vendas de
 * alojamentos familiares nos últimos 12 meses (euros por m², indicador 0012234 do INE, categoria
 * Total), por freguesia e por concelho. Dois pedidos por trimestre: a metainformação (o último
 * período) e os dados desse período (sempre com Dim1: sem ele a resposta passa o limite de células).
 * Uma leitura por período (salvo --force). Termos: CC BY 4.0, uso comercial permitido, com a
 * frase de atribuição do INE.
 *
 *   php artisan ine:housing-prices                         diz o que faria (sem pedidos)
 *   php artisan ine:housing-prices --execute               lê o INE e grava
 *   php artisan ine:housing-prices --fixture=caminho.json  lê uma amostra gravada (com --execute grava)
 */
class IneHousingPricesCommand extends Command
{
    public const META_URL = 'https://www.ine.pt/ine/json_indicador/pindicaMeta.jsp?varcd=0012234&lang=PT';
    public const DATA_URL = 'https://www.ine.pt/ine/json_indicador/pindica.jsp?op=2&varcd=0012234&Dim1=%s&lang=PT';

    protected $signature = 'ine:housing-prices {--execute : Lê e grava (sem isto, só diz o que faria)} {--fixture= : Lê esta amostra gravada (os dados de um período)} {--force : Lê de novo um período já gravado}';

    protected $description = 'Imobiliário: a referência do INE (valor mediano das vendas por m², por freguesia e concelho).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $fixture = $this->option('fixture');
        if ($fixture === null && ! $execute) {
            $this->line('SIMULAÇÃO: nenhum pedido. Com --execute, lê:');
            $this->line('  1. ' . self::META_URL . ' (o último período)');
            $this->line('  2. ' . sprintf(self::DATA_URL, '{período}') . ' (os dados desse período)');

            return self::SUCCESS;
        }
        if ($fixture !== null) {
            $path = is_file((string) $fixture) ? (string) $fixture : base_path((string) $fixture);
            if (! is_file($path)) {
                $this->error("Amostra não encontrada: {$fixture}");

                return self::FAILURE;
            }
            $data = json_decode((string) file_get_contents($path), true);
        } else {
            $meta = $this->get(self::META_URL);
            $period = $meta !== null ? self::latestPeriodCode($meta) : null;
            if ($period === null) {
                $this->error('Não foi possível ler o último período da metainformação do INE.');

                return self::FAILURE;
            }
            if (! $this->option('force') && IneHousingPrice::where('period_code', $period)->exists()) {
                $this->info("O período {$period} já está gravado. Nada a fazer (use --force para ler de novo).");

                return self::SUCCESS;
            }
            $data = $this->get(sprintf(self::DATA_URL, $period));
        }
        $rows = $data !== null ? self::parse($data) : [];
        if ($rows === []) {
            $this->error('A resposta do INE não tem dados reconhecíveis.');

            return self::FAILURE;
        }
        $period = $rows[0]['period_code'];
        $parishes = array_filter($rows, fn ($r) => $r['geo_level'] === 'freguesia');
        $withValue = array_filter($parishes, fn ($r) => $r['has_value']);
        $codes = array_column($withValue, 'official_code');
        $matched = DB::table('parishes')->whereIn('dicofre', $codes)->count();
        $this->table(['Período', 'Registos', 'Freguesias', 'Freguesias com valor', 'Concelhos', 'Freguesias da plataforma com referência'],
            [[$rows[0]['period_label'], count($rows), count($parishes), count($withValue), count($rows) - count($parishes), $matched]]);
        if (! $execute) {
            $this->line('SIMULAÇÃO: nada foi gravado. Use --execute para gravar.');

            return self::SUCCESS;
        }
        $now = now();
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('ine_housing_prices')->upsert(array_map(fn ($r) => $r + ['fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now], $chunk),
                ['geo_code', 'period_code'], ['geo_name', 'official_code', 'period_label', 'median_m2', 'has_value', 'ine_updated_on', 'fetched_at', 'updated_at']);
        }
        $this->info("Gravado: {$period}, " . count($rows) . ' registos.');
        \App\Services\Compass\Imoveis\ImoveisCompassTriggers::afterIneUpdate();

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> só a categoria Total (H1), por freguesia (9) e por concelho (7) */
    public static function parse(array $data): array
    {
        $root = isset($data[0]) ? $data[0] : $data;
        $updated = isset($root['DataUltimoAtualizacao']) ? CarbonImmutable::parse((string) $root['DataUltimoAtualizacao'])->toDateString() : null;
        $out = [];
        foreach ((array) ($root['Dados'] ?? []) as $label => $records) {
            $code = self::periodCode((string) $label);
            if ($code === null) {
                continue;
            }
            foreach ((array) $records as $r) {
                $geo = (string) ($r['geocod'] ?? '');
                if (str_replace('.', '', (string) ($r['dim_3'] ?? '')) !== 'H1' || ! in_array(strlen($geo), [7, 9], true)) {
                    continue;
                }
                $value = isset($r['valor']) && is_numeric($r['valor']) ? (int) round((float) $r['valor']) : null;
                $out[] = ['geo_level' => strlen($geo) === 9 ? 'freguesia' : 'municipio', 'geo_code' => $geo, 'geo_name' => mb_substr((string) ($r['geodsg'] ?? ''), 0, 191),
                    'official_code' => substr($geo, 3), 'period_code' => $code, 'period_label' => mb_substr((string) $label, 0, 60),
                    'median_m2' => $value, 'has_value' => $value !== null, 'ine_updated_on' => $updated];
            }
        }

        return $out;
    }

    /** "1.º Trimestre de 2026" → S5A20261. */
    public static function periodCode(string $label): ?string
    {
        return preg_match('/(\d)\.\s*º\s*Trimestre\s+de\s+(\d{4})/u', $label, $m) ? "S5A{$m[2]}{$m[1]}" : null;
    }

    /** O código do último período na metainformação (UltimoPeriodo, ou a categoria de período mais recente). */
    public static function latestPeriodCode(array $meta): ?string
    {
        $root = isset($meta[0]) ? $meta[0] : $meta;
        $codes = [];
        foreach ((array) ($root['Dimensoes']['Categoria_Dim'] ?? []) as $c) {
            foreach ((array) $c as $key => $list) {
                if (str_starts_with((string) $key, 'Dim_Num1_')) {
                    foreach ((array) $list as $x) {
                        $codes[(string) ($x['categ_dsg'] ?? '')] = (string) ($x['categ_cod'] ?? '');
                    }
                }
            }
        }
        $last = (string) ($root['UltimoPeriodo'] ?? '');
        if ($last !== '' && isset($codes[$last])) {
            return $codes[$last];
        }
        $all = array_values(array_filter($codes, fn ($c) => str_starts_with($c, 'S5A')));
        sort($all);

        return $all !== [] ? end($all) : null;
    }

    private function get(string $url): ?array
    {
        $res = Http::timeout(120)->connectTimeout(10)->withUserAgent((string) config('radar.user_agent'))->acceptJson()->get($url);
        if (! $res->successful()) {
            $this->warn("O INE respondeu {$res->status()} a {$url}");

            return null;
        }

        return $res->json();
    }
}
