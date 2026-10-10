<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinSupplier;
use App\Services\Ocr\AnthropicInvoiceReader;
use App\Services\Ocr\OcrModelStopped;
use App\Services\Ocr\OcrSchemas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * XPLENDOR — OCR de faturas de fornecedor. Reaproveita o MOTOR do XFIN (JSON mode +
 * prompt com schema embutido + anti-alucinação + validação humana), com prompt B2B
 * e extração das LINHAS.
 *
 * F2a — QR PRIMEIRO:
 *  1. O scraper (Python: zxing-cpp + poppler) lê o QR da AT, a camada de texto do PDF
 *     e, se preciso, rasteriza as páginas. SEM IA.
 *  2. Com QR válido o CABEÇALHO vem do QR (NIFs, tipo, data, nº, ATCUD, bases/IVA por
 *     taxa, totais). B tem de ser o NIF da empresa e A nunca o pode ser — senão a fatura
 *     fica 'nao_desta_empresa' e a IA NÃO é chamada.
 *  3. A IA só lê as LINHAS: pelo TEXTO (função ocr_text) se o PDF tiver texto útil, senão
 *     pelas IMAGENS de todas as páginas (função ocr_image). O fornecedor, o modelo e o esforço
 *     de cada função escolhem-se em Administração › Modelos de IA; sem escolha, valem
 *     OCR_MODEL_TEXT e OCR_MODEL_IMAGE do .env. Recebe as bases do QR para se auto-conferir.
 *  4. CONFERÊNCIA: soma das linhas por taxa = base do QR por taxa (tolerância configurável).
 *     Não confere → 1 nova tentativa (modelo de imagem se a 1.ª foi texto; mais esforço se
 *     já foi imagem). Fica sempre a melhor tentativa ('por_validar'), nunca se descarta.
 *  Sem QR legível → como antes (cabeçalho + linhas pela IA), marcado 'sem_qr'.
 *
 * ⚠️ SÓ LÊ/EXTRAI. NÃO escreve no PingWin. A gravação final só acontece quando o
 * utilizador VALIDA no ecrã (controller::update).
 */
class InvoiceOcrService
{
    // b2b-v3: + código do artigo do fornecedor nas linhas (caminho sem QR).
    public const PROMPT_VERSION = 'b2b-v3';
    // Caminho com QR: a IA só lê as linhas (o cabeçalho vem do QR).
    // linhas-v2 (F3): + guias referidas na fatura (para a ligação de faturas de guias ao PingWin).
    public const LINES_PROMPT_VERSION = 'linhas-v2';

    public const STATUS_NOT_OURS = 'nao_desta_empresa';
    public const CHECK_OK = 'confere';
    public const CHECK_MISMATCH = 'nao_confere';
    public const CHECK_NO_QR = 'sem_qr';

    private const OPENAI_CONNECT_TIMEOUT = 15;
    private const OPENAI_MAX_ATTEMPTS = 3;
    private const OPENAI_BACKOFF_MS = [500, 1500];
    private const MAX_TEXT_CHARS = 60000;

    /** O ficheiro da fatura em leitura (a Anthropic recebe-o diretamente). @var array{bytes: string, mime: string, is_pdf: bool} */
    private array $file = ['bytes' => '', 'mime' => '', 'is_pdf' => false];

    /**
     * Processa uma fatura (chamado pela fila). Ver o fluxo no topo. Marca a fatura
     * 'por_validar', 'nao_desta_empresa' ou 'erro'. NÃO escreve no PingWin.
     */
    public function process(int $invoiceId): void
    {
        $invoice = OcrInvoice::find($invoiceId);
        if (! $invoice) {
            return;
        }
        $started = hrtime(true);

        try {
            $bytes = Storage::disk($this->disk())->get($invoice->image_path);
            if ($bytes === null || $bytes === '') {
                throw new \RuntimeException('Ficheiro da fatura não encontrado no storage.');
            }
            $mime = (string) ($invoice->image_mime ?? 'image/jpeg');
            $isPdf = $this->isPdf($mime, $invoice->image_path);
            $cfg = $this->cfg();
            $this->file = ['bytes' => $bytes, 'mime' => $mime, 'is_pdf' => $isPdf];
            $imagePlan = $this->planFor('ocr_image', 'imagem');

            // 1) Leitura local (sem IA): QR + texto + (se o texto não chegar) imagens. A Anthropic
            //    lê o PDF diretamente, por isso não é preciso rasterizar.
            $an = $this->analyzeFile($bytes, $mime, $imagePlan['provider'] === 'anthropic' ? 'never' : 'auto');
            $qr = AtInvoiceQr::parse($an['qr']['raw'] ?? null);
            $qrOk = $qr !== null && $qr['valid'];
            $pages = (int) ($an['pages'] ?? 1);

            // 2) Validações do QR contra a empresa — antes de gastar IA.
            if ($qrOk && ($problem = $this->notOursProblem($qr, (int) $invoice->company_id))) {
                $this->persistNotOurs($invoice, $qr, $pages, $problem, $this->elapsedMs($started));

                return;
            }

            // 3) Linhas pela IA: texto se houver texto útil, senão imagens.
            $textOk = $isPdf && (int) ($an['text_chars'] ?? 0) >= $cfg['text_min_chars'];
            $images = $isPdf ? ($an['images'] ?? []) : [['page' => 1, 'mime' => $mime, 'base64' => base64_encode($bytes)]];
            $plan = $textOk ? $this->planFor('ocr_text', 'texto') : $imagePlan;

            $attempts = [];
            try {
                $best = $this->attempt($plan, $qrOk ? $qr : null, $an['text'] ?? '', $images, $invoice->company_id);
            } catch (OcrModelStopped $stop) {
                // Recusa ou resposta cortada: para revisão manual, com o motivo, sem repetir.
                $this->persistStopped($invoice, $plan, $stop, $qrOk ? $qr : null, $an, $this->elapsedMs($started));
                $this->linkAfterRead($invoiceId);

                return;
            }
            $attempts[] = $best;

            // 4) Conferência pelo QR (+ 1 nova tentativa se não conferir).
            if ($qrOk && ! $best['check']['ok']) {
                $retry = $plan['lines_source'] === 'texto'
                    ? $imagePlan
                    : ['effort' => $this->higherEffort($imagePlan['model'], $imagePlan['effort'])] + $imagePlan;
                try {
                    if ($retry['lines_source'] === 'imagem' && $images === []) {
                        $images = $this->analyzeFile($bytes, $mime, 'always')['images'] ?? [];
                    }
                    $second = $this->attempt($retry, $qr, $an['text'] ?? '', $images, $invoice->company_id);
                    $attempts[] = $second;
                    if ($second['check']['ok'] || $second['check']['abs_cents'] < $best['check']['abs_cents']) {
                        $best = $second;
                    }
                } catch (\Throwable $e) {
                    // A 2.ª tentativa falhou: fica a 1.ª (nunca se descarta o resultado).
                    Log::warning('[OCR Fatura] 2.ª tentativa falhou', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
                    $in = $e instanceof OcrModelStopped ? $e->tokensIn : 0;
                    $out = $e instanceof OcrModelStopped ? $e->tokensOut : 0;
                    $attempts[] = ['provider' => $retry['provider'], 'model' => $retry['model'], 'lines_source' => $retry['lines_source'], 'effort' => $retry['effort'],
                        'tokens_in' => $in, 'tokens_out' => $out, 'cost_usd' => $this->costUsd($retry['model'], $in, $out) ?? 0.0, 'ms' => 0, 'error' => mb_substr($e->getMessage(), 0, 300)];
                }
            }

            // Guias referidas (F3): do texto do PDF e/ou lidas pela IA (imagens).
            $best['clean']['guides'] = $this->mergeGuides(
                OcrPingwinLinkService::extractGuides((string) ($an['text'] ?? '')),
                (array) ($best['clean']['guides'] ?? [])
            );

            $this->persist($invoice, $best, $attempts, $qrOk ? $qr : null, $an, $this->elapsedMs($started));
        } catch (\Throwable $e) {
            Log::warning('[OCR Fatura] Falhou', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
            // Query direta: o modelo pode ter ficado "sujo" com a escrita que falhou.
            OcrInvoice::whereKey($invoiceId)->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e; // deixa o Job notificar/registar
        }

        $this->linkAfterRead($invoiceId);
    }

    /** Depois da leitura: as ligações ao PingWin e aos artigos (uma falha nunca estraga a leitura). */
    private function linkAfterRead(int $invoiceId): void
    {
        $invoice = OcrInvoice::find($invoiceId);
        if (! $invoice) {
            return;
        }
        // F3: liga ao documento do PingWin (espelhos; pesquisa viva do fornecedor por NIF, só
        // leitura — o processamento corre no worker). Uma falha aqui nunca estraga a leitura.
        try {
            app(OcrPingwinLinkService::class)->link($invoice->fresh(), true);
        } catch (\Throwable $e) {
            Log::warning('[OCR Fatura] ligação ao PingWin falhou', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
        }
        // F2b: liga cada linha a um artigo do catálogo (mapa → PingWin → descrição → sugestões).
        try {
            app(OcrLineArticleService::class)->linkInvoice($invoice->fresh());
        } catch (\Throwable $e) {
            Log::warning('[OCR Fatura] ligação das linhas a artigos falhou', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
        }
    }

    /** Guias únicas pela referência (a data lida no texto ganha à da IA). */
    private function mergeGuides(array $fromText, array $fromAi): array
    {
        $out = [];
        foreach (array_merge($fromText, $fromAi) as $g) {
            $ref = trim((string) ($g['ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $out[$ref] ??= ['ref' => $ref, 'date' => null];
            $out[$ref]['date'] ??= $g['date'] ?? null;
        }

        return array_values($out);
    }

    /**
     * Uma tentativa de leitura: chama a IA (texto ou imagens), sanitiza e confere com o QR.
     * Devolve o resultado + registo de custo (tokens, USD, ms).
     */
    private function attempt(array $plan, ?array $qr, string $text, array $images, int $companyId): array
    {
        if ($plan['model'] === null || $plan['model'] === '') {
            throw new \RuntimeException('Modelo de OCR não configurado: escolha-o em Modelos de IA (ou OCR_MODEL_' . ($plan['lines_source'] === 'texto' ? 'TEXT' : 'IMAGE') . ' no .env).');
        }
        if ($plan['provider'] !== 'anthropic' && $plan['lines_source'] === 'imagem' && $images === []) {
            throw new \RuntimeException('Sem imagens da fatura para a IA ler.');
        }

        $t0 = hrtime(true);
        $system = $qr ? $this->linesPrompt() : $this->prompt();
        $res = match ($plan['provider']) {
            'openai' => $this->callModel($plan['model'], $system, $this->userContent($plan['lines_source'], $qr, $text, $images), $plan['effort']),
            'anthropic' => $this->callAnthropic($plan, $system, $qr),
            default => throw new \RuntimeException("O OCR não lê faturas com o fornecedor \"{$plan['provider']}\"."),
        };
        $clean = $this->sanitize($this->decodeJson($res['content']));
        if (! $qr) {
            // ⚠️ O NIF do fornecedor NUNCA é o da própria empresa (esse é o cliente).
            $clean = $this->dropOwnCompanyIdentity($clean, $companyId);
        }

        return [
            'provider'     => $plan['provider'],
            'model'        => $plan['model'],
            'lines_source' => $plan['lines_source'],
            'effort'       => $plan['provider'] === 'anthropic' || $this->isReasoningModel($plan['model']) ? $plan['effort'] : null,
            'input'        => $res['input'] ?? ($plan['lines_source'] === 'texto' ? 'texto' : 'imagens'),
            'clean'        => $clean,
            'tokens_in'    => $res['tokens_in'],
            'tokens_out'   => $res['tokens_out'],
            'cost_usd'     => $this->costUsd($plan['model'], $res['tokens_in'], $res['tokens_out']),
            'ms'           => $this->elapsedMs($t0),
            'check'        => $qr ? $this->conference($clean['lines'], $qr) : ['ok' => false, 'abs_cents' => 0, 'rows' => []],
        ];
    }

    /** Leitura local, sem IA (scraper): tem QR, quantas páginas, quanto texto. Para a amostra do ocr:compare. */
    public function inspectFile(string $bytes, string $mime): array
    {
        $an = $this->analyzeFile($bytes, $mime, 'never');
        $qr = AtInvoiceQr::parse($an['qr']['raw'] ?? null);

        return ['qr' => $qr !== null && $qr['valid'], 'pages' => (int) ($an['pages'] ?? 1), 'text_chars' => (int) ($an['text_chars'] ?? 0)];
    }

    /**
     * Comparação dos modelos (ocr:compare): UMA leitura SEM o QR (o prompt completo, para medir
     * o modelo), com o fornecedor, o modelo e o esforço dados. Não grava nada. Anthropic: o
     * ficheiro original; OpenAI: como hoje no caminho das imagens (as páginas rasterizadas pelo
     * scraper, ou a fotografia).
     *
     * @return array{clean: ?array, tokens_in: int, tokens_out: int, cost_usd: ?float, ms: int, error: ?string}
     */
    public function readForComparison(string $provider, string $model, string $effort, string $bytes, string $mime): array
    {
        $isPdf = $this->isPdf($mime, '');
        $this->file = ['bytes' => $bytes, 'mime' => $mime, 'is_pdf' => $isPdf];
        $t0 = hrtime(true);
        try {
            if ($provider === 'anthropic') {
                $res = $this->callAnthropic(['function' => 'ocr_image', 'model' => $model, 'effort' => $effort], $this->prompt(), null);
            } else {
                $images = $isPdf ? ($this->analyzeFile($bytes, $mime, 'always')['images'] ?? []) : [['page' => 1, 'mime' => $mime, 'base64' => base64_encode($bytes)]];
                $res = $this->callModel($model, $this->prompt(), $this->userContent('imagem', null, '', $images), $effort);
            }

            return ['clean' => $this->sanitize($this->decodeJson($res['content'])), 'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'],
                'cost_usd' => $this->costUsd($model, $res['tokens_in'], $res['tokens_out']), 'ms' => $this->elapsedMs($t0), 'error' => null];
        } catch (OcrModelStopped $e) {
            return ['clean' => null, 'tokens_in' => $e->tokensIn, 'tokens_out' => $e->tokensOut,
                'cost_usd' => $this->costUsd($model, $e->tokensIn, $e->tokensOut), 'ms' => $this->elapsedMs($t0), 'error' => $e->reason];
        } catch (\Throwable $e) {
            return ['clean' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0.0, 'ms' => $this->elapsedMs($t0), 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * Anthropic: o ficheiro original (PDF como documento, fotografia como imagem), saída
     * estruturada com o esquema de hoje. Com QR, só as linhas (e as bases para se conferir).
     */
    protected function callAnthropic(array $plan, string $system, ?array $qr): array
    {
        $instruction = ($qr
            ? "Lê as LINHAS desta fatura de fornecedor para o JSON pedido.\n\n" . $this->qrHint($qr)
            : 'Extrai os dados desta fatura de fornecedor para o JSON pedido.')
            . "\n\nA fatura segue em anexo (" . ($this->file['is_pdf'] ? 'PDF' : 'fotografia') . ').';
        $maxTokens = (int) config("ai.functions.{$plan['function']}.max_tokens", 16000) + (int) config("ai.reasoning_headroom.{$plan['effort']}", 12000);

        return app(AnthropicInvoiceReader::class)->read($plan['model'], $plan['effort'], $system, $qr ? OcrSchemas::lines() : OcrSchemas::full(),
            $instruction, $this->file['bytes'], $this->file['mime'], $this->file['is_pdf'], $maxTokens);
    }

    /**
     * O fornecedor, o modelo e o esforço de uma função do OCR (Modelos de IA, ou a reserva do .env).
     *
     * @return array{lines_source: string, function: string, provider: string, model: string, effort: string}
     */
    private function planFor(string $function, string $source): array
    {
        $s = \App\Services\Ai\AiFunctionSettings::for($function);

        return ['lines_source' => $source, 'function' => $function, 'provider' => $s['provider'] ?: 'openai', 'model' => $s['model'], 'effort' => $s['effort']];
    }

    /** O esforço seguinte do modelo (2.ª tentativa); fora do catálogo, o OCR_RETRY_REASONING_EFFORT. */
    private function higherEffort(string $model, string $effort): string
    {
        $efforts = array_values(array_diff((array) (\App\Services\Ai\AiFunctionSettings::model($model)['efforts'] ?? []), ['default']));
        if ($efforts === []) {
            return $this->cfg()['retry_effort'];
        }
        if ($effort === 'default') {
            return end($efforts);
        }
        $i = array_search($effort, $efforts, true);

        return $i === false ? $this->cfg()['retry_effort'] : $efforts[min($i + 1, count($efforts) - 1)];
    }

    /** Mensagem do utilizador: texto do PDF ou imagens + (com QR) as bases para se conferir. */
    private function userContent(string $source, ?array $qr, string $text, array $images): array
    {
        $intro = $qr
            ? "Lê as LINHAS desta fatura de fornecedor para o JSON pedido.\n\n" . $this->qrHint($qr)
            : 'Extrai os dados desta fatura de fornecedor para o JSON pedido.';

        if ($source === 'texto') {
            $pages = array_values(array_filter(explode("\f", $text), fn ($p) => trim($p) !== ''));
            $body = '';
            foreach ($pages as $i => $p) {
                $body .= "\n--- página " . ($i + 1) . " ---\n" . rtrim($p) . "\n";
            }

            return [['type' => 'text', 'text' => $intro . "\n\nTEXTO DA FATURA (extraído do PDF, layout preservado):\n" . mb_substr($body, 0, self::MAX_TEXT_CHARS)]];
        }

        $parts = [['type' => 'text', 'text' => $intro . "\n\nA fatura segue em " . count($images) . ' imagem(ns), uma por página, por ordem.']];
        foreach ($images as $img) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . ($img['mime'] ?? 'image/jpeg') . ';base64,' . $img['base64'], 'detail' => 'high']];
        }

        return $parts;
    }

    /** As bases do QR por taxa, para a IA se auto-conferir (não para as copiar). */
    private function qrHint(array $qr): string
    {
        $rows = array_map(fn ($r) => sprintf('  · IVA %s%%: base %s €', $r['rate'], number_format($r['base_cents'] / 100, 2, '.', '')), $qr['by_rate']);

        return "AUTO-CONFERÊNCIA — o QR oficial desta fatura (AT) diz que a soma das linhas (totalLinha, sem IVA) por taxa de IVA é:\n"
            . implode("\n", $rows)
            . "\nUsa isto para confirmares que não te faltou nem sobrou nenhuma linha. NÃO alteres valores lidos para forçar a soma: se não fechar, devolve o que está escrito na fatura.";
    }

    /**
     * Conferência POR TAXA: soma de line_total_cents das linhas vs base do QR. Linhas sem
     * taxa contam como divergência. Tolerância por taxa em config (cêntimos).
     */
    public function conference(array $lines, array $qr): array
    {
        $tol = (int) $this->cfg()['tolerance_cents'];
        $sums = [];
        foreach ($lines as $l) {
            $key = $l['vat_rate'] === null ? 'sem_taxa' : (string) $l['vat_rate'];
            $sums[$key] = ($sums[$key] ?? 0) + (int) ($l['line_total_cents'] ?? 0);
        }
        $qrBases = [];
        foreach ($qr['by_rate'] as $r) {
            $qrBases[(string) $r['rate']] = (int) $r['base_cents'];
        }

        $rows = [];
        $ok = true;
        $abs = 0;
        $keys = array_unique(array_merge(array_keys($qrBases), array_keys($sums)));
        usort($keys, fn ($a, $b) => (float) $a <=> (float) $b);
        foreach ($keys as $k) {
            $q = $qrBases[$k] ?? 0;
            $s = $sums[$k] ?? 0;
            $diff = $s - $q;
            if ($k === 'sem_taxa' && $s === 0) {
                continue;
            }
            $rowOk = abs($diff) <= $tol && $k !== 'sem_taxa';
            $ok = $ok && $rowOk;
            $abs += abs($diff);
            $rows[] = ['rate' => $k === 'sem_taxa' ? null : (int) $k, 'qr_cents' => $q, 'lines_cents' => $s, 'diff_cents' => $diff, 'ok' => $rowOk];
        }

        return ['ok' => $ok && $rows !== [], 'abs_cents' => $abs, 'rows' => $rows];
    }

    /** B tem de ser o NIF da empresa; A nunca o pode ser. Devolve a mensagem ou null. */
    private function notOursProblem(array $qr, int $companyId): ?string
    {
        $ownNif = preg_replace('/\D+/', '', (string) Company::where('id', $companyId)->value('nipc'));
        if ($ownNif === '') {
            return null; // empresa sem NIF configurado: não há com que comparar
        }
        if ($qr['issuer_nif'] === $ownNif) {
            return "Esta fatura foi emitida pela própria empresa (NIF emitente {$ownNif} no QR) — não é uma fatura de fornecedor.";
        }
        if ($qr['buyer_nif'] !== $ownNif) {
            return "Esta fatura não é desta empresa: o NIF do adquirente no QR é {$qr['buyer_nif']}, o da empresa é {$ownNif}.";
        }

        return null;
    }

    /**
     * Chama a OpenAI (chat completions, JSON mode). Modelos de raciocínio levam
     * reasoning_effort e não levam temperature. Devolve conteúdo + tokens.
     */
    protected function callModel(string $model, string $system, array $userContent, ?string $effort): array
    {
        $apiKey = (string) config('services.openai.key');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_KEY não configurada.');
        }

        $payload = [
            'model'                 => $model,
            'max_completion_tokens' => $this->cfg()['max_output_tokens'],
            'response_format'       => ['type' => 'json_object'], // JSON mode
            'messages'              => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];
        if ($this->isReasoningModel($model)) {
            if ($effort) {
                $payload['reasoning_effort'] = $effort;
            }
        } else {
            $payload['temperature'] = 0;
        }

        $lastException = null;
        for ($attempt = 1; $attempt <= self::OPENAI_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->connectTimeout(self::OPENAI_CONNECT_TIMEOUT)
                    ->timeout($this->cfg()['http_timeout'])
                    ->acceptJson()
                    ->post('https://api.openai.com/v1/chat/completions', $payload);

                if ($response->failed()) {
                    $status = $response->status();
                    if (in_array($status, [429, 500, 502, 503, 504], true) && $attempt < self::OPENAI_MAX_ATTEMPTS) {
                        usleep(self::OPENAI_BACKOFF_MS[$attempt - 1] * 1000);
                        continue;
                    }
                    throw new \RuntimeException("OpenAI HTTP {$status}: " . mb_substr((string) $response->json('error.message', $response->body()), 0, 300));
                }

                $content = $response->json('choices.0.message.content');
                if (! is_string($content) || trim($content) === '') {
                    throw new \RuntimeException('OpenAI devolveu conteúdo vazio (finish_reason: ' . $response->json('choices.0.finish_reason') . ').');
                }

                return [
                    'content'    => $content,
                    'tokens_in'  => (int) $response->json('usage.prompt_tokens', 0),
                    'tokens_out' => (int) $response->json('usage.completion_tokens', 0),
                ];
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt === self::OPENAI_MAX_ATTEMPTS || str_contains($e->getMessage(), 'OpenAI HTTP 4')) {
                    break; // 4xx (exceto 429) não se repete
                }
                usleep(self::OPENAI_BACKOFF_MS[$attempt - 1] * 1000);
            }
        }

        throw new \RuntimeException('OpenAI indisponível ao ler a fatura: ' . ($lastException?->getMessage() ?? ''), previous: $lastException);
    }

    /**
     * Leitura local do ficheiro pelo scraper (Python: zxing-cpp + poppler), por docker exec
     * (o worker tem o socket) — mesmo padrão do PingWin. $images: auto|always|never.
     */
    protected function analyzeFile(string $bytes, string $mime, string $images): array
    {
        $cfg = $this->cfg();
        $payload = json_encode([
            'mode' => 'analyze', 'file_base64' => base64_encode($bytes), 'mime' => $mime,
            'max_pages' => $cfg['max_pages'], 'text_min_chars' => $cfg['text_min_chars'], 'images' => $images,
        ]);
        $process = new Process([
            'docker', 'exec', '-i', env('SCRAPER_CONTAINER', 'xplendor-scraper'),
            'python', '/scraper/sources/ocr/run.py',
        ]);
        $process->setTimeout(180);
        $process->setInput($payload);
        $process->run();

        $out = trim($process->getOutput());
        if ($out === '') {
            throw new \RuntimeException('Leitura do ficheiro falhou (scraper sem resposta): ' . mb_substr($process->getErrorOutput(), 0, 300));
        }
        $data = json_decode($out, true);
        if (! is_array($data) || ! ($data['ok'] ?? false)) {
            throw new \RuntimeException('Leitura do ficheiro falhou: ' . mb_substr((string) ($data['error'] ?? $out), 0, 300));
        }

        return $data;
    }

    /** Só o texto do PDF (scraper, SEM IA) — para extrair guias de faturas lidas antes da F3. */
    public function analyzeText(string $bytes): ?string
    {
        return $this->analyzeFile($bytes, 'application/pdf', 'never')['text'] ?? null;
    }

    /** Config do OCR (services.openai.ocr) normalizada. */
    private function cfg(): array
    {
        $c = (array) config('services.openai.ocr', []);

        // O modelo e o esforço de cada leitura vêm de Modelos de IA (planFor); o .env é a reserva.
        return [
            'retry_effort'      => $c['retry_reasoning_effort'] ?? 'medium',
            'reasoning_prefixes' => array_filter(array_map('trim', explode(',', (string) ($c['reasoning_model_prefixes'] ?? '')))),
            'max_pages'         => max(1, (int) ($c['max_pages'] ?? 10)),
            'text_min_chars'    => (int) ($c['text_min_chars'] ?? 200),
            'tolerance_cents'   => (int) ($c['check_tolerance_cents'] ?? 2),
            'max_output_tokens' => (int) ($c['max_output_tokens'] ?? 16000),
            'http_timeout'      => (int) ($c['http_timeout'] ?? 240),
            'prices'            => (string) ($c['prices'] ?? ''),
        ];
    }

    private function isReasoningModel(string $model): bool
    {
        foreach ($this->cfg()['reasoning_prefixes'] as $p) {
            if ($p !== '' && str_starts_with($model, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Custo estimado (USD): pelos preços do catálogo da IA (config/ai.php, os mesmos da lista
     * "Modelos de IA"); fora dele, pelos de OCR_PRICES ("modelo=entrada/saída;…" por 1M tokens).
     * Null se o modelo não tiver preço.
     */
    public function costUsd(string $model, int $in, int $out): ?float
    {
        $catalog = \App\Services\Ai\AiFunctionSettings::model($model)['prices'] ?? null;
        if (is_array($catalog)) {
            return round($in * (float) $catalog['input'] / 1e6 + $out * (float) $catalog['output'] / 1e6, 6);
        }
        $best = null;
        foreach (explode(';', $this->cfg()['prices']) as $entry) {
            if (! preg_match('/^\s*([^=]+?)\s*=\s*([\d.]+)\s*\/\s*([\d.]+)\s*$/', $entry, $m)) {
                continue;
            }
            // Igual ou prefixo (ex.: "gpt-4o-mini" cobre "gpt-4o-mini-2024-07-18"); o mais longo ganha.
            if (($model === $m[1] || str_starts_with($model, $m[1] . '-')) && ($best === null || strlen($m[1]) > strlen($best[0]))) {
                $best = [$m[1], (float) $m[2], (float) $m[3]];
            }
        }

        return $best === null ? null : round($in * $best[1] / 1e6 + $out * $best[2] / 1e6, 6);
    }

    private function elapsedMs(int|float $t0): int
    {
        return (int) round((hrtime(true) - $t0) / 1e6);
    }

    // -------------------------------------------------------------- SANITIZAÇÃO

    /** JSON.parse seco (confia no JSON mode); tolera ruído extraindo o objeto. */
    private function decodeJson(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);
        if (! is_array($decoded)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            }
        }
        if (! is_array($decoded)) {
            throw new \RuntimeException('A IA devolveu um JSON inválido.');
        }

        return $decoded;
    }

    /**
     * Sanitiza a resposta da IA: tipos, datas (regex), taxas IVA ∈ {6,13,23},
     * números → CÊNTIMOS. Descarta linhas vazias/lixo. Devolve estrutura limpa
     * pronta a persistir (valores já em cêntimos).
     */
    public function sanitize(array $raw): array
    {
        $forn = is_array($raw['fornecedor'] ?? null) ? $raw['fornecedor'] : [];
        $sum = is_array($raw['sumario'] ?? null) ? $raw['sumario'] : [];

        $lines = [];
        foreach (($raw['linhas'] ?? []) as $l) {
            if (! is_array($l)) {
                continue;
            }
            $item = $this->str($l['item'] ?? null);
            $qty = $this->num($l['quantidade'] ?? null);
            $unitPrice = $this->dec6($l['precoUnitario'] ?? null); // F2b: 6 casas, nunca arredondar
            $lineTotal = $this->cents($l['totalLinha'] ?? null);
            // Linha só entra se tiver ALGO de útil (nome ou algum valor).
            if ($item === null && $qty === null && $unitPrice === null && $lineTotal === null) {
                continue;
            }
            $lines[] = [
                'supplier_code'    => $this->code($l['codigo'] ?? null),
                'item'             => $item,
                'quantity'         => $qty,
                'unit'             => $this->str($l['unidade'] ?? null),
                'unit_price'       => $unitPrice,
                'discount_pct'     => $this->pct($l['descontoPct'] ?? null),
                'line_total_cents' => $lineTotal,
                'vat_rate'         => $this->vat($l['taxaIva'] ?? null),
            ];
        }

        $vatBreakdown = [];
        foreach (($sum['ivaPorTaxa'] ?? []) as $b) {
            if (! is_array($b)) {
                continue;
            }
            $rate = $this->vat($b['taxa'] ?? null);
            if ($rate === null) {
                continue;
            }
            $vatBreakdown[] = [
                'rate'      => $rate,
                'base_cents' => (int) $this->cents($b['base'] ?? 0),
                'vat_cents'  => (int) $this->cents($b['iva'] ?? 0),
            ];
        }

        return [
            'supplier_name' => $this->str($forn['nome'] ?? ($raw['fornecedorNome'] ?? null)),
            'supplier_nif'  => $this->nif($forn['nif'] ?? null),
            'number'        => $this->str($raw['numeroFatura'] ?? null),
            'issue_date'    => $this->date($raw['dataEmissao'] ?? null),
            'lines'         => $lines,
            'guides'        => $this->guides($raw['guias'] ?? []),
            'summary'       => [
                'goods_total_cents'         => (int) $this->cents($sum['totalMercadorias'] ?? 0),
                'commercial_discount_cents' => (int) $this->cents($sum['descontoComercial'] ?? 0),
                'taxable_base_cents'        => (int) $this->cents($sum['baseTributavel'] ?? 0),
                'vat_total_cents'           => (int) $this->cents($sum['valorIvaTotal'] ?? 0),
                'withholding_cents'         => (int) $this->cents($sum['retencaoFonte'] ?? 0),
                'financial_discount_cents'  => (int) $this->cents($sum['descontoFinanceiro'] ?? 0),
                'total_cents'               => (int) $this->cents($sum['total'] ?? 0),
                'vat_breakdown'             => $vatBreakdown,
            ],
        ];
    }

    /**
     * Score de confiança (heurística de completude, como o XFIN): % de campos-chave
     * presentes (fornecedor, nif 9 díg, nº, data, total) + se há linhas + se as
     * linhas fecham com o total (coerência). 0..100.
     */
    public function confidence(array $clean): int
    {
        $checks = [];
        $checks[] = ($clean['supplier_name'] ?? null) !== null;
        $checks[] = is_string($clean['supplier_nif'] ?? null) && preg_match('/^\d{9}$/', $clean['supplier_nif']) === 1;
        $checks[] = ($clean['number'] ?? null) !== null;
        $checks[] = ($clean['issue_date'] ?? null) !== null;
        $total = (int) ($clean['summary']['total_cents'] ?? 0);
        $checks[] = $total > 0;
        $checks[] = ! empty($clean['lines']);

        // Coerência: soma das linhas ≈ base tributável (margem 5%).
        $linesSum = array_sum(array_map(fn ($l) => (int) ($l['line_total_cents'] ?? 0), $clean['lines'] ?? []));
        $base = (int) ($clean['summary']['taxable_base_cents'] ?? 0);
        $checks[] = $base > 0 && abs($linesSum - $base) <= max(5, (int) round($base * 0.05));

        $passed = count(array_filter($checks));

        return (int) round($passed / count($checks) * 100);
    }

    // ------------------------------------------------------------- PERSISTÊNCIA

    /**
     * Persiste o resultado ESCOLHIDO (a melhor tentativa) + registo de custo/origem/conferência.
     * Com QR: cabeçalho e sumário vêm do QR (a IA só deu as linhas e o nome). Marca 'por_validar'.
     */
    private function persist(OcrInvoice $invoice, array $best, array $attempts, ?array $qr, array $an, int $ms): void
    {
        $clean = $best['clean'];
        if ($qr) {
            $clean['supplier_nif'] = $qr['issuer_nif'];
            $clean['number'] = $qr['number'];
            $clean['issue_date'] = $qr['issue_date'];
            $clean['summary'] = $this->summaryFromQr($qr);
        }
        $supplierId = $this->matchSupplier($invoice->company_id, $clean['supplier_nif'], $clean['supplier_name']);
        if ($qr && $clean['supplier_name'] === null && $supplierId) {
            $clean['supplier_name'] = PingwinSupplier::whereKey($supplierId)->value('name');
        }
        $confidence = $this->confidence($clean);
        $check = $best['check'];

        DB::transaction(function () use ($invoice, $clean, $confidence, $supplierId, $best, $attempts, $qr, $an, $ms, $check) {
            $invoice->lines()->delete();
            $invoice->summary()->delete();

            $invoice->update([
                'supplier_id'    => $supplierId,
                'supplier_name'  => $clean['supplier_name'],
                'supplier_nif'   => $clean['supplier_nif'],
                'buyer_nif'      => $qr['buyer_nif'] ?? null,
                'number'         => $clean['number'],
                'atcud'          => $qr['atcud'] ?? null,
                'doc_type'       => $qr['doc_type'] ?? null,
                'issue_date'     => $clean['issue_date'],
                'model'          => $best['model'],
                'ai_provider'    => $best['provider'] ?? null,
                'ai_effort'      => $best['effort'] ?? null,
                'prompt_version' => $qr ? self::LINES_PROMPT_VERSION : self::PROMPT_VERSION,
                'confidence'     => $confidence,
                'status'         => 'por_validar',
                'error_message'  => null,
                'qr_raw'         => $an['qr']['raw'] ?? null,
                'qr_ok'          => $qr !== null,
                'qr_data'        => $qr ? $this->qrData($qr, $an) : null,
                'source'         => $qr ? 'qr+' . $best['lines_source'] : 'sem_qr',
                'lines_source'   => $best['lines_source'],
                'pages'          => (int) ($an['pages'] ?? 1),
                'tokens_in'      => array_sum(array_column($attempts, 'tokens_in')),
                'tokens_out'     => array_sum(array_column($attempts, 'tokens_out')),
                'cost_usd'       => $this->sumCost($attempts),
                'duration_ms'    => $ms,
                'attempts'       => count($attempts),
                'attempts_log'   => $this->attemptsLog($attempts, $best),
                'check_status'   => $qr ? ($check['ok'] ? self::CHECK_OK : self::CHECK_MISMATCH) : self::CHECK_NO_QR,
                'check_diff'     => $qr ? $check['rows'] : null,
                'guide_refs'     => ($clean['guides'] ?? []) ?: null,
            ]);

            $pos = 0;
            foreach ($clean['lines'] as $line) {
                OcrInvoiceLine::create(array_merge($line, [
                    'ocr_invoice_id' => $invoice->id,
                    'company_id'     => $invoice->company_id,
                    'position'       => $pos++,
                ]));
            }

            OcrInvoiceSummary::create(array_merge($clean['summary'], [
                'ocr_invoice_id' => $invoice->id,
                'company_id'     => $invoice->company_id,
            ]));
        });
    }

    /**
     * A IA parou sem leitura (recusa ou resposta cortada): a fatura fica 'por_validar', sem
     * linhas, com o cabeçalho do QR (se houver) e o motivo em error_message, para revisão manual.
     * Os tokens gastos contam no custo.
     */
    private function persistStopped(OcrInvoice $invoice, array $plan, OcrModelStopped $stop, ?array $qr, array $an, int $ms): void
    {
        $empty = ['supplier_name' => null, 'supplier_nif' => null, 'number' => null, 'issue_date' => null, 'lines' => [], 'guides' => [],
            'summary' => ['goods_total_cents' => 0, 'commercial_discount_cents' => 0, 'taxable_base_cents' => 0, 'vat_total_cents' => 0,
                'withholding_cents' => 0, 'financial_discount_cents' => 0, 'total_cents' => 0, 'vat_breakdown' => []]];
        $attempt = [
            'provider' => $plan['provider'], 'model' => $plan['model'], 'lines_source' => $plan['lines_source'], 'effort' => $plan['effort'],
            'clean' => $empty, 'tokens_in' => $stop->tokensIn, 'tokens_out' => $stop->tokensOut,
            'cost_usd' => $this->costUsd($plan['model'], $stop->tokensIn, $stop->tokensOut), 'ms' => $ms,
            'check' => $qr ? $this->conference([], $qr) : ['ok' => false, 'abs_cents' => 0, 'rows' => []], 'error' => $stop->reason,
        ];
        $this->persist($invoice, $attempt, [$attempt], $qr, $an, $ms);
        OcrInvoice::whereKey($invoice->id)->update(['error_message' => mb_substr($stop->getMessage(), 0, 500)]);
        Log::warning('[OCR Fatura] A IA parou sem leitura: fica para revisão manual', ['invoice_id' => $invoice->id, 'motivo' => $stop->reason, 'modelo' => $plan['model']]);
    }

    /** QR de outra empresa (ou emitido pela própria): cabeçalho do QR, sem linhas, sem IA. */
    private function persistNotOurs(OcrInvoice $invoice, array $qr, int $pages, string $message, int $ms): void
    {
        DB::transaction(function () use ($invoice, $qr, $pages, $message, $ms) {
            $invoice->lines()->delete();
            $invoice->summary()->delete();
            $invoice->update([
                'supplier_id'   => null,
                'supplier_nif'  => $qr['issuer_nif'],
                'buyer_nif'     => $qr['buyer_nif'],
                'number'        => $qr['number'],
                'atcud'         => $qr['atcud'],
                'doc_type'      => $qr['doc_type'],
                'issue_date'    => $qr['issue_date'],
                'model'         => null,
                'ai_provider'   => null,
                'ai_effort'     => null,
                'confidence'    => 0,
                'status'        => self::STATUS_NOT_OURS,
                'error_message' => $message,
                'qr_raw'        => $qr['raw'],
                'qr_ok'         => true,
                'qr_data'       => $this->qrData($qr, []),
                'source'        => 'qr',
                'lines_source'  => null,
                'pages'         => $pages,
                'tokens_in'     => 0,
                'tokens_out'    => 0,
                'cost_usd'      => 0,
                'duration_ms'   => $ms,
                'attempts'      => 0,
                'attempts_log'  => [],
                'check_status'  => null,
                'check_diff'    => null,
            ]);
        });
        Log::info('[OCR Fatura] Não é desta empresa — parado antes da IA', ['invoice_id' => $invoice->id, 'buyer_nif' => $qr['buyer_nif']]);
    }

    /** Sumário (cêntimos) a partir do QR: bases/IVA por taxa, total IVA (N), total (O), retenção (P). */
    private function summaryFromQr(array $qr): array
    {
        $base = AtInvoiceQr::taxableBaseCents($qr);

        return [
            'goods_total_cents'         => $base,
            'commercial_discount_cents' => 0,
            'taxable_base_cents'        => $base,
            'vat_total_cents'           => (int) ($qr['vat_total_cents'] ?? array_sum(array_column($qr['by_rate'], 'vat_cents'))),
            'withholding_cents'         => (int) ($qr['withholding_cents'] ?? 0),
            'financial_discount_cents'  => 0,
            'total_cents'               => (int) $qr['total_cents'],
            'vat_breakdown'             => array_map(fn ($r) => ['rate' => $r['rate'], 'base_cents' => $r['base_cents'], 'vat_cents' => $r['vat_cents']], $qr['by_rate']),
        ];
    }

    /** O que se guarda do QR (campos crus + derivados + onde foi lido). */
    private function qrData(array $qr, array $an): array
    {
        return [
            'fields'  => $qr['fields'],
            'by_rate' => $qr['by_rate'],
            'spaces'  => $qr['spaces'],
            'page'    => $an['qr']['page'] ?? null,
            'dpi'     => $an['qr']['dpi'] ?? null,
            'copies_dropped' => $an['copies_dropped'] ?? [],
            'truncated' => (bool) ($an['truncated'] ?? false),
        ];
    }

    private function sumCost(array $attempts): ?float
    {
        $costs = array_column($attempts, 'cost_usd');
        if (in_array(null, $costs, true)) {
            return null; // há um modelo sem preço configurado → custo desconhecido
        }

        return round(array_sum($costs), 6);
    }

    private function attemptsLog(array $attempts, array $best): array
    {
        return array_values(array_map(fn ($a, $i) => [
            'n'            => $i + 1,
            'provider'     => $a['provider'] ?? null,
            'model'        => $a['model'],
            'input'        => $a['input'] ?? null,
            'lines_source' => $a['lines_source'],
            'effort'       => $a['effort'] ?? null,
            'tokens_in'    => $a['tokens_in'],
            'tokens_out'   => $a['tokens_out'],
            'cost_usd'     => $a['cost_usd'],
            'ms'           => $a['ms'],
            'lines'        => isset($a['clean']) ? count($a['clean']['lines']) : null,
            'check_ok'     => $a['check']['ok'] ?? null,
            'diff_abs_cents' => $a['check']['abs_cents'] ?? null,
            'chosen'       => $a === $best,
            'error'        => $a['error'] ?? null,
        ], $attempts, array_keys($attempts)));
    }

    /**
     * Descarta a identidade da PRÓPRIA empresa (o cliente) se a IA a trouxe como
     * fornecedor. O NIF do fornecedor NUNCA é o da empresa; se bater, anula o NIF
     * (e o nome se coincidir com a designação fiscal da empresa) — evita ligar a
     * fatura ao "fornecedor" errado. Devolve o $clean corrigido.
     */
    private function dropOwnCompanyIdentity(array $clean, int $companyId): array
    {
        $company = \App\Models\Company::find($companyId);
        if (! $company) {
            return $clean;
        }
        $ownNif = preg_replace('/\D+/', '', (string) $company->nipc);
        $supNif = $clean['supplier_nif'] ?? null;

        if ($ownNif !== '' && $supNif !== null && $supNif === $ownNif) {
            Log::info('[OCR Fatura] NIF do fornecedor = NIF da própria empresa → descartado (era o cliente).', ['company_id' => $companyId]);
            $clean['supplier_nif'] = null;
            // Se o nome também é o da própria empresa, é o cliente → anula também.
            $ownName = mb_strtolower(trim((string) $company->fiscal_name));
            if ($ownName !== '' && mb_strtolower(trim((string) ($clean['supplier_name'] ?? ''))) === $ownName) {
                $clean['supplier_name'] = null;
            }
        }

        return $clean;
    }

    /** Liga ao fornecedor sincronizado por NIF (exato) ou nome (case-insensitive). */
    private function matchSupplier(int $companyId, ?string $nif, ?string $name): ?int
    {
        if ($nif !== null && preg_match('/^\d{9}$/', $nif) === 1) {
            $byNif = PingwinSupplier::where('company_id', $companyId)->where('tax_number', $nif)->value('id');
            if ($byNif) {
                return (int) $byNif;
            }
        }
        if ($name !== null && trim($name) !== '') {
            $byName = PingwinSupplier::where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
                ->value('id');
            if ($byName) {
                return (int) $byName;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- COERÇÕES

    private function isPdf(string $mime, string $path): bool
    {
        return str_contains(strtolower($mime), 'pdf') || str_ends_with(strtolower($path), '.pdf');
    }

    private function disk(): string
    {
        return (string) config('services.openai.ocr_disk', 'local');
    }

    private function str($v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /** NIF: só dígitos; devolve string ou null. */
    private function nif($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $v);

        return $digits === '' ? null : $digits;
    }

    /** Data ISO válida (YYYY-MM-DD) ou null. */
    private function date($v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }

    private function num($v): ?float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    /** Decimal com 6 casas como string (preço unitário com precisão total), ou null. */
    private function dec6($v): ?string
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return number_format((float) $v, 6, '.', '');
    }

    /** € (número) → cêntimos inteiros, ou null se não numérico. */
    private function cents($v): ?int
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (int) round(((float) $v) * 100);
    }

    private function pct($v): ?float
    {
        $n = $this->num($v);
        if ($n === null) {
            return null;
        }

        return max(0.0, min(100.0, $n));
    }

    /**
     * Taxas válidas: as dos espaços fiscais em config (PT 6/13/23, Açores, Madeira) + 0
     * (isento). Usado também pela validação do controller.
     */
    public static function validVatRates(): array
    {
        $rates = [0];
        foreach ((array) config('services.openai.ocr.vat_rates', ['PT' => [6, 13, 23]]) as $list) {
            $rates = array_merge($rates, array_map('intval', (array) $list));
        }
        $rates = array_values(array_unique($rates));
        sort($rates);

        return $rates;
    }

    /** Guias lidas pela IA: [{"numero": "GT 3105/2026", "data": "2026-05-02"}] → [{ref, date}]. */
    private function guides($raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $g) {
            $ref = is_array($g) ? $this->str($g['numero'] ?? null) : $this->str($g);
            if ($ref !== null) {
                $out[] = ['ref' => mb_substr($ref, 0, 60), 'date' => is_array($g) ? $this->date($g['data'] ?? null) : null];
            }
        }

        return $out;
    }

    /** Código do artigo do fornecedor (texto curto) ou null. */
    private function code($v): ?string
    {
        $s = $this->str(is_int($v) || is_float($v) ? (string) $v : $v);

        return $s === null ? null : mb_substr($s, 0, 60);
    }

    /** Taxa de IVA válida (ver validVatRates) ou null (descarta lixo). */
    private function vat($v): ?int
    {
        if (! is_numeric($v)) {
            return null;
        }
        $r = (int) round((float) $v);

        return in_array($r, self::validVatRates(), true) ? $r : null;
    }

    /**
     * PROMPT B2B — reaproveita a técnica do XFIN (schema embutido, anti-alucinação,
     * exemplo concreto, coerção aritmética) MAS pede a CADEIA COMPLETA da fatura de
     * fornecedor (não o "total pago" B2C).
     */
    public function prompt(): string
    {
        return <<<'PROMPT'
És um extrator de dados de FATURAS DE FORNECEDOR portuguesas (B2B). Recebes a imagem de uma fatura e devolves SÓ um objeto JSON válido (sem texto à volta) com este schema EXATO:

{
  "fornecedor": {"nome": "string", "nif": "string (9 dígitos)"},
  "numeroFatura": "string",
  "dataEmissao": "YYYY-MM-DD",
  "linhas": [
    {"codigo": "string (código do artigo do fornecedor)", "item": "string", "quantidade": number, "unidade": "string (un/kg/cx/L...)", "precoUnitario": number, "descontoPct": number, "totalLinha": number, "taxaIva": 6|13|23}
  ],
  "sumario": {
    "totalMercadorias": number, "descontoComercial": number, "baseTributavel": number,
    "ivaPorTaxa": [{"taxa": 6|13|23, "base": number, "iva": number}],
    "valorIvaTotal": number, "retencaoFonte": number, "descontoFinanceiro": number, "total": number
  }
}

⚠️ FORNECEDOR = O EMISSOR (quem EMITE e vende), NUNCA o cliente/adquirente/destinatário.
- Numa fatura portuguesa há DOIS NIFs: o do EMISSOR (a empresa que fatura) e o do ADQUIRENTE (o cliente que recebe). Queremos SÓ o do EMISSOR.
- O EMISSOR está no TOPO/cabeçalho, junto ao logótipo/nome da empresa que emite e à menção "Contribuinte nº"/"NIF"/"NIPC" do emitente.
- O CLIENTE aparece como DESTINATÁRIO, muitas vezes após "Exmo(s). Sr(s)", "Cliente", "Adquirente", "Faturar a", "Nome/Morada do cliente". NÃO uses o NIF nem o nome que aparecem aí.
- Se tiveres dúvida entre os dois NIFs, escolhe o do topo/cabeçalho (emissor). Se mesmo assim não tiveres a certeza, OMITE o nif (não adivinhes).

REGRAS:
- Extrai a fatura COMPLETA: TODAS as linhas de artigos (uma entrada por linha) e a cadeia de valores toda. NÃO é o "total pago" — é o detalhe B2B. As LINHAS são o mais importante — extrai-as sempre, com atenção.
- "codigo" é o código/referência do artigo NA FATURA do fornecedor (coluna "Artigo"/"Código"/"Ref."). Se não houver, omite.
- Se a fatura vier repetida (ORIGINAL/DUPLICADO/TRIPLICADO), lê só UMA via. "A transportar"/"Transportado" e referências a guias NÃO são linhas.
- ⚠️ Se NÃO souberes um campo, OMITE-O (ou usa null). NUNCA INVENTES valores, NIFs, datas ou linhas. É melhor faltar do que estar errado.
- ⚠️ SUMÁRIO: só o preenches se conseguires LÊ-LO com confiança na fatura. Se os valores do sumário não forem legíveis ou não fecharem com as linhas, OMITE os campos do sumário (deixa-os fora/null) em vez de pôres valores errados — a Xplendor calcula a soma das linhas do seu lado.
- Valores como NÚMEROS (ponto decimal, sem símbolo €, sem separador de milhares). Ex.: 1234.56.
- Datas em ISO YYYY-MM-DD. NIF com 9 dígitos.
- taxaIva só pode ser 6, 13 ou 23 (taxas de IVA de Portugal continental). Se vier outra, escolhe a mais próxima só se for óbvio; senão omite.
- COERÊNCIA ARITMÉTICA (usa-a para te corrigires, margem 0.05):
  · a soma dos totalLinha ≈ totalMercadorias ≈ baseTributavel (antes de IVA) — se não fechar, revê o sumário (as linhas mandam)
  · totalMercadorias − descontoComercial ≈ baseTributavel
  · baseTributavel + valorIvaTotal − retencaoFonte − descontoFinanceiro ≈ total

EXEMPLO (fatura de fornecedor PT, ilustrativo — repara: "Forno Tradicional" é o EMISSOR/fornecedor no topo; um eventual "Exmo. Sr. Restaurante Yuko" seria o CLIENTE, a ignorar):
{
  "fornecedor": {"nome": "Recheio Cash & Carry SA", "nif": "500829993"},
  "numeroFatura": "FT 2024A/12345",
  "dataEmissao": "2024-03-15",
  "linhas": [
    {"codigo": "100231", "item": "Arroz Agulha 5kg", "quantidade": 10, "unidade": "un", "precoUnitario": 4.20, "descontoPct": 0, "totalLinha": 42.00, "taxaIva": 6},
    {"codigo": "300718", "item": "Detergente Loiça 5L", "quantidade": 2, "unidade": "un", "precoUnitario": 6.50, "descontoPct": 10, "totalLinha": 11.70, "taxaIva": 23}
  ],
  "sumario": {
    "totalMercadorias": 53.70, "descontoComercial": 0, "baseTributavel": 53.70,
    "ivaPorTaxa": [{"taxa": 6, "base": 42.00, "iva": 2.52}, {"taxa": 23, "base": 11.70, "iva": 2.69}],
    "valorIvaTotal": 5.21, "retencaoFonte": 0, "descontoFinanceiro": 0, "total": 58.91
  }
}

Devolve APENAS o JSON.
PROMPT;
    }

    /**
     * PROMPT DAS LINHAS (caminho com QR): o cabeçalho e os totais já vêm do QR da AT, a IA
     * só lê as LINHAS (+ o nome do fornecedor, que o QR não tem).
     */
    public function linesPrompt(): string
    {
        return <<<'PROMPT'
És um extrator de LINHAS de FATURAS DE FORNECEDOR portuguesas (B2B). O cabeçalho e os totais da fatura já são conhecidos (vêm do QR oficial da AT) — só precisas das LINHAS de artigos. Recebes o TEXTO da fatura (extraído do PDF) ou as IMAGENS das páginas, e devolves SÓ um objeto JSON válido com este schema EXATO:

{
  "fornecedorNome": "string (nome do EMISSOR, no topo — nunca o cliente/adquirente)",
  "linhas": [
    {"codigo": "string", "item": "string", "quantidade": number, "unidade": "string", "precoUnitario": number, "descontoPct": number, "taxaIva": number, "totalLinha": number}
  ],
  "guias": [{"numero": "string (ex.: GT 3105/2026)", "data": "YYYY-MM-DD"}]
}

CAMPOS DE CADA LINHA:
- codigo: código/referência do artigo do fornecedor (coluna "Artigo"/"Código"/"Ref."/"Cód."). Copia-o tal como está. Se não houver, omite.
- item: descrição do artigo.
- quantidade, unidade (UN, KG, CX, L…), precoUnitario (sem IVA), descontoPct (0 se não houver).
- taxaIva: a taxa de IVA da linha em % (ex.: 6, 13, 23; 0 se isento). Se a fatura usar códigos de taxa, converte-os para a percentagem.
- totalLinha: valor da linha SEM IVA, já com o desconto da linha (a coluna "Valor"/"Total"/"Líquido").

REGRAS:
- TODAS as linhas de artigos, de TODAS as páginas, uma entrada por linha, pela ordem da fatura. Inclui portes, taras, ecovalor e outras linhas com valor se aparecerem como linhas.
- Se a fatura vier repetida (ORIGINAL / DUPLICADO / TRIPLICADO / QUADRUPLICADO), lê só UMA via.
- NÃO são linhas: "A transportar", "Transportado", subtotais, resumo/quadro de IVA, totais, referências a guias de remessa/transporte (ex.: "GT 3105/2026 de 02/05/2026"), cabeçalhos de coluna.
- guias: se a fatura referir guias de transporte/remessa (GT, GR, GD…), lista-as em "guias" com o número e a data, se legível. Se não houver, devolve [].
- Valores como NÚMEROS (ponto decimal, sem € nem separador de milhares): "1 214,92" → 1214.92; "1.504,07" → 1504.07.
- ⚠️ NUNCA inventes linhas nem valores. Se um campo não for legível, omite-o. Não alteres o que está escrito para fazer bater os totais.

Devolve APENAS o JSON.
PROMPT;
    }
}
