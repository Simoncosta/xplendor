<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OcrInvoice;
use App\Services\Ai\AiFunctionSettings;
use App\Services\InvoiceOcrService;
use App\Services\Ocr\OcrComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Comparação dos modelos do OCR das faturas (chamadas REAIS, acompanhada).
 *
 *   php artisan ocr:compare                       SIMULAÇÃO: as faturas escolhidas, o número de
 *                                                 chamadas, o custo estimado e as chaves (sem as
 *                                                 mostrar). Não envia nada.
 *   php artisan ocr:compare --execute             corre (só com autorização expressa)
 *
 * Amostra: até 20 faturas variadas, as que existem nesta base (com a revisão gravada, se
 * houver) e as da pasta --folder (fora do git). Cada modelo lê a fatura sozinho, SEM o QR
 * (o prompt completo), para medir o modelo. Não altera nenhuma fatura gravada. Os resultados
 * ficam em storage/app/private/ocr-compare/ (fora do git), com o relatório em Markdown.
 */
class OcrCompareCommand extends Command
{
    protected $signature = 'ocr:compare
        {--execute : Faz as chamadas reais (sem isto, só simula)}
        {--folder=/tmp/ocr-amostra : Pasta com faturas extra (PDF, JPG, PNG, WebP), fora do git}
        {--limit=20 : Máximo de faturas}
        {--invoices= : Só estas faturas desta base (ids separados por vírgulas)}';

    protected $description = 'Compara os modelos do OCR das faturas (simulação por omissão; chamadas reais só com --execute).';

    public const OUT_DIR = 'ocr-compare';

    /** Estimativas para a simulação (tokens). */
    private const EST = [
        'prompt' => 1800, 'anthropic_page' => 2300, 'openai_page' => 1300, 'per_line_out' => 70, 'json_out' => 400,
        'reasoning' => ['low' => 1000, 'medium' => 3000, 'high' => 8000, 'default' => 8000],
    ];

    public function handle(InvoiceOcrService $ocr): int
    {
        $models = self::models();
        $sample = $this->sample($ocr);
        if ($sample === []) {
            $this->warn('Não há faturas para comparar (nem nesta base, nem na pasta).');

            return self::SUCCESS;
        }

        $this->info('Faturas escolhidas (' . count($sample) . '):');
        $this->table(['Ref.', 'Origem', 'Tipo', 'Páginas', 'QR', 'Linhas (gravadas)', 'Fornecedor', 'Revisão gravada'], array_map(fn ($f) => [
            $f['ref'], $f['origin'], $f['is_pdf'] ? 'PDF' . ($f['text_chars'] >= 200 ? ' com texto' : ' sem texto') : 'Fotografia', $f['pages'], $f['qr'] ? 'sim' : 'não',
            $f['lines'] ?? '?', $f['supplier'] ?? '?', $f['truth'] ? 'sim' : 'não',
        ], $sample));

        $calls = count($sample) * count($models);
        $rows = [];
        $total = 0.0;
        $unknown = false;
        foreach ($models as $m) {
            $cost = 0.0;
            $known = is_array(AiFunctionSettings::model($m['model'])['prices'] ?? null);
            foreach ($sample as $f) {
                [$in, $out] = $this->estimate($m, $f);
                $cost += (float) ($ocr->costUsd($m['model'], $in, $out) ?? 0);
            }
            $unknown = $unknown || ! $known;
            $total += $cost;
            $rows[] = [$m['label'], count($sample), $known ? number_format($cost, 2, ',', ' ') . ' USD' : 'preço por confirmar'];
        }
        $this->info("Chamadas: {$calls} (" . count($sample) . ' faturas × ' . count($models) . ' modelos).');
        $this->table(['Modelo', 'Chamadas', 'Custo estimado'], $rows);
        $this->line('Custo estimado total: ' . number_format($total, 2, ',', ' ') . ' USD' . ($unknown ? ' (sem os modelos com preço por confirmar)' : '')
            . '. É uma estimativa: o raciocínio e as páginas fazem variar o custo real.');
        $this->line('Chaves que vai usar (os valores não são mostrados):');
        foreach (['anthropic' => 'ANTHROPIC_API_KEY', 'openai' => 'OPENAI_KEY'] as $provider => $env) {
            if (in_array($provider, array_column($models, 'provider'), true)) {
                $key = $provider === 'anthropic' ? (string) config('ai.providers.anthropic.key') : (string) config('services.openai.key');
                $this->line("  · {$env}: " . ($key !== '' ? 'configurada' : 'EM FALTA'));
            }
        }

        if (! $this->option('execute')) {
            $this->warn('SIMULAÇÃO: nada foi enviado. Para correr: php artisan ocr:compare --execute (só depois de autorizado).');

            return self::SUCCESS;
        }

        return $this->runComparison($ocr, $sample, $models);
    }

    /** @return array<int, array{key: string, label: string, provider: string, model: string, effort: string}> */
    public static function models(): array
    {
        return [
            ['key' => 'fable', 'label' => 'claude-fable-5-1 (esforço do modelo)', 'provider' => 'anthropic', 'model' => 'claude-fable-5-1', 'effort' => 'default'],
            ['key' => 'opus-medio', 'label' => 'claude-opus-5-5 (médio)', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'medium'],
            ['key' => 'opus-alto', 'label' => 'claude-opus-5-5 (alto)', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'high'],
            ['key' => 'gpt', 'label' => 'gpt-6.1-sol (como hoje)', 'provider' => 'openai', 'model' => 'gpt-6.1-sol', 'effort' => (string) config('services.openai.ocr.reasoning_effort', 'low')],
        ];
    }

    /** As faturas: as da pasta (todas) e as desta base, variadas, até ao limite. */
    private function sample(InvoiceOcrService $ocr): array
    {
        $limit = max(1, (int) $this->option('limit'));
        $out = [];
        $hashes = [];
        $folder = (string) $this->option('folder');
        foreach (is_dir($folder) ? (glob(rtrim($folder, '/') . '/*') ?: []) : [] as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? null;
            if (! $mime || ! is_file($path)) {
                continue;
            }
            $bytes = (string) file_get_contents($path);
            if (isset($hashes[sha1($bytes)])) {
                continue;
            }
            $hashes[sha1($bytes)] = true;
            $out[] = $this->describe('pasta: ' . basename($path), 'pasta', $bytes, $mime, null, null, null, $ocr);
        }

        $ids = $this->option('invoices') ? array_map('intval', explode(',', (string) $this->option('invoices'))) : null;
        $dev = OcrInvoice::query()->withCount('lines')->whereNotIn('status', ['erro', 'processing', InvoiceOcrService::STATUS_NOT_OURS])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderByDesc('status')->orderBy('id')->get()
            ->sortByDesc(fn ($i) => $i->status === 'validada' ? 1 : 0);
        $suppliers = [];
        $later = [];
        foreach ($dev as $inv) {
            $bytes = Storage::disk((string) config('services.openai.ocr_disk', 'local'))->get((string) $inv->image_path);
            if (! $bytes || isset($hashes[sha1($bytes)])) {
                continue;
            }
            $hashes[sha1($bytes)] = true;
            $item = $this->describe("fatura #{$inv->id}", 'esta base', $bytes, (string) $inv->image_mime, $inv, (int) $inv->lines_count, $inv->supplier_nif, $ocr);
            // Variedade: primeiro um por fornecedor; os repetidos ficam para o fim.
            if (isset($suppliers[(string) $inv->supplier_nif])) {
                $later[] = $item;
            } else {
                $suppliers[(string) $inv->supplier_nif] = true;
                $out[] = $item;
            }
        }

        return array_slice(array_merge($out, $later), 0, $limit);
    }

    private function describe(string $ref, string $origin, string $bytes, string $mime, ?OcrInvoice $inv, ?int $lines, ?string $supplier, InvoiceOcrService $ocr): array
    {
        $isPdf = str_contains($mime, 'pdf');
        try {
            $info = $ocr->inspectFile($bytes, $mime);
        } catch (\Throwable) {
            $info = ['qr' => false, 'pages' => $isPdf ? max(1, preg_match_all('#/Type\s*/Page(?!s)#', $bytes)) : 1, 'text_chars' => 0];
        }

        return ['ref' => $ref, 'origin' => $origin, 'bytes' => $bytes, 'mime' => $mime, 'is_pdf' => $isPdf, 'pages' => $info['pages'], 'qr' => $info['qr'],
            'text_chars' => $info['text_chars'], 'lines' => $lines, 'supplier' => $supplier, 'invoice_id' => $inv?->id,
            'truth' => $inv && $inv->status === 'validada' ? OcrComparison::truthFrom($inv) : null];
    }

    /** @return array{0: int, 1: int} tokens de entrada e de saída estimados */
    private function estimate(array $m, array $f): array
    {
        $pages = max(1, (int) $f['pages']);
        $in = self::EST['prompt'] + $pages * ($m['provider'] === 'anthropic' ? self::EST['anthropic_page'] : self::EST['openai_page']);
        $out = self::EST['json_out'] + (int) ($f['lines'] ?? 15) * self::EST['per_line_out'] + (self::EST['reasoning'][$m['effort']] ?? 3000);

        return [$in, $out];
    }

    private function runComparison(InvoiceOcrService $ocr, array $sample, array $models): int
    {
        $results = [];
        foreach ($sample as $i => $f) {
            foreach ($models as $m) {
                $this->line("  {$f['ref']} · {$m['label']}…");
                $r = $ocr->readForComparison($m['provider'], $m['model'], $m['effort'], $f['bytes'], $f['mime']);
                $results[$i][$m['key']] = $r;
                $this->line('    ' . ($r['error'] ? "parou/erro: {$r['error']}" : count($r['clean']['lines'] ?? []) . ' linhas') . ", {$r['ms']} ms, " . ($r['cost_usd'] === null ? 'custo por confirmar' : number_format((float) $r['cost_usd'], 4) . ' USD'));
            }
        }
        $stamp = now()->format('Y-m-d-His');
        $raw = ['data' => now()->toIso8601String(), 'modelos' => $models, 'faturas' => array_map(fn ($f, $i) => array_diff_key($f, ['bytes' => 1]) + ['leituras' => $results[$i]], $sample, array_keys($sample))];
        Storage::disk('local')->put(self::OUT_DIR . "/resultados-{$stamp}.json", json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put(self::OUT_DIR . '/COMPARACAO-MODELOS.md', self::report($sample, $models, $results));
        $this->info('Resultados: storage/app/private/' . self::OUT_DIR . "/resultados-{$stamp}.json; relatório: storage/app/private/" . self::OUT_DIR . '/COMPARACAO-MODELOS.md');

        return self::SUCCESS;
    }

    /** O relatório (Markdown): os totais por modelo, por fatura e, sem revisão, as discordâncias. */
    public static function report(array $sample, array $models, array $results): string
    {
        $md = "# Comparação dos modelos do OCR das faturas\n\nCorrida a " . now()->format('d/m/Y H:i') . '. ' . count($sample) . ' faturas ('
            . count(array_filter($sample, fn ($f) => $f['truth'])) . ' com a revisão gravada), ' . count($models)
            . " modelos. Cada modelo leu cada fatura sozinho, sem o QR (o prompt completo). Nenhuma fatura gravada foi alterada.\n\n";

        $md .= "## Totais por modelo\n\n| Modelo | Leituras | Paradas ou erros | Campos certos (com revisão) | Linhas emparelhadas | Campos das linhas certos | Tempo médio | Custo médio por fatura | Custo total |\n|---|---|---|---|---|---|---|---|---|\n";
        foreach ($models as $m) {
            $ok = $stops = $fieldsOk = $fieldsAll = $matched = $truthLines = $lineOk = $lineAll = $ms = 0;
            $cost = 0.0;
            $costKnown = true;
            foreach ($sample as $i => $f) {
                $r = $results[$i][$m['key']] ?? null;
                if (! $r) {
                    continue;
                }
                $ms += $r['ms'];
                $costKnown = $costKnown && $r['cost_usd'] !== null;
                $cost += (float) $r['cost_usd'];
                if ($r['error']) {
                    $stops++;

                    continue;
                }
                $ok++;
                if ($f['truth']) {
                    $fl = OcrComparison::fields($f['truth'], $r['clean']);
                    $fieldsOk += count(array_filter($fl));
                    $fieldsAll += count($fl);
                    $ln = OcrComparison::lines($f['truth']['lines'], $r['clean']['lines']);
                    $matched += $ln['emparelhadas'];
                    $truthLines += $ln['verdade'];
                    $lineOk += array_sum($ln['campos']);
                    $lineAll += $ln['emparelhadas'] * count(OcrComparison::LINE_FIELDS);
                }
            }
            $n = count($sample);
            $md .= sprintf("| %s | %d | %d | %s | %s | %s | %s | %s | %s |\n", $m['label'], $ok, $stops,
                $fieldsAll ? self::pct($fieldsOk, $fieldsAll) : 'sem revisão', $truthLines ? "{$matched} de {$truthLines}" : 'sem revisão',
                $lineAll ? self::pct($lineOk, $lineAll) : 'sem revisão', $n ? number_format($ms / $n / 1000, 1, ',', ' ') . ' s' : '',
                $costKnown && $n ? number_format($cost / $n, 4, ',', ' ') . ' USD' : 'por confirmar', $costKnown ? number_format($cost, 4, ',', ' ') . ' USD' : 'por confirmar');
        }

        $md .= "\n## Por fatura\n\n| Fatura | Tipo | QR | " . implode(' | ', array_column($models, 'label')) . " |\n|---|---|---|" . str_repeat('---|', count($models)) . "\n";
        foreach ($sample as $i => $f) {
            $cells = [];
            foreach ($models as $m) {
                $r = $results[$i][$m['key']] ?? null;
                if (! $r || $r['error']) {
                    $cells[] = 'parou: ' . ($r['error'] ?? '?');

                    continue;
                }
                $cell = count($r['clean']['lines']) . ' linhas';
                if ($f['truth']) {
                    $fl = OcrComparison::fields($f['truth'], $r['clean']);
                    $ln = OcrComparison::lines($f['truth']['lines'], $r['clean']['lines']);
                    $cell = count(array_filter($fl)) . '/' . count($fl) . ' campos, ' . $ln['emparelhadas'] . '/' . $ln['verdade'] . ' linhas';
                }
                $cells[] = $cell . ', ' . number_format($r['ms'] / 1000, 1, ',', '') . ' s, ' . ($r['cost_usd'] === null ? '? USD' : number_format((float) $r['cost_usd'], 4, ',', '') . ' USD');
            }
            $md .= "| {$f['ref']} | " . ($f['is_pdf'] ? 'PDF' : 'Fotografia') . ' | ' . ($f['qr'] ? 'sim' : 'não') . ' | ' . implode(' | ', $cells) . " |\n";
        }

        $md .= "\n## Faturas sem revisão: os campos em que os modelos discordam\n\nPara confirmar no papel.\n";
        foreach ($sample as $i => $f) {
            if ($f['truth']) {
                continue;
            }
            $reads = [];
            foreach ($models as $m) {
                $r = $results[$i][$m['key']] ?? null;
                if ($r && ! $r['error']) {
                    $reads[$m['label']] = $r['clean'];
                }
            }
            $diff = count($reads) > 1 ? OcrComparison::disagreements($reads) : [];
            $md .= "\n### {$f['ref']}\n\n";
            if ($diff === []) {
                $md .= count($reads) > 1 ? "Todos os modelos leram o mesmo nos campos principais.\n" : "Menos de duas leituras válidas.\n";

                continue;
            }
            $md .= '| Campo | ' . implode(' | ', array_keys($reads)) . " |\n|---|" . str_repeat('---|', count($reads)) . "\n";
            foreach ($diff as $field => $values) {
                $md .= "| {$field} | " . implode(' | ', array_map(fn ($k) => str_replace('|', '/', (string) ($values[$k] ?? '')), array_keys($reads))) . " |\n";
            }
        }

        return $md . "\n## Recomendação\n\n(A escrever depois de ver os resultados.)\n";
    }

    private static function pct(int $a, int $b): string
    {
        return number_format($a / $b * 100, 1, ',', ' ') . '%';
    }
}
