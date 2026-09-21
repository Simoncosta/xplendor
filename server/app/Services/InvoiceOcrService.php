<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\OcrInvoiceSummary;
use App\Models\PingwinSupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * XPLENDOR — OCR de faturas de fornecedor (Fase A). Reaproveita o MOTOR do XFIN
 * (gpt-4o-mini + JSON mode + prompt com schema embutido + anti-alucinação +
 * coerção aritmética + validação humana), MAS com prompt B2B (cadeia completa da
 * fatura, não o "total pago" B2C) e extração das LINHAS (o XFIN descartava-as).
 *
 * ⚠️ SÓ LÊ/EXTRAI. NÃO escreve no PingWin (Fase B). A gravação final só acontece
 * quando o utilizador VALIDA no ecrã (controller::update). Aqui só cria o rascunho
 * (status 'por_validar') a partir do que a IA leu.
 *
 * PDF-scan → imagem: delegado ao scraper (Python/poppler) via docker exec (worker
 * tem o socket), como o PingWin. Imagens vão direto. OpenAI é chamado em PHP
 * (mesmo padrão do CarAiAnalysesService — Http + retry).
 */
class InvoiceOcrService
{
    public const MODEL = 'gpt-4o-mini';
    // b2b-v2: NIF do EMISSOR (não do cliente) + reforço da coerência do sumário
    // (omitir se incerto). A soma das linhas é calculada/conferida pela Xplendor.
    public const PROMPT_VERSION = 'b2b-v2';

    private const VALID_VAT = [6, 13, 23];
    private const OPENAI_TIMEOUT = 120;
    private const OPENAI_CONNECT_TIMEOUT = 15;
    private const OPENAI_MAX_ATTEMPTS = 3;
    private const OPENAI_BACKOFF_MS = [500, 1500];

    /**
     * Processa uma fatura (chamado pela fila): lê a imagem do storage, converte
     * PDF→imagem se preciso, chama a IA, sanitiza e persiste linhas+sumário. Marca
     * a fatura 'por_validar' (ou 'erro'). NÃO escreve no PingWin.
     */
    public function process(int $invoiceId): void
    {
        $invoice = OcrInvoice::find($invoiceId);
        if (! $invoice) {
            return;
        }

        try {
            $bytes = Storage::disk($this->disk())->get($invoice->image_path);
            if ($bytes === null || $bytes === '') {
                throw new \RuntimeException('Ficheiro da fatura não encontrado no storage.');
            }
            $mime = (string) ($invoice->image_mime ?? 'image/jpeg');

            // PDF → imagem (scraper Python/poppler). Imagens vão direto.
            if ($this->isPdf($mime, $invoice->image_path)) {
                $bytes = $this->rasterizePdf($bytes);
                $mime = 'image/png';
            }

            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            $raw = $this->rawExtract($dataUri);
            $parsed = $this->decodeJson($raw);
            $clean = $this->sanitize($parsed);
            // ⚠️ O NIF do fornecedor NUNCA é o da própria empresa (esse é o cliente).
            // Se a IA trouxe o NIF/nome da própria empresa por engano, descarta-o.
            $clean = $this->dropOwnCompanyIdentity($clean, $invoice->company_id);

            $this->persist($invoice, $clean);
        } catch (\Throwable $e) {
            Log::warning('[OCR Fatura] Falhou', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
            $invoice->update(['status' => 'erro', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e; // deixa o Job notificar/registar
        }
    }

    /** Chama a OpenAI (visão) com o prompt B2B. Devolve o conteúdo (JSON string). */
    protected function rawExtract(string $dataUri): string
    {
        $apiKey = (string) config('services.openai.key');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_KEY não configurada.');
        }

        $lastException = null;
        for ($attempt = 1; $attempt <= self::OPENAI_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->connectTimeout(self::OPENAI_CONNECT_TIMEOUT)
                    ->timeout(self::OPENAI_TIMEOUT)
                    ->acceptJson()
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model'           => self::MODEL,
                        'temperature'     => 0.1,
                        'max_tokens'      => 4000,
                        'response_format' => ['type' => 'json_object'], // JSON mode
                        'messages'        => [
                            ['role' => 'system', 'content' => $this->prompt()],
                            ['role' => 'user', 'content' => [
                                ['type' => 'text', 'text' => 'Extrai os dados desta fatura de fornecedor para o JSON pedido.'],
                                ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                            ]],
                        ],
                    ]);

                if ($response->failed()) {
                    $status = $response->status();
                    if (in_array($status, [429, 500, 502, 503, 504], true) && $attempt < self::OPENAI_MAX_ATTEMPTS) {
                        usleep(self::OPENAI_BACKOFF_MS[$attempt - 1] * 1000);
                        continue;
                    }
                    $response->throw();
                }

                $content = $response->json('choices.0.message.content');
                if (! is_string($content) || trim($content) === '') {
                    throw new \RuntimeException('OpenAI devolveu conteúdo vazio.');
                }

                return $content;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt === self::OPENAI_MAX_ATTEMPTS) {
                    break;
                }
                usleep(self::OPENAI_BACKOFF_MS[$attempt - 1] * 1000);
            }
        }

        throw new \RuntimeException('OpenAI indisponível ao ler a fatura.', previous: $lastException);
    }

    /**
     * Converte a 1.ª página de um PDF em imagem via o scraper (Python/poppler),
     * invocado por docker exec (worker tem o socket) — mesmo padrão do PingWin.
     * Recebe/devolve bytes; a comunicação é base64 por STDIN/STDOUT.
     */
    protected function rasterizePdf(string $pdfBytes): string
    {
        $payload = json_encode(['mode' => 'pdf_to_image', 'pdf_base64' => base64_encode($pdfBytes)]);
        $process = new Process([
            'docker', 'exec', '-i', env('SCRAPER_CONTAINER', 'xplendor-scraper'),
            'python', '/scraper/sources/ocr/run.py',
        ]);
        $process->setTimeout(120);
        $process->setInput($payload);
        $process->run();

        $out = trim($process->getOutput());
        if ($out === '') {
            throw new \RuntimeException('Conversão de PDF falhou (scraper sem resposta): ' . mb_substr($process->getErrorOutput(), 0, 300));
        }
        $data = json_decode($out, true);
        if (! is_array($data) || ! ($data['ok'] ?? false) || empty($data['image_base64'])) {
            throw new \RuntimeException('Conversão de PDF falhou: ' . mb_substr((string) ($data['error'] ?? $out), 0, 300));
        }

        return base64_decode($data['image_base64']);
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
            $unitPrice = $this->cents($l['precoUnitario'] ?? null);
            $lineTotal = $this->cents($l['totalLinha'] ?? null);
            // Linha só entra se tiver ALGO de útil (nome ou algum valor).
            if ($item === null && $qty === null && $unitPrice === null && $lineTotal === null) {
                continue;
            }
            $lines[] = [
                'item'             => $item,
                'quantity'         => $qty,
                'unit'             => $this->str($l['unidade'] ?? null),
                'unit_price_cents' => $unitPrice,
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
            'supplier_name' => $this->str($forn['nome'] ?? null),
            'supplier_nif'  => $this->nif($forn['nif'] ?? null),
            'number'        => $this->str($raw['numeroFatura'] ?? null),
            'issue_date'    => $this->date($raw['dataEmissao'] ?? null),
            'lines'         => $lines,
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

    /** Persiste linhas + sumário + campos da fatura. Marca 'por_validar'. */
    private function persist(OcrInvoice $invoice, array $clean): void
    {
        $confidence = $this->confidence($clean);
        $supplierId = $this->matchSupplier($invoice->company_id, $clean['supplier_nif'], $clean['supplier_name']);

        DB::transaction(function () use ($invoice, $clean, $confidence, $supplierId) {
            $invoice->lines()->delete();
            $invoice->summary()->delete();

            $invoice->update([
                'supplier_id'    => $supplierId,
                'supplier_name'  => $clean['supplier_name'],
                'supplier_nif'   => $clean['supplier_nif'],
                'number'         => $clean['number'],
                'issue_date'     => $clean['issue_date'],
                'model'          => self::MODEL,
                'prompt_version' => self::PROMPT_VERSION,
                'confidence'     => $confidence,
                'status'         => 'por_validar',
                'error_message'  => null,
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

    /** Taxa de IVA ∈ {6,13,23} ou null (descarta lixo). */
    private function vat($v): ?int
    {
        if (! is_numeric($v)) {
            return null;
        }
        $r = (int) round((float) $v);

        return in_array($r, self::VALID_VAT, true) ? $r : null;
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
    {"item": "string", "quantidade": number, "unidade": "string (un/kg/cx/L...)", "precoUnitario": number, "descontoPct": number, "totalLinha": number, "taxaIva": 6|13|23}
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
    {"item": "Arroz Agulha 5kg", "quantidade": 10, "unidade": "un", "precoUnitario": 4.20, "descontoPct": 0, "totalLinha": 42.00, "taxaIva": 6},
    {"item": "Detergente Loiça 5L", "quantidade": 2, "unidade": "un", "precoUnitario": 6.50, "descontoPct": 10, "totalLinha": 11.70, "taxaIva": 23}
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
}
