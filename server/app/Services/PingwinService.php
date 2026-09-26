<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\PingwinDailySale;
use App\Models\PingwinLocation;
use App\Models\PingwinSyncRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * XPLENDOR — PingWin (Incremento 1). Orquestra o cliente Python (mycloudpie):
 *  · valida a ligação (login+logout de teste) ANTES de gravar credenciais;
 *  · guarda a SENHA cifrada (access_token, cast EncryptedLegacy) + os IDs em config;
 *  · sincroniza (lojas + resumo de vendas) invocando o Python.
 *
 * ⚠️ A senha é decifrada pelo Laravel (cast/APP_KEY) e passada ao Python por
 * STDIN — NUNCA por argv (argv aparece no `ps`). Sem cripto duplicada no Python.
 * O LOGOUT é garantido do lado do Python (context manager).
 */
class PingwinService
{
    private const PLATFORM = 'pingwin';
    private const ENTRYPOINT = '/scraper/sources/pingwin/run.py';

    // Só isto VARIA por restaurante → guarda-se em company_integrations.config.
    // (A senha vai à parte, cifrada em access_token.) Tudo o resto é GLOBAL e
    // vem do .env (config('services.pingwin')) — igual a todos os restaurantes.
    private const CONFIG_KEYS = ['username', 'database'];

    /** Invoca o entrypoint Python, passando a config+senha por STDIN. */
    protected function invoke(array $payload): array
    {
        $command = [
            'docker', 'exec', '-i',
            env('SCRAPER_CONTAINER', 'xplendor-scraper'),
            'python', self::ENTRYPOINT,
        ];

        $process = new Process($command);
        $process->setTimeout(180);
        $process->setInput(json_encode($payload)); // credenciais por STDIN (nunca argv)
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $exit = $process->getExitCode();

        if ($stdout === '') {
            // NÃO esconder a causa: expõe o erro REAL (stderr + exit code) — pode
            // ser docker/socket (invocação), ImportError (deps) ou rede/SSL.
            Log::error('[PingWin] sem output do Python', ['exit' => $exit, 'stderr' => mb_substr($stderr, 0, 2000)]);
            $detail = $stderr !== '' ? mb_substr($stderr, 0, 500) : 'sem stderr';
            throw new \RuntimeException("Sem resposta do cliente PingWin (exit={$exit}): {$detail}");
        }

        // O que decide sucesso/falha é o "ok" do JSON — NÃO o stderr. O stderr pode
        // trazer avisos INOFENSIVOS (ex.: o do xlrd "file size ... sector size") com
        // o resultado na mesma correto. Extraímos só o objeto JSON do stdout (1.º
        // "{" até ao último "}"), tolerando qualquer ruído que lá tenha caído.
        $data = json_decode($stdout, true);
        if (! is_array($data)) {
            $data = $this->extractJson($stdout);
        }
        if (! is_array($data)) {
            Log::error('[PingWin] resposta inválida', ['stdout' => mb_substr($stdout, 0, 1000), 'stderr' => mb_substr($stderr, 0, 1000)]);
            throw new \RuntimeException('Resposta do cliente PingWin inválida: ' . mb_substr($stdout, 0, 300));
        }

        // Falha "de negócio" (ok:false, ex.: relatório): guarda o stderr do Python
        // (tem o corpo da resposta do servidor) no log para diagnóstico completo.
        if (! ($data['ok'] ?? false)) {
            Log::warning('[PingWin] cliente devolveu ok:false', [
                'error' => mb_substr((string) ($data['error'] ?? ''), 0, 500),
                'stderr' => mb_substr($stderr, 0, 2000),
            ]);
        }

        return $data;
    }

    /** Testa a ligação (login+logout). Devolve true/false. Não grava nada. */
    public function validateConnection(array $config, string $password): bool
    {
        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'validate']));

        return (bool) ($result['ok'] ?? false);
    }

    /**
     * GRAVA as credenciais (senha cifrada + config) com estado "a validar". NÃO
     * valida aqui — a validação síncrona corre no php-fpm, que NÃO tem o socket
     * do Docker, logo o `docker exec` para o Python rebenta ("Sem resposta").
     * A validação real vai por FILA (ValidatePingwinConnectionJob → worker, que
     * tem o socket) e atualiza o estado + notifica no sino.
     */
    public function saveCredentials(int $companyId, array $config, string $password): CompanyIntegration
    {
        return CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => self::PLATFORM],
            [
                'access_token' => $password,           // cifrado pelo cast EncryptedLegacy
                'config' => $this->pickConfig($config),
                'status' => 'validating',
                'error_message' => null,
            ]
        );
    }

    /**
     * Valida a ligação das credenciais JÁ guardadas (login → logout garantido no
     * Python) e ATUALIZA o estado. Corre no worker (fila), que tem o socket.
     * Devolve o resultado bruto (['ok'=>bool, 'error'=>?string]) para a
     * notificação levar o MOTIVO REAL da falha (ex.: 401 credenciais inválidas).
     */
    public function validateStored(int $companyId): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || empty($integration->config)) {
            return ['ok' => false, 'error' => 'PingWin não está configurado para esta empresa.'];
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        try {
            $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'validate']));
        } catch (\Throwable $e) {
            // Erro de infraestrutura (docker/rede) → tratamos como falha de validação
            // com o motivo real (já não esconde nada — ver invoke()).
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($result['ok'] ?? false) {
            $integration->update(['status' => 'active', 'error_message' => null]);
        } else {
            $integration->update([
                'status' => 'error',
                'error_message' => mb_substr((string) ($result['error'] ?? 'erro desconhecido'), 0, 500),
            ]);
        }

        return $result;
    }

    /**
     * DOCUMENTOS (Fase 1, só leitura): busca os tipos de documento (LOGOUT
     * garantido no Python) e faz UPSERT em pingwin_document_configs. Reutiliza as
     * credenciais da empresa. Devolve o nº de tipos guardados.
     */
    public function syncDocuments(int $companyId): int
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'documents']));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Sincronização de documentos PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $now = now();
        $saved = 0;
        foreach ($result['documents'] ?? [] as $doc) {
            $externalId = (string) ($doc['id'] ?? $doc['code'] ?? '');
            if ($externalId === '') {
                continue;
            }
            \App\Models\PingwinDocumentConfig::updateOrCreate(
                ['company_id' => $companyId, 'external_id' => $externalId],
                [
                    'code' => $doc['code'] ?? null,
                    'description' => $doc['description'] ?? null,
                    'entitytype' => $doc['entitytype'] ?? null,
                    'fiscaltype' => $doc['fiscaltype'] ?? null,
                    'fiscaltype_description' => $doc['fiscaltype_description'] ?? null,
                    'deleted' => (bool) ($doc['deleted'] ?? false),
                    'synced_at' => $now,
                ]
            );
            $saved++;
        }

        return $saved;
    }

    /**
     * ARTIGOS (Fase 1, só leitura): busca o catálogo COMPLETO (browserdataset
     * paginado por Range, LOGOUT garantido no Python) e faz UPSERT idempotente em
     * pingwin_catalog_items por (company_id, pingwin_id). Preços em CÊNTIMOS.
     * São muitos → grava em lotes. Devolve o nº de artigos guardados.
     */
    public function syncCatalog(int $companyId): int
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'catalog']));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Sincronização de artigos PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $now = now();
        $rows = [];
        foreach ($result['articles'] ?? [] as $art) {
            $pingwinId = (string) ($this->pick($art, ['id', 'product_id', 'code']) ?? '');
            if ($pingwinId === '') {
                continue;
            }
            $deleted = (bool) ($this->pick($art, ['deleted']) ?? false);
            $rows[] = [
                'company_id'          => $companyId,
                'pingwin_id'          => $pingwinId,
                'code'                => $this->str($this->pick($art, ['code'])),
                'description'         => $this->str($this->pick($art, ['description', 'descr'])),
                'family'              => $this->str($this->pick($art, ['family', 'family_descr', 'family_id_descr'])),
                'family_pingwin_id'   => $this->str($this->pick($art, ['family_id', 'family_pingwin_id'])),
                'forsale'             => (bool) ($this->pick($art, ['forsale', 'for_sale', 'issale']) ?? false),
                'forpurchase'         => (bool) ($this->pick($art, ['forpurchase', 'for_purchase', 'ispurchase']) ?? false),
                'has_bom'             => (bool) ($this->pick($art, ['isbom', 'has_bom', 'bom']) ?? false),
                'product_type'        => $this->str($this->pick($art, ['product_type', 'producttype', 'type'])),
                'product_status'      => $this->str($this->pick($art, ['product_status', 'status', 'state'])),
                'taxgroup'            => $this->str($this->pick($art, ['taxgroup', 'taxgroup_descr', 'tax_group'])),
                'printzone'           => $this->str($this->pick($art, ['printzone', 'printzone_descr', 'print_zone'])),
                'saleprice_cents'     => $this->toCentsNullable($this->pick($art, ['saleprice', 'sale_price', 'price'])),
                'purchaseprice_cents' => $this->toCentsNullable($this->pick($art, ['purchaseprice', 'purchase_price', 'cost'])),
                'saleunit'            => $this->str($this->pick($art, ['saleunit', 'sale_unit', 'unit'])),
                'purchaseunit'        => $this->str($this->pick($art, ['purchaseunit', 'purchase_unit'])),
                'order_code'          => $this->str($this->pick($art, ['order_code', 'ordercode'])),
                // supplier_code fica NULL (matching é fase futura; o browserdataset não o traz).
                'supplier_code'       => null,
                'is_active'           => ! $deleted, // is_active = NOT deleted
                'synced_at'           => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        // UPSERT em lotes por (company_id, pingwin_id) — idempotente. Não toca em
        // created_at ao atualizar (só nas colunas de dados + updated_at/synced_at).
        if (! empty($rows)) {
            $updateCols = [
                'code', 'description', 'family', 'family_pingwin_id', 'forsale', 'forpurchase',
                'has_bom', 'product_type', 'product_status', 'taxgroup', 'printzone',
                'saleprice_cents', 'purchaseprice_cents', 'saleunit', 'purchaseunit',
                'order_code', 'is_active', 'synced_at', 'updated_at',
            ];
            foreach (array_chunk($rows, 500) as $chunk) {
                \App\Models\PingwinCatalogItem::upsert($chunk, ['company_id', 'pingwin_id'], $updateCols);
            }
        }

        // ⚠️ RECONCILIAÇÃO DE ANULADOS (bug de integridade): o "anular" no PingWin é
        // soft-delete → o artigo sai dos ATIVOS (STATE:0) mas o registo antigo ficaria
        // is_active=true no espelho a mentir. O Python devolve os ids anulados (STATE:1,
        // deleted:1); marcamos is_active=false SÓ nos que (a) existem, (b) estão ativos,
        // (c) constam da lista de anulados. NÃO importamos anulados novos. Cruzamento por
        // pingwin_id (não code — há anulados sem code). Se a leitura do STATE:1 falhasse,
        // o run devolvia ok:false e nem cá chegávamos (nada é aplicado — via segura).
        $deletedIds = array_values(array_filter(array_map(
            static fn ($v) => (string) $v,
            $result['deleted_ids'] ?? []
        ), static fn ($s) => $s !== ''));

        $markedInactive = 0;
        if (! empty($deletedIds)) {
            foreach (array_chunk($deletedIds, 500) as $chunk) {
                $markedInactive += \App\Models\PingwinCatalogItem::where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereIn('pingwin_id', $chunk)
                    ->update(['is_active' => false, 'synced_at' => now()]);
            }
        }
        if ($markedInactive > 0) {
            \Illuminate\Support\Facades\Log::info('[PingWin Artigos] reconciliação de anulados', [
                'company_id' => $companyId, 'marcados_inativos' => $markedInactive,
            ]);
        }

        return count($rows);
    }

    /**
     * PREÇO — conversão na FRONTEIRA (crítico). A BD guarda CÊNTIMOS (int); o PingWin
     * quer decimal STRING com PONTO e 2 casas, SEM separador de milhares.
     *   1000 → "10.00" · 5 → "0.05" · 100000 → "1000.00" · 1025 → "10.25"
     */
    /** Chave de cache (Redis) para o resultado de uma LEITURA assíncrona. Scoped por
     *  empresa (o token é aleatório; a empresa no path do poll fecha a tenancy). */
    public static function readKey(int $companyId, string $token): string
    {
        return "pingwin_read:{$companyId}:{$token}";
    }

    /** Traduz erros crus de infra/conexão numa mensagem HUMANA para a UI (o stderr real
     *  fica no log). Erros de "negócio" do PingWin passam à frente (podem ser úteis). */
    public static function humanError(string $raw): string
    {
        $r = mb_strtolower($raw);
        foreach (['docker daemon', 'docker.sock', 'permission denied', 'cannot connect',
                  'sem resposta do cliente pingwin', 'connection refused', 'timed out', 'timeout'] as $needle) {
            if (str_contains($r, $needle)) {
                return 'Não foi possível ligar ao PingWin neste momento. Tenta novamente daqui a instantes.';
            }
        }

        return trim($raw) !== '' ? $raw : 'Ocorreu um erro ao contactar o PingWin.';
    }

    public static function centsToDecimalString(int $cents): string
    {
        return number_format($cents / 100, 2, '.', ''); // '.' decimal, '' milhares
    }

    /** Inverso: decimal (string/float) → CÊNTIMOS com ROUND (não truncar).
     *  "10.25" → 1025 · "10.999" → 1100 (round, não 1099). */
    public static function decimalToCents(string|float $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * ARTIGOS — CRIAR (Etapa 1b, ESCRITA REAL). Invoca o Python (create_product) que
     * faz NEW→carregar-lojas→(prices)→MERGE→SAVE→CLOSE→confirmação, tudo numa só
     * sessão/porta. Preços chegam JÁ como decimal string (convertidos no job pela
     * fronteira). Devolve {ok, persisted, code, pingwin_id, stores_count, raw}.
     *
     * ⚠️ NÃO toca no espelho — isso é o job, e SÓ após persisted=true.
     */
    public function createProduct(int $companyId, array $product, ?string $saleprice = null, ?string $purchaseprice = null): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $extra = array_filter([
            'mode'          => 'create_product',
            'product'       => $product,
            'saleprice'     => $saleprice,
            'purchaseprice' => $purchaseprice,
        ], static fn ($v) => $v !== null);
        // 'product' pode ser [] legítimo (herda o branco do servidor) — repor se filtrado.
        $extra['product'] = $product;

        $result = $this->invoke($this->buildPayload($config, $password, $extra));

        // Erro de execução do Python (exceção): sem 'result' → falha dura.
        if (! isset($result['result']) && ! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Criar artigo PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        return $result['result'] ?? ['ok' => false, 'persisted' => false];
    }

    /**
     * ARTIGOS — EDITAR (Etapa 3, ESCRITA REAL): edita um artigo existente (incl. mudar o
     * Estado). Invoca o Python (update_product): OPEN→(EDIT prices se mudou)→MERGE curto
     * (maindataset SEM chaves de preço)→SAVE→CLOSE→confirmação in-session. `changes` =
     * campos do maindataset a sobrepor (nomes PingWin). Preço já em decimal string.
     * Devolve {ok, product_id, code, confirm:{status, saleprice, ...}} (leitura autoritativa).
     */
    public function updateProduct(int $companyId, string $productId, array $changes, ?string $saleprice = null, ?string $purchaseprice = null): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $extra = ['mode' => 'update_product', 'product_id' => $productId, 'changes' => $changes];
        if ($saleprice !== null) {
            $extra['saleprice'] = $saleprice;
        }
        if ($purchaseprice !== null) {
            $extra['purchaseprice'] = $purchaseprice;
        }

        $result = $this->invoke($this->buildPayload($config, $password, $extra));

        if (! isset($result['result']) && ! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Editar artigo PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        return $result['result'] ?? ['ok' => false];
    }

    /**
     * ARTIGOS — LER/ABRIR (Etapa 2, SÓ LEITURA): lê um artigo completo pelo id
     * (OPEN,GET,INFO com os 19 datasets → CLOSE) para mostrar/editar na tela. Mapeia os
     * preços para CÊNTIMOS (consistência com o espelho). Leitura autoritativa (NÃO
     * browserdataset). Síncrono (sem job), como o form-lookups.
     */
    public function readProduct(int $companyId, string $productId): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, [
            'mode' => 'read_product', 'product_id' => $productId,
        ]));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Leitura do artigo PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $product = $result['product'] ?? [];

        // Preços do servidor (decimal) → CÊNTIMOS, para o frontend/espelho.
        $prices = $product['prices'] ?? [];
        $product['saleprice_cents'] = isset($prices['saleprice']) ? self::decimalToCents($prices['saleprice']) : null;
        $product['purchaseprice_cents'] = isset($prices['purchaseprice']) ? self::decimalToCents($prices['purchaseprice']) : null;

        // ⚠️ Fallback do catalog_item_id (id do espelho local): a tela precisa dele para
        // EDITAR/ANULAR e o state da navegação perde-se num F5. Resolve-se aqui pelo
        // pingwin_id → sobrevive a recarregar a página / link direto.
        $catalogItemId = \App\Models\PingwinCatalogItem::where('company_id', $companyId)
            ->where('pingwin_id', $productId)->value('id');
        $product['catalog_item_id'] = $catalogItemId;

        // Tab Compras (C1): espelha as linhas de fornecedor e devolve o shape limpo (ride-along).
        $rawSupplierLines = $product['supplier_prices'] ?? [];
        $product['supplier_prices'] = $this->syncSupplierPricesFromRead(
            $companyId,
            $catalogItemId ? (int) $catalogItemId : null,
            $productId,
            is_array($rawSupplierLines) ? $rawSupplierLines : [],
        );

        return $product;
    }

    /**
     * Tab Compras (C1) — espelha as linhas de tbsupprice de um artigo: upsert por
     * (company_id, line_pingwin_id) + reconciliação (linhas que já não vêm → is_active=false).
     * Liga ao supplier UNIFICADO (source=pingwin) e ao catalog item. Preços em cêntimos; `raw`
     * guarda a linha original. NÃO escreve no PingWin — só reflete o que a leitura devolveu.
     * Devolve o shape limpo para o frontend.
     */
    public function syncSupplierPricesFromRead(int $companyId, ?int $catalogItemId, string $productPingwinId, array $lines): array
    {
        // Mapa: supplier_id do PingWin → suppliers.id local (source=pingwin, mesma empresa).
        $supplierPwIds = collect($lines)
            ->map(fn ($l) => $this->strOrNull($this->pick($l, ['supplier_id'])))
            ->filter()->unique()->values()->all();
        $supplierMap = $supplierPwIds
            ? \App\Models\PingwinSupplier::where('company_id', $companyId)
                ->whereIn('pingwin_id', $supplierPwIds)->pluck('id', 'pingwin_id')
            : collect();

        $now = now();
        $rows = [];
        $seen = [];
        foreach ($lines as $l) {
            $lineId = $this->strOrNull($this->pick($l, ['id']));
            if ($lineId === null) {
                continue; // sem id de linha não há chave idempotente
            }
            $seen[] = $lineId;
            $supPw = $this->strOrNull($this->pick($l, ['supplier_id']));
            $price = $this->pick($l, ['price']);
            $cp = $this->pick($l, ['currprecision']);

            $rows[] = [
                'company_id'              => $companyId,
                'catalog_item_id'         => $catalogItemId,
                'product_pingwin_id'      => $this->strOrNull($this->pick($l, ['product_id'])) ?? $productPingwinId,
                'supplier_id'             => $supPw !== null ? ($supplierMap[$supPw] ?? null) : null,
                'supplier_pingwin_id'     => $supPw,
                'supplier_name'           => $this->strOrNull($this->pick($l, ['supplier_name'])),
                'line_pingwin_id'         => $lineId,
                'supprice_header_id'      => $this->strOrNull($this->pick($l, ['supprice_header_id'])),
                'table_name'              => $this->strOrNull($this->pick($l, ['table_name'])),
                'start_date'              => $this->parseDateOrNull($this->pick($l, ['start_date'])),
                'end_date'                => $this->parseDateOrNull($this->pick($l, ['end_date'])),
                'currency'                => $this->strOrNull($this->pick($l, ['currency'])),
                'unit_id'                 => $this->strOrNull($this->pick($l, ['unit_id'])),
                'unit_name'               => $this->strOrNull($this->pick($l, ['unit_name'])),
                'sup_product_description' => $this->strOrNull($this->pick($l, ['sup_product_description'])),
                'sup_product_code'        => $this->strOrNull($this->pick($l, ['sup_product_code'])),
                'sup_product_barcode'     => $this->strOrNull($this->pick($l, ['sup_product_barcode'])),
                'price_cents'             => $price !== null && $price !== '' ? self::decimalToCents($price) : null,
                'currprecision'           => $cp !== null && $cp !== '' ? (int) $cp : null,
                'discount1'               => $this->pick($l, ['discount1']),
                'discount2_mul'           => $this->pick($l, ['discount2_mul']),
                'raw'                     => json_encode($l, JSON_UNESCAPED_UNICODE),
                'is_active'               => ! $this->isDeleted($this->pick($l, ['deleted'])),
                'synced_at'               => $now,
                'created_at'              => $now,
                'updated_at'              => $now,
            ];
        }

        if ($rows) {
            $updateCols = array_values(array_diff(array_keys($rows[0]), ['company_id', 'line_pingwin_id', 'created_at']));
            \App\Models\PingwinSupplierPrice::upsert($rows, ['company_id', 'line_pingwin_id'], $updateCols);
        }

        // Reconciliação por artigo: linhas ativas que já não vieram nesta leitura → is_active=false.
        $q = \App\Models\PingwinSupplierPrice::where('company_id', $companyId)
            ->where('product_pingwin_id', $productPingwinId)
            ->where('is_active', true);
        if ($seen) {
            $q->whereNotIn('line_pingwin_id', $seen);
        }
        $q->update(['is_active' => false, 'synced_at' => $now]);

        return $this->supplierPricesShaped($companyId, $productPingwinId);
    }

    /** Shape limpo das linhas de fornecedor ATIVAS de um artigo (lê o espelho). */
    public function supplierPricesShaped(int $companyId, string $productPingwinId): array
    {
        return \App\Models\PingwinSupplierPrice::where('company_id', $companyId)
            ->where('product_pingwin_id', $productPingwinId)
            ->where('is_active', true)
            ->orderBy('supplier_name')->orderBy('id')
            ->get()
            ->map(static fn (\App\Models\PingwinSupplierPrice $p) => [
                'id'                      => $p->id,
                'line_pingwin_id'         => $p->line_pingwin_id,
                'supplier'                => ['id' => $p->supplier_id, 'pingwin_id' => $p->supplier_pingwin_id, 'name' => $p->supplier_name],
                'table'                   => ['header_id' => $p->supprice_header_id, 'name' => $p->table_name],
                'start_date'              => optional($p->start_date)->toDateString(),
                'end_date'                => optional($p->end_date)->toDateString(),
                'currency'                => $p->currency,
                'unit'                    => ['id' => $p->unit_id, 'name' => $p->unit_name],
                'sup_product_description' => $p->sup_product_description,
                'sup_product_code'        => $p->sup_product_code,
                'sup_product_barcode'     => $p->sup_product_barcode,
                'price_cents'             => $p->price_cents,
                'currprecision'           => $p->currprecision,
                'discount1'               => $p->discount1,
                'discount2_mul'           => $p->discount2_mul,
                'is_active'               => $p->is_active,
            ])->all();
    }

    /** Endpoint dedicado (C1): linhas de fornecedor de um artigo pelo id do espelho local. */
    public function supplierPricesForCatalogItem(int $companyId, int $catalogItemId): array
    {
        $productPingwinId = \App\Models\PingwinCatalogItem::where('company_id', $companyId)
            ->whereKey($catalogItemId)->value('pingwin_id');
        if (! $productPingwinId) {
            return [];
        }

        return $this->supplierPricesShaped($companyId, (string) $productPingwinId);
    }

    /** String não-vazia ou null (para colunas nullable). */
    private function strOrNull($value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** deleted (1/'1'/true) → true. Tudo o resto → false. */
    private function isDeleted($value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /** Data válida (Y-m-d) ou null — tolera formatos vários; nunca rebenta a leitura. */
    private function parseDateOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Carbon\CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ARTIGOS — ANULAR (Etapa 4, ESCRITA REAL): apaga DEFINITIVAMENTE um artigo no
     * PingWin. Invoca o Python (delete_product) que faz OPEN→CANCEL,CLOSE→DELETE→
     * confirmação por releitura (tem de vir VAZIA). Devolve {ok, deleted_confirmed,
     * code, product_id, still_present}. ⚠️ NÃO toca no espelho — isso é o job, e SÓ
     * após deleted_confirmed=true (soft-delete de histórico; nunca reactiva no PingWin).
     */
    public function deleteProduct(int $companyId, string $productId, string $code = ''): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, [
            'mode' => 'delete_product', 'product_id' => $productId, 'code' => $code,
        ]));

        if (! isset($result['result']) && ! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Apagar artigo PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        return $result['result'] ?? ['ok' => false, 'deleted_confirmed' => false];
    }

    /**
     * ARTIGOS — FORM (Etapa 1a, SÓ LEITURA / Caminho 1/3): lê o form de criação do
     * artigo no PingWin. O Python faz NEW,GET,INFO na porta 8134 (o form não abre
     * com OPEN puro), CLOSE logo, e uma SALVAGUARDA por browserdataset a provar que
     * NADA persistiu. NUNCA MERGE/SAVE; não toca no espelho nem em pingwin_catalog_writes.
     *
     * Devolve o shape limpo do form (próximo code + lookups vivos do servidor).
     * ⚠️ Se a salvaguarda detetar que a contagem subiu (o NEW terá persistido),
     * ABORTA com erro em destaque — mudaria toda a estratégia da escrita (1b).
     */
    public function productFormLookups(int $companyId): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'product_form_lookups']));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Leitura do form de artigo PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $form = $result['form'] ?? [];

        // ⚠️ SALVAGUARDA: se o NEW+CLOSE tiver persistido (contagem subiu), NÃO seguir.
        $guard = $form['_guard'] ?? [];
        if (($guard['persisted'] ?? false) === true) {
            throw new \RuntimeException(
                'ABORTADO: a contagem de artigos subiu após NEW+CLOSE (antes=' .
                ($guard['count_before'] ?? '?') . ', depois=' . ($guard['count_after'] ?? '?') .
                ') — o NEW terá PERSISTIDO. Não prosseguir com a escrita.'
            );
        }

        return $form;
    }

    /**
     * FORNECEDORES (Fase 1, só leitura): busca os fornecedores (browserdataset
     * paginado, LOGOUT garantido no Python) e faz UPSERT idempotente em
     * pingwin_suppliers por (company_id, pingwin_id). Mapeamento tolerante a
     * aliases (o HAR confirma os nomes exatos). Devolve o nº de fornecedores.
     */
    public function syncSuppliers(int $companyId): int
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'suppliers']));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Sincronização de fornecedores PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $now = now();
        $rows = [];
        foreach ($result['suppliers'] ?? [] as $sup) {
            $pingwinId = (string) ($this->pick($sup, ['id', 'entity_id', 'code']) ?? '');
            if ($pingwinId === '') {
                continue;
            }
            $deleted = (bool) ($this->pick($sup, ['deleted']) ?? false);
            // Nomes de campo confirmados no HAR (fornecedores.har, dataset 1099511639252):
            // name, fiscalname, tax_number, base_address, postalcode, postalcode_description
            // (= localidade), phone, email, deleted. Aliases extra por robustez.
            $rows[] = [
                'company_id'  => $companyId,
                // ⚠️ Tabela unificada: marcar SEMPRE 'pingwin'. O upsert é por
                // (company_id, pingwin_id) → nunca casa com um manual (pingwin_id
                // NULL) e nunca apaga nada. Os fornecedores manuais ficam intactos.
                'source'      => 'pingwin',
                'pingwin_id'  => $pingwinId,
                'code'        => $this->str($this->pick($sup, ['code'])),
                'name'        => $this->str($this->pick($sup, ['name', 'description', 'descr', 'company_name'])),
                'fiscal_name' => $this->str($this->pick($sup, ['fiscalname', 'fiscal_name', 'legalname', 'legal_name'])),
                'tax_number'  => $this->str($this->pick($sup, ['tax_number', 'taxnumber', 'nif', 'vat', 'fiscal_number'])),
                'address'     => $this->str($this->pick($sup, ['base_address', 'address', 'addr', 'address1'])),
                'city'        => $this->str($this->pick($sup, ['postalcode_description', 'city', 'town', 'location'])),
                'postal_code' => $this->str($this->pick($sup, ['postalcode', 'postal_code', 'zip', 'zipcode'])),
                'phone'       => $this->str($this->pick($sup, ['phone', 'telephone', 'tel', 'mobile'])),
                'email'       => $this->str($this->pick($sup, ['email', 'mail'])),
                'is_active'   => ! $deleted,
                'synced_at'   => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        $updateCols = [
            'code', 'name', 'fiscal_name', 'tax_number', 'address', 'city', 'postal_code',
            'phone', 'email', 'is_active', 'synced_at', 'updated_at',
        ];
        foreach (array_chunk($rows, 500) as $chunk) {
            \App\Models\PingwinSupplier::upsert($chunk, ['company_id', 'pingwin_id'], $updateCols);
        }

        return count($rows);
    }

    /**
     * UNIDADES (Fase 1, só leitura): busca as unidades (PORTA 8138, LOGOUT garantido
     * no Python) e faz UPSERT idempotente em pingwin_units por (company_id,
     * pingwin_id). O Python já devolve SÓ o maindataset (o baseunit "radio conv." é
     * ignorado na origem). Guarda duplicados/apagados; is_active = NOT deleted.
     * Devolve o nº de unidades guardadas.
     */
    public function syncUnits(int $companyId): int
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'units']));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Sincronização de unidades PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $now = now();
        $rows = [];
        foreach ($result['units'] ?? [] as $u) {
            $pingwinId = (string) ($this->pick($u, ['id']) ?? '');
            if ($pingwinId === '') {
                continue;
            }
            $deleted = (bool) ($this->pick($u, ['deleted']) ?? false);
            $rows[] = [
                'company_id'         => $companyId,
                'pingwin_id'         => $pingwinId,
                'description'        => $this->str($this->pick($u, ['description', 'descr'])),
                'shortname'          => $this->str($this->pick($u, ['shortname', 'short_name'])),
                'product_pingwin_id' => $this->str($this->pick($u, ['product_id'])),   // ''/null = global
                'parent_pingwin_id'  => $this->str($this->pick($u, ['parent_id'])),     // unidade-base
                'unit_value'         => $this->numOrNull($this->pick($u, ['unit_value', 'parent_qnt'])),
                'purchase'           => (bool) ($this->pick($u, ['purchase']) ?? false),
                'sale'               => (bool) ($this->pick($u, ['sale']) ?? false),
                'stock'              => (bool) ($this->pick($u, ['stock']) ?? false),
                'net_weight'         => $this->numOrNull($this->pick($u, ['net_weight'])),
                'external_measure'   => $this->str($this->pick($u, ['external_measure'])),
                'raw'                => json_encode($u), // objeto completo (p/ editar/anular sem perder campos)
                'is_active'          => ! $deleted,
                'synced_at'          => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        $updateCols = [
            'description', 'shortname', 'product_pingwin_id', 'parent_pingwin_id', 'unit_value',
            'purchase', 'sale', 'stock', 'net_weight', 'external_measure', 'raw', 'is_active', 'synced_at', 'updated_at',
        ];
        foreach (array_chunk($rows, 500) as $chunk) {
            \App\Models\PingwinUnit::upsert($chunk, ['company_id', 'pingwin_id'], $updateCols);
        }

        return count($rows);
    }

    /**
     * ⚠️ ESCRITA: CRIA uma unidade no PingWin (Action NEW, porta 8136, LOGOUT
     * garantido no Python). A confirmação do utilizador é feita a montante
     * (controller exige confirm + a UI pergunta). Em sucesso, faz UPSERT da unidade
     * nova em pingwin_units (para a tela refletir) e devolve a unidade criada. Em
     * falha, LEVANTA com o motivo REAL do PingWin (nunca engolido no genérico).
     */
    public function createUnit(int $companyId, array $payload): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'create_unit', 'unit' => $payload]));

        if (! ($result['ok'] ?? false)) {
            // Motivo REAL do PingWin exposto (não genérico).
            throw new \RuntimeException('Criação de unidade no PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $u = $result['unit'] ?? [];
        $pingwinId = (string) ($this->pick($u, ['id']) ?? '');
        if ($pingwinId === '') {
            throw new \RuntimeException('O PingWin não devolveu o id da unidade criada.');
        }

        // UPSERT da unidade nova (a tela reflete imediatamente, sem re-sync completo).
        $deleted = (bool) ($this->pick($u, ['deleted']) ?? false);
        \App\Models\PingwinUnit::updateOrCreate(
            ['company_id' => $companyId, 'pingwin_id' => $pingwinId],
            [
                'description'        => $this->str($this->pick($u, ['description'])),
                'shortname'          => $this->str($this->pick($u, ['shortname'])),
                'product_pingwin_id' => $this->str($this->pick($u, ['product_id'])),
                'parent_pingwin_id'  => $this->str($this->pick($u, ['parent_id'])),
                'unit_value'         => $this->numOrNull($this->pick($u, ['unit_value', 'parent_qnt'])),
                'purchase'           => (bool) ($this->pick($u, ['purchase']) ?? false),
                'sale'               => (bool) ($this->pick($u, ['sale']) ?? false),
                'stock'              => (bool) ($this->pick($u, ['stock']) ?? false),
                'net_weight'         => $this->numOrNull($this->pick($u, ['net_weight'])),
                'external_measure'   => $this->str($this->pick($u, ['external_measure'])),
                'raw'                => $u, // objeto completo (p/ editar/anular)
                'is_active'          => ! $deleted,
                'synced_at'          => now(),
            ]
        );

        return $u;
    }

    /**
     * ⚠️ ESCRITA: GRAVA uma unidade existente (Action EDIT,SAVE). É a MESMA operação
     * para EDITAR (deleted=false) e ANULAR (deleted=true) — a flag decide. Envia o
     * OBJETO COMPLETO (parte do raw guardado, sobrepõe os campos alterados) para não
     * perder nenhum campo. Em sucesso, atualiza a unidade local. Em falha, LEVANTA
     * com o motivo REAL do PingWin (nunca engolido).
     */
    public function saveUnit(int $companyId, int $unitId, array $changes, bool $deleted): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $unit = \App\Models\PingwinUnit::where('company_id', $companyId)->where('id', $unitId)->first();
        if (! $unit) {
            throw new \RuntimeException('Unidade não encontrada.');
        }

        // Base = objeto COMPLETO guardado (raw); fallback a partir das colunas se faltar.
        $object = is_array($unit->raw) ? $unit->raw : $this->rawFromColumns($unit);
        $object['id'] = $unit->pingwin_id; // garante o id certo (identifica a linha a gravar)

        // Sobrepõe só os campos que o utilizador alterou (editar). Anular ignora changes.
        $map = [
            'description' => 'description', 'shortname' => 'shortname', 'parent_id' => 'parent_id',
            'parent_qnt' => 'parent_qnt', 'unit_value' => 'unit_value',
            'net_weight' => 'net_weight', 'external_measure' => 'external_measure',
            'frac_unit' => 'frac_unit', 'warn_maxsale_qnt' => 'warn_maxsale_qnt',
        ];
        foreach ($map as $in => $field) {
            if (array_key_exists($in, $changes) && $changes[$in] !== null) {
                $object[$field] = $changes[$in];
            }
        }
        // O fator de conversão vive em parent_qnt E unit_value (iguais) — mantém-nos coerentes.
        if (array_key_exists('parent_qnt', $changes) && $changes['parent_qnt'] !== null) {
            $object['unit_value'] = $changes['parent_qnt'];
        }
        $object['deleted'] = $deleted ? 1 : 0; // ⚠️ 0=editar (mantém ativa), 1=anular

        $password = (string) $integration->access_token;
        $config = $integration->config;
        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'save_unit', 'unit' => $object]));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Gravação de unidade no PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $saved = $result['unit'] ?? $object;

        // Atualiza a unidade local (reflete na tela): editada → valores novos;
        // anulada → is_active=false (o filtro ativas/anuladas já trata).
        $unit->update([
            'description'       => $this->str($this->pick($saved, ['description'])) ?? $unit->description,
            'shortname'         => $this->str($this->pick($saved, ['shortname'])) ?? $unit->shortname,
            'parent_pingwin_id' => $this->str($this->pick($saved, ['parent_id'])),
            'unit_value'        => $this->numOrNull($this->pick($saved, ['unit_value', 'parent_qnt'])),
            'net_weight'        => $this->numOrNull($this->pick($saved, ['net_weight'])),
            'external_measure'  => $this->str($this->pick($saved, ['external_measure'])),
            'raw'               => is_array($saved) ? $saved : $object,
            'is_active'         => ! $deleted,
            'synced_at'         => now(),
        ]);

        return $saved;
    }

    /** Objeto completo mínimo a partir das colunas (fallback quando não há raw). */
    private function rawFromColumns(\App\Models\PingwinUnit $u): array
    {
        return [
            'id'               => $u->pingwin_id,
            'product_id'       => $u->product_pingwin_id ?? '',
            'description'      => $u->description,
            'shortname'        => $u->shortname,
            'purchase'         => $u->purchase ? 1 : 0,
            'sale'             => $u->sale ? 1 : 0,
            'stock'            => $u->stock ? 1 : 0,
            'parent_id'        => $u->parent_pingwin_id ?? '',
            'parent_qnt'       => $u->unit_value ?? 1,
            'unit_value'       => $u->unit_value ?? 1,
            'net_weight'       => $u->net_weight ?? 0,
            'external_measure' => $u->external_measure ?? '',
            'deleted'          => $u->is_active ? 0 : 1,
        ];
    }

    /**
     * FAMÍLIAS (Fase 1, só leitura): busca a árvore de famílias (GET /family,
     * LOGOUT garantido no Python), guarda FLAT (com parent_pingwin_id) via UPSERT
     * idempotente e RELIGA os artigos existentes às famílias (por family_pingwin_id).
     * Devolve o nº de famílias guardadas.
     */
    public function syncFamilies(int $companyId): int
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        $password = (string) $integration->access_token; // o cast decifra
        $config = $integration->config;

        $result = $this->invoke($this->buildPayload($config, $password, ['mode' => 'families']));

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Sincronização de famílias PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        $now = now();
        $rows = [];
        foreach ($result['families'] ?? [] as $fam) {
            $pingwinId = (string) ($this->pick($fam, ['id']) ?? '');
            if ($pingwinId === '') {
                continue;
            }
            // parent vazio/0/null → raiz (guardamos null).
            $parent = $this->str($this->pick($fam, ['parent_id']));
            if ($parent === '0') {
                $parent = null;
            }
            $deleted = (bool) ($this->pick($fam, ['deleted']) ?? false);
            $rows[] = [
                'company_id'        => $companyId,
                'pingwin_id'        => $pingwinId,
                'description'       => $this->str($this->pick($fam, ['description', 'descr'])),
                'parent_pingwin_id' => $parent,
                'is_active'         => ! $deleted,
                'synced_at'         => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }

        if (! empty($rows)) {
            $updateCols = ['description', 'parent_pingwin_id', 'is_active', 'synced_at', 'updated_at'];
            foreach (array_chunk($rows, 500) as $chunk) {
                \App\Models\PingwinFamily::upsert($chunk, ['company_id', 'pingwin_id'], $updateCols);
            }
        }

        // Religar os artigos já importados às famílias (por family_pingwin_id).
        $this->relinkArticlesToFamilies($companyId);

        return count($rows);
    }

    /**
     * Liga cada artigo (pingwin_catalog_items) à sua família por family_pingwin_id
     * → pingwin_families.id. Artigo cuja família não existe (órfão) fica com
     * family_id = null (não rebenta). Idempotente. Devolve o nº de artigos ligados.
     */
    public function relinkArticlesToFamilies(int $companyId): int
    {
        // Mapa family_pingwin_id → pingwin_families.id (só desta empresa — tenancy).
        $famByPingwinId = \App\Models\PingwinFamily::where('company_id', $companyId)
            ->pluck('id', 'pingwin_id'); // ['<pingwin_id>' => <id>]

        $linked = 0;
        \App\Models\PingwinCatalogItem::where('company_id', $companyId)
            ->select('id', 'family_pingwin_id', 'family_id')
            ->chunkById(500, function ($items) use ($famByPingwinId, &$linked) {
                foreach ($items as $item) {
                    $key = (string) ($item->family_pingwin_id ?? '');
                    $newFamilyId = ($key !== '' && isset($famByPingwinId[$key])) ? $famByPingwinId[$key] : null;
                    if ($item->family_id !== $newFamilyId) {
                        $item->update(['family_id' => $newFamilyId]);
                    }
                    if ($newFamilyId !== null) {
                        $linked++;
                    }
                }
            });

        return $linked;
    }

    /**
     * Monta a árvore de famílias (flat→nested por parent) para exibição. Nós sem
     * parent OU com parent inexistente (órfão) são RAÍZES; children ordenados por
     * description; famílias inativas (deleted) ignoradas. Opcionalmente inclui a
     * contagem de artigos por família (item_count, só os próprios do nó).
     * Devolve um array de nós {id, pingwin_id, description, item_count, children[]}.
     */
    public function familyTree(int $companyId, bool $withCounts = true): array
    {
        $families = \App\Models\PingwinFamily::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('description')
            ->get(['id', 'pingwin_id', 'description', 'parent_pingwin_id']);

        // Contagem de artigos por family_id (uma query agregada).
        $counts = [];
        if ($withCounts) {
            $counts = \App\Models\PingwinCatalogItem::where('company_id', $companyId)
                ->whereNotNull('family_id')
                ->selectRaw('family_id, COUNT(*) as c')
                ->groupBy('family_id')
                ->pluck('c', 'family_id')
                ->toArray();
        }

        // 1) Nós indexados por pingwin_id, cada um com children[].
        $byPingwinId = [];
        foreach ($families as $fam) {
            $byPingwinId[$fam->pingwin_id] = [
                'id'          => $fam->id,
                'pingwin_id'  => $fam->pingwin_id,
                'description' => $fam->description,
                'item_count'  => (int) ($counts[$fam->id] ?? 0),
                'children'    => [],
            ];
        }

        // 2) Ligar cada nó à mãe; sem parent OU parent inexistente → raiz.
        $roots = [];
        foreach ($families as $fam) {
            $parent = $fam->parent_pingwin_id;
            if ($parent !== null && $parent !== '' && $parent !== '0' && isset($byPingwinId[$parent])) {
                $byPingwinId[$parent]['children'][] = &$byPingwinId[$fam->pingwin_id];
            } else {
                $roots[] = &$byPingwinId[$fam->pingwin_id];
            }
        }
        unset($fam);

        return $roots;
    }

    /**
     * Sincroniza: descobre lojas + resumo de vendas por loja (LOGOUT garantido no
     * Python) e persiste. Tenancy assegurada pelo controller (company da rota).
     */
    public function sync(int $companyId, ?string $date = null): array
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('platform', self::PLATFORM)
            ->first();

        if (! $integration || $integration->status === 'revoked' || empty($integration->config)) {
            throw ValidationException::withMessages(['pingwin' => ['PingWin não está ligado para esta empresa.']]);
        }

        // ⚠️ O relatório precisa das lojas a pedir. Compõe o "Stores" (CSV) a
        // partir dos winrest_store_id das lojas ATIVAS cadastradas. Sem lojas →
        // mensagem clara (o relatório iria com "Stores": "" e falharia).
        $storeIds = PingwinLocation::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('winrest_store_id')
            ->filter(fn ($id) => trim((string) $id) !== '')
            ->values();

        if ($storeIds->isEmpty()) {
            throw ValidationException::withMessages([
                'pingwin' => ['Cadastra pelo menos uma loja (ID PingWin) para sincronizar vendas.'],
            ]);
        }

        // O cast decifra a senha (APP_KEY); passamos ao Python por STDIN.
        $password = (string) $integration->access_token;
        $config = $integration->config;

        $extra = [
            'mode' => 'sync',
            // Override do "Stores" global (vazio) com as lojas cadastradas (CSV).
            'stores' => $storeIds->implode(','),
        ];
        if ($date) {
            $extra['date'] = $date;
        }

        $result = $this->invoke($this->buildPayload($config, $password, $extra));

        if (! ($result['ok'] ?? false)) {
            $integration->update(['status' => 'error', 'error_message' => mb_substr((string) ($result['error'] ?? 'erro'), 0, 500)]);
            throw new \RuntimeException('Sincronização PingWin falhou: ' . ($result['error'] ?? 'erro desconhecido'));
        }

        // O Python devolve a data efetiva + lojas (fetch_stores) + vendas por loja.
        $businessDate = (string) ($result['date'] ?? now()->subDay()->toDateString());
        $count = $this->persistSyncResult($companyId, $businessDate, $result['sales'] ?? []);

        $integration->update(['status' => 'active', 'error_message' => null, 'last_synced_at' => now()]);
        $result['locations_count'] = $count;

        return $result;
    }

    /** Extrai o objeto JSON do stdout (1.º "{" até ao último "}"), ignorando ruído. */
    private function extractJson(string $output): ?array
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** € (float) → cêntimos inteiros. NUNCA guardar float. */
    private function toCents($value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** € → cêntimos inteiros, ou NULL se o valor não veio (coluna nullable). */
    private function toCentsNullable($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    /**
     * Primeiro valor não-vazio de uma lista de chaves candidatas (o browserdataset
     * do catálogo varia por instalação; tolera nomes alternativos). NULL se nenhuma.
     */
    private function pick(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    /** Número (float) ou NULL se não numérico. */
    private function numOrNull($value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /** Normaliza para string (ou NULL) — evita guardar arrays/objetos por engano. */
    private function str($value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    /**
     * Persiste um dia sincronizado: liga cada linha do relatório à loja CADASTRADA
     * (match por nome — winrest_name/display_name), guarda as vendas em CÊNTIMOS
     * (pingwin_daily_sales, idempotente por (location_id, business_date)) e regista
     * o dia (pingwin_sync_runs → portão de honestidade). Devolve o nº de lojas
     * com vendas guardadas.
     *
     * NOTA (fase futura): NÃO cria lojas automaticamente. Uma linha do relatório
     * sem loja cadastrada correspondente é registada no log e ignorada (a
     * descoberta/reconciliação automática fica para depois).
     */
    private function persistSyncResult(int $companyId, string $businessDate, array $sales): int
    {
        // Mapa nome(lower) → location_id a partir das lojas CADASTRADAS da empresa
        // (winrest_name e display_name, para maximizar o match).
        $locationByName = [];
        foreach (PingwinLocation::where('company_id', $companyId)->get() as $loc) {
            foreach ([$loc->winrest_name, $loc->display_name] as $n) {
                $n = mb_strtolower(trim((string) $n));
                if ($n !== '') {
                    $locationByName[$n] = $loc->id;
                }
            }
        }

        $now = now();
        $saved = 0;
        $unmatched = [];
        foreach ($sales as $row) {
            $name = trim((string) ($row['loja'] ?? ''));
            if ($name === '') {
                continue;
            }
            $locationId = $locationByName[mb_strtolower($name)] ?? null;
            if (! $locationId) {
                // Sem correspondência → avisa (não cria; fase futura). Não parte o sync.
                $unmatched[] = $name;
                continue;
            }

            PingwinDailySale::updateOrCreate(
                ['location_id' => $locationId, 'business_date' => $businessDate],
                [
                    'company_id'         => $companyId,
                    'gross_cents'        => $this->toCents($row['vendas_brutas'] ?? 0),
                    'credit_notes_cents' => $this->toCents($row['notas_credito'] ?? 0),
                    'discounts_cents'    => $this->toCents($row['descontos'] ?? 0),
                    'net_cents'          => $this->toCents($row['vendas_liquidas'] ?? 0),
                    'tax_cents'          => $this->toCents($row['impostos'] ?? 0),
                    'invoiced_cents'     => $this->toCents($row['valor_faturado'] ?? 0),
                    'tickets_count'      => (int) ($row['num_tickets'] ?? 0),
                    'covers_count'       => (int) ($row['pos_num_pessoas'] ?? 0),
                    'synced_at'          => $now,
                ]
            );
            $saved++;
        }

        // Lojas do relatório sem cadastro → avisa (reconciliação é fase futura).
        if (! empty($unmatched)) {
            Log::warning('[PingWin] linhas do relatório sem loja cadastrada (ignoradas)', [
                'company_id' => $companyId, 'business_date' => $businessDate, 'lojas' => array_values(array_unique($unmatched)),
            ]);
        }

        // 3) Regista o dia sincronizado (idempotente) → base do portão de honestidade.
        PingwinSyncRun::updateOrCreate(
            ['company_id' => $companyId, 'business_date' => $businessDate],
            ['status' => 'success', 'locations_count' => $saved, 'synced_at' => $now]
        );

        return $saved;
    }

    private function pickConfig(array $config): array
    {
        return array_intersect_key($config, array_flip(self::CONFIG_KEYS));
    }

    /** Parâmetros GLOBAIS do PingWin (iguais a todos) — do .env via config. */
    private function globalConfig(): array
    {
        return array_filter(
            (array) config('services.pingwin', []),
            static fn ($v) => $v !== null
        );
    }

    /**
     * O frontend_url (Origin/Referer do SPA) VARIA por restaurante: segue o
     * padrão https://{database}.mycloudpie.com (ex.: database "yuko" →
     * https://yuko.mycloudpie.com). Deriva do X-Database da empresa. Aceita um
     * override manual (config.frontend_url ou PINGWIN_FRONTEND_URL) para o caso
     * raro de um restaurante fugir ao padrão.
     */
    private function resolveFrontendUrl(array $config): ?string
    {
        $override = $config['frontend_url'] ?? config('services.pingwin.frontend_url');
        if (! empty($override)) {
            return $override;
        }
        $database = trim((string) ($config['database'] ?? ''));

        return $database !== '' ? "https://{$database}.mycloudpie.com" : null;
    }

    /**
     * Compõe o que o mycloudpie.py recebe: os GLOBAIS do .env + os POR-EMPRESA
     * (username, database) + o frontend_url DERIVADO do database + a senha
     * (decifrada) + o modo/data.
     */
    private function buildPayload(array $config, string $password, array $extra): array
    {
        return array_merge(
            $this->globalConfig(),
            $this->pickConfig($config),
            array_filter(['frontend_url' => $this->resolveFrontendUrl($config)], static fn ($v) => $v !== null),
            ['password' => $password],
            $extra
        );
    }
}
