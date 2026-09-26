<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\SyncRestaurantJob;
use App\Jobs\ValidatePingwinConnectionJob;
use App\Models\CompanyIntegration;
use App\Models\PingwinLocation;
use App\Services\AlertService;
use App\Services\CoverManagerService;
use App\Services\PingwinDashboardService;
use App\Services\PingwinService;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;

/**
 * XPLENDOR — PingWin (POS), lado STAND. Cadastrar credenciais (senha cifrada),
 * sincronizar (lojas + vendas) e listar. Guard tenant de 2 camadas + gate de
 * módulo 'pingwin' na rota (Fase 3). A senha nunca sai em texto simples.
 */
class CompanyPingwinController extends Controller
{
    public function __construct(private readonly PingwinService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    /**
     * Cadastrar/atualizar credenciais PingWin. GUARDA (senha cifrada) com estado
     * "a validar" e despacha a validação para a FILA (worker, com docker socket) —
     * a validação síncrona no php-fpm falharia (sem socket). O utilizador é
     * notificado no sino com o resultado (sucesso ou o motivo real da falha).
     */
    public function connect(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // Só os 3 que VARIAM por restaurante. Tudo o resto (URLs, versão,
        // report_id, etc.) é global e vem do .env — ver config('services.pingwin').
        $data = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'database' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $password = $data['password'];
        unset($data['password']);

        // Guarda com estado "validating" (não valida aqui) e valida pela fila.
        $integration = $this->service->saveCredentials($companyId, $data, $password);
        ValidatePingwinConnectionJob::dispatch($companyId);

        return ApiResponse::success([
            'platform' => 'pingwin',
            'status' => $integration->status, // 'validating'
        ], 'Credenciais guardadas — a validar a ligação. Serás notificado quando terminar.');
    }

    /** Sincroniza lojas + resumo de vendas (LOGOUT garantido no cliente). */
    public function sync(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $result = $this->service->sync($companyId, $data['date'] ?? null);

        return ApiResponse::success([
            'date' => $result['date'] ?? null,
            'locations' => PingwinLocation::where('company_id', $companyId)->get(),
        ], 'Sincronização concluída.');
    }

    /**
     * Documentos PingWin (Fase 1): lista da BD com paginação Laravel (EXIBIÇÃO —
     * aos poucos, page/perPage) + pesquisa (código/descrição) + filtro por tipo de
     * entidade. Devolve também a última sincronização (max de TODAS as linhas, não
     * só da página) e as entidades distintas (para o filtro).
     */
    public function documents(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'page'       => ['nullable', 'integer', 'min:1'],
            'perPage'    => ['nullable', 'integer', 'min:1', 'max:200'],
            'search'     => ['nullable', 'string', 'max:120'],
            'entitytype' => ['nullable', 'string', 'max:120'],
        ]);
        $perPage = (int) ($data['perPage'] ?? 20);

        $base = \App\Models\PingwinDocumentConfig::where('company_id', $companyId);

        $query = (clone $base)
            ->when($data['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
            })
            ->when($data['entitytype'] ?? null, fn ($q, $e) => $q->where('entitytype', $e))
            ->orderBy('description');

        $lastSynced = (clone $base)->max('synced_at');

        return ApiResponse::success([
            'documents'      => $query->paginate($perPage)->appends($request->query()),
            'last_synced_at' => $lastSynced ? Carbon::parse($lastSynced)->toIso8601String() : null,
            // Tipos de entidade distintos (para o dropdown de filtro), de TODAS as linhas.
            'entitytypes'    => (clone $base)->whereNotNull('entitytype')->where('entitytype', '!=', '')
                ->distinct()->orderBy('entitytype')->pluck('entitytype')->values(),
        ], 'Documentos carregados.');
    }

    /** Gatilho: sincroniza os tipos de documento PingWin (fila; notifica no fim). */
    public function syncDocuments(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        \App\Jobs\SyncPingwinDocumentsJob::dispatch($companyId);

        return ApiResponse::success(['queued' => true], 'A sincronizar documentos… serás notificado quando terminar.');
    }

    /**
     * Artigos PingWin (Fase 1): lista da BD com paginação Laravel (EXIBIÇÃO —
     * aos poucos, page/perPage) + pesquisa (código/descrição) + filtros (família,
     * forsale/forpurchase). Devolve última sincronização + famílias distintas.
     */
    public function catalog(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'page'        => ['nullable', 'integer', 'min:1'],
            'perPage'     => ['nullable', 'integer', 'min:1', 'max:200'],
            'search'      => ['nullable', 'string', 'max:120'],
            'family'      => ['nullable', 'string', 'max:255'],
            'forsale'     => ['nullable', 'boolean'],
            'forpurchase' => ['nullable', 'boolean'],
        ]);
        $perPage = (int) ($data['perPage'] ?? 20);

        $base = \App\Models\PingwinCatalogItem::where('company_id', $companyId);

        $query = (clone $base)
            ->when($data['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
            })
            ->when($data['family'] ?? null, fn ($q, $f) => $q->where('family', $f))
            ->when(array_key_exists('forsale', $data) && $data['forsale'] !== null,
                fn ($q) => $q->where('forsale', (bool) $data['forsale']))
            ->when(array_key_exists('forpurchase', $data) && $data['forpurchase'] !== null,
                fn ($q) => $q->where('forpurchase', (bool) $data['forpurchase']))
            ->orderBy('code')->orderBy('description');

        $lastSynced = (clone $base)->max('synced_at');

        return ApiResponse::success([
            'articles'       => $query->paginate($perPage)->appends($request->query()),
            'last_synced_at' => $lastSynced ? Carbon::parse($lastSynced)->toIso8601String() : null,
            // Famílias distintas (para o dropdown de filtro), de TODAS as linhas.
            'families'       => (clone $base)->whereNotNull('family')->where('family', '!=', '')
                ->distinct()->orderBy('family')->pluck('family')->values(),
        ], 'Artigos carregados.');
    }

    /** Gatilho: sincroniza o catálogo de artigos PingWin (fila; notifica no fim). */
    public function syncCatalog(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        \App\Jobs\SyncPingwinCatalogJob::dispatch($companyId);

        return ApiResponse::success(['queued' => true], 'A sincronizar artigos… serás notificado quando terminar.');
    }

    /**
     * ARTIGOS — FORM (Etapa 1a, SÓ LEITURA): lê no PingWin o form de criação do
     * artigo (próximo code + lookups vivos), via NEW,GET,INFO→CLOSE na porta 8134,
     * com salvaguarda de não-persistência. Síncrono (não é job): a UI da 1c abre o
     * form com estes dados frescos do servidor. Sem escrita, sem confirm.
     */
    public function articleFormLookups(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // ⚠️ Opção 1: a leitura (docker exec) corre no WORKER (root); o php-fpm (www-data)
        // nunca toca no socket. Despacha e devolve um token; a UI faz polling em /read/{token}.
        $token = (string) Str::uuid();
        \App\Jobs\ReadPingwinFormLookupsJob::dispatch($companyId, $token);

        return ApiResponse::success(['read_id' => $token, 'status' => 'pending'], 'A carregar o formulário do PingWin…');
    }

    /**
     * ARTIGOS — LER/ABRIR (Etapa 2, SÓ LEITURA): lê um artigo completo pelo id do PingWin
     * (leitura autoritativa OPEN,GET,INFO) para a tela mostrar/editar. Síncrono, sem job.
     */
    public function showArticle(int $companyId, string $productId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // ⚠️ Opção 1: leitura no WORKER (root). Despacha e devolve token; UI faz polling.
        $token = (string) Str::uuid();
        \App\Jobs\ReadPingwinProductJob::dispatch($companyId, $productId, $token);

        return ApiResponse::success(['read_id' => $token, 'status' => 'pending'], 'A carregar o artigo do PingWin…');
    }

    /**
     * Poll do resultado de uma LEITURA assíncrona (form-lookups ou artigo). Lê do Redis
     * pelo token e CONSOME (apaga) ao entregar. `pending` enquanto o worker não terminou.
     */
    public function articleRead(int $companyId, string $token)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $key = PingwinService::readKey($companyId, $token);
        $val = Cache::get($key);
        if ($val === null) {
            return ApiResponse::success(['status' => 'pending'], 'A carregar…');
        }
        Cache::forget($key); // consome ao entregar (o TTL é só rede de segurança)

        if (($val['status'] ?? '') === 'ready') {
            return ApiResponse::success(array_merge(['status' => 'ready'], $val['result'] ?? []), 'Pronto.');
        }

        return ApiResponse::success(['status' => 'error', 'error_message' => $val['error_message'] ?? 'Falha na leitura.'], 'Falha na leitura.');
    }

    /**
     * ARTIGOS — tab Compras (C1, SÓ LEITURA): linhas de fornecedor de um artigo, lidas do
     * ESPELHO local (sem chamar o scraper). O espelho é populado ao ler o artigo (showArticle).
     */
    public function supplierPrices(int $companyId, int $catalogItemId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(
            ['supplier_prices' => $this->service->supplierPricesForCatalogItem($companyId, $catalogItemId)],
            'Preços de fornecedor.'
        );
    }

    /** Regras de validação do CRIAR artigo (nomes já no formato do corpo PingWin). */
    private function articleWriteRules(): array
    {
        return [
            'confirm'                  => ['required', 'accepted'], // trava: sem confirmação não escreve
            'description'              => ['required', 'string', 'max:120'],
            'shortname'                => ['nullable', 'string', 'max:60'],
            'button_name'              => ['nullable', 'string', 'max:60'],
            'family_id'                => ['nullable', 'string', 'max:60'],
            'product_type'             => ['nullable', 'string', 'max:20'],  // id (corpo: SEM _id)
            'status'                   => ['nullable', 'string', 'max:20'],  // id (corpo: SEM _id)
            'taxgroup_id'              => ['nullable', 'string', 'max:20'],  // COM _id
            'stockconfig_id'           => ['nullable', 'string', 'max:20'],
            'printzone_id'             => ['nullable', 'string', 'max:20'],
            'base_unit_id'             => ['nullable', 'string', 'max:60'],
            'default_sale_unit_id'     => ['nullable', 'string', 'max:60'],
            'default_purchase_unit_id' => ['nullable', 'string', 'max:60'],
            'default_stock_unit_id'    => ['nullable', 'string', 'max:60'],
            'label_unit_id'            => ['nullable', 'string', 'max:60'],
            'volume_unit_id'           => ['nullable', 'string', 'max:60'],
            'forsale'                  => ['nullable', 'boolean'],
            'forpurchase'              => ['nullable', 'boolean'],
            'forproduction'            => ['nullable', 'boolean'],
            'change_sale_price'        => ['nullable', 'boolean'], // "Preço de venda variável"
            'setexpireday'             => ['nullable', 'integer', 'min:0'],
            'weight'                   => ['nullable', 'numeric', 'min:0'],
            'obs'                      => ['nullable', 'string', 'max:1000'],
            'saleprice_cents'          => ['nullable', 'integer', 'min:0'],
            'purchaseprice_cents'      => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** Constrói o payload do maindataset (nomes PingWin) + os preços em cêntimos. */
    private function buildArticlePayload(array $data): array
    {
        $bool = static fn (string $k) => array_key_exists($k, $data) ? ((bool) $data[$k] ? 1 : 0) : null;

        // product_type/status SEM _id; taxgroup_id/stockconfig_id/printzone_id COM _id.
        $product = array_filter([
            'description'    => $data['description'],
            'shortname'      => $data['shortname'] ?? $data['description'],
            'button_name'    => $data['button_name'] ?? ($data['shortname'] ?? $data['description']),
            'family_id'      => $data['family_id'] ?? null,
            'product_type'   => $data['product_type'] ?? null,
            'status'         => $data['status'] ?? null,
            'taxgroup_id'    => $data['taxgroup_id'] ?? null,
            'stockconfig_id' => $data['stockconfig_id'] ?? null,
            'printzone_id'   => $data['printzone_id'] ?? null,
            'setexpireday'   => $data['setexpireday'] ?? null,
            'weight'         => $data['weight'] ?? null,
            'obs'            => $data['obs'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        // base_unit_id propaga para os defaults quando não especificados.
        $base = $data['base_unit_id'] ?? null;
        if ($base) {
            $product['base_unit_id']             = $base;
            $product['default_sale_unit_id']     = $data['default_sale_unit_id'] ?? $base;
            $product['default_purchase_unit_id'] = $data['default_purchase_unit_id'] ?? $base;
            $product['default_stock_unit_id']    = $data['default_stock_unit_id'] ?? $base;
            $product['label_unit_id']            = $data['label_unit_id'] ?? $base;
        }
        if (! empty($data['volume_unit_id'])) {
            $product['volume_unit_id'] = $data['volume_unit_id'];
        }

        // Checkboxes: 0 é válido → adicionar explicitamente (não filtrar).
        foreach (['forsale', 'forpurchase', 'forproduction', 'change_sale_price'] as $k) {
            $v = $bool($k);
            if ($v !== null) {
                $product[$k] = $v;
            }
        }

        return [$product, $data['saleprice_cents'] ?? null, $data['purchaseprice_cents'] ?? null];
    }

    /**
     * ⚠️ ESCRITA: CRIAR um artigo no PingWin. Ação DELIBERADA (exige confirm=accepted).
     * Tenancy PRIMEIRO (o ensure_module:pingwin já correu na rota); só depois o payload.
     * Regista a auditoria (pingwin_catalog_writes, status=a_criar) ANTES de despachar o
     * job (worker, docker socket). UI faz polling. O espelho só muda após persisted=true.
     */
    public function createArticle(Request $request, int $companyId)
    {
        // 1) Tenancy antes de olhar para confirm/payload (o confirm não dá falsa segurança).
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // 2) Só agora o payload + a trava de confirmação.
        $data = $request->validate($this->articleWriteRules());

        // Regra de negócio (imposta no backend, não só no form): Compra e Produção exclusivos.
        if (($data['forpurchase'] ?? false) && ($data['forproduction'] ?? false)) {
            return ApiResponse::error('Um artigo não pode ser de Compra e de Produção ao mesmo tempo.', 422);
        }

        [$product, $saleCents, $purchaseCents] = $this->buildArticlePayload($data);

        $write = \App\Models\PingwinCatalogWrite::create([
            'company_id'          => $companyId,
            'user_id'             => Auth::id(),
            'action'              => 'criar',
            'description'         => $data['description'],
            'payload'             => $product,
            'saleprice_cents'     => $saleCents,
            'purchaseprice_cents' => $purchaseCents,
            'status'              => 'a_criar',
        ]);

        \App\Jobs\CreatePingwinCatalogJob::dispatch($companyId, $write->id);

        return ApiResponse::success(
            ['creation_id' => $write->id, 'status' => 'a_criar'],
            'A criar o artigo no PingWin… aguarda o resultado.'
        );
    }

    /** Poll do estado da criação de artigo (à imagem de unitCreation). */
    public function articleCreation(int $companyId, int $creationId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $write = \App\Models\PingwinCatalogWrite::where('company_id', $companyId)->find($creationId);
        if (! $write) {
            return ApiResponse::error('Criação não encontrada.', 404);
        }

        return ApiResponse::success([
            'creation_id'   => $write->id,
            'status'        => $write->status,
            'code'          => $write->code,
            'pingwin_id'    => $write->pingwin_id,
            'error_message' => $write->error_message,
            'description'   => $write->description,
        ], 'Estado da criação.');
    }

    /**
     * ⚠️ ESCRITA (mais destrutiva): APAGAR um artigo no PingWin (DELETE definitivo).
     * Apagar acidental é pior que criar → exige confirm=accepted. Tenancy PRIMEIRO
     * (antes do confirm/payload). Regista auditoria (action=anular, a_anular) ANTES de
     * despachar o job. O espelho só é soft-deleted após deleted_confirmed=true (no job).
     */
    public function deleteArticle(Request $request, int $companyId, int $catalogItemId)
    {
        // 1) Tenancy antes de tudo (o ensure_module:pingwin já correu na rota).
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $item = \App\Models\PingwinCatalogItem::where('company_id', $companyId)->where('id', $catalogItemId)->first();
        if (! $item) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        // 2) Só agora a trava de confirmação.
        $request->validate(['confirm' => ['required', 'accepted']]);

        $write = \App\Models\PingwinCatalogWrite::create([
            'company_id'      => $companyId,
            'user_id'         => Auth::id(),
            'action'          => 'anular',
            'catalog_item_id' => $item->id,
            'code'            => $item->code,
            'description'     => $item->description ?? ('#' . $item->id),
            'status'          => 'a_anular',
        ]);

        \App\Jobs\DeletePingwinCatalogJob::dispatch($companyId, $write->id);

        return ApiResponse::success(
            ['creation_id' => $write->id, 'status' => 'a_anular'],
            'A apagar o artigo no PingWin… aguarda o resultado.'
        );
    }

    /** Regras do EDITAR: tudo nullable (edição parcial); confirm obrigatório. */
    private function articleUpdateRules(): array
    {
        return array_merge($this->articleWriteRules(), [
            'description' => ['nullable', 'string', 'max:120'],
            'status'      => ['nullable', 'string', 'in:1,2,3'], // 1=Ativo 2=Inativo 3=Descontinuado
            // C2 — mudanças de linhas de fornecedor (tab Compras). Opcional; validação fina no service.
            'supplier_prices_changes'          => ['nullable', 'array'],
            'supplier_prices_changes.create'   => ['sometimes', 'array'],
            'supplier_prices_changes.update'   => ['sometimes', 'array'],
            'supplier_prices_changes.delete'   => ['sometimes', 'array'],
        ]);
    }

    /** Constrói o `changes` (só campos PRESENTES, nomes PingWin) + preços em cêntimos. */
    private function buildArticleChanges(array $data): array
    {
        $changes = [];
        // Escalares/ids do maindataset (só os que vieram).
        foreach (['description', 'shortname', 'button_name', 'family_id', 'product_type', 'status',
                  'taxgroup_id', 'stockconfig_id', 'printzone_id', 'setexpireday', 'weight', 'obs'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                $changes[$k] = ($k === 'status') ? (int) $data[$k] : $data[$k];
            }
        }
        // base_unit_id propaga para os defaults quando não especificados.
        if (! empty($data['base_unit_id'])) {
            $base = $data['base_unit_id'];
            $changes['base_unit_id'] = $base;
            $changes['default_sale_unit_id'] = $data['default_sale_unit_id'] ?? $base;
            $changes['default_purchase_unit_id'] = $data['default_purchase_unit_id'] ?? $base;
            $changes['default_stock_unit_id'] = $data['default_stock_unit_id'] ?? $base;
            $changes['label_unit_id'] = $data['label_unit_id'] ?? $base;
        }
        if (! empty($data['volume_unit_id'])) {
            $changes['volume_unit_id'] = $data['volume_unit_id'];
        }
        // Checkboxes: 0 é válido → incluir só se a chave veio.
        foreach (['forsale', 'forpurchase', 'forproduction', 'change_sale_price'] as $k) {
            if (array_key_exists($k, $data)) {
                $changes[$k] = ((bool) $data[$k]) ? 1 : 0;
            }
        }

        return [$changes, $data['saleprice_cents'] ?? null, $data['purchaseprice_cents'] ?? null];
    }

    /**
     * ⚠️ ESCRITA: EDITAR um artigo (inclui mudar o Estado: 1/2/3). Tenancy PRIMEIRO,
     * depois confirm=accepted. Regista auditoria (action=editar, a_editar) antes de
     * despachar o job. O espelho só muda após a confirmação autoritativa (no job).
     */
    public function updateArticle(Request $request, int $companyId, int $catalogItemId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $item = \App\Models\PingwinCatalogItem::where('company_id', $companyId)->where('id', $catalogItemId)->first();
        if (! $item) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        $data = $request->validate($this->articleUpdateRules());

        if (($data['forpurchase'] ?? false) && ($data['forproduction'] ?? false)) {
            return ApiResponse::error('Um artigo não pode ser de Compra e de Produção ao mesmo tempo.', 422);
        }

        [$changes, $saleCents, $purchaseCents] = $this->buildArticleChanges($data);

        // C2 — mudanças de fornecedor (opcional). Permite editar SÓ fornecedores (sem tocar no artigo).
        $supplierChanges = $data['supplier_prices_changes'] ?? null;
        $hasSupplier = ! empty($supplierChanges['create'] ?? []) || ! empty($supplierChanges['update'] ?? []) || ! empty($supplierChanges['delete'] ?? []);

        if (empty($changes) && $saleCents === null && $purchaseCents === null && ! $hasSupplier) {
            return ApiResponse::error('Nada para editar.', 422);
        }

        $write = \App\Models\PingwinCatalogWrite::create([
            'company_id'              => $companyId,
            'user_id'                 => Auth::id(),
            'action'                  => 'editar',
            'catalog_item_id'         => $item->id,
            'code'                    => $item->code,
            'description'             => $changes['description'] ?? ($item->description ?? ('#' . $item->id)),
            'payload'                 => $changes,
            'saleprice_cents'         => $saleCents,
            'purchaseprice_cents'     => $purchaseCents,
            'supplier_prices_changes' => $hasSupplier ? $supplierChanges : null,
            'status'                  => 'a_editar',
        ]);

        \App\Jobs\UpdatePingwinCatalogJob::dispatch($companyId, $write->id);

        return ApiResponse::success(['creation_id' => $write->id, 'status' => 'a_editar'], 'A editar o artigo no PingWin… aguarda o resultado.');
    }

    /** Poll do estado do editar de artigo (mesma tabela de auditoria). */
    public function articleUpdate(int $companyId, int $creationId)
    {
        return $this->articleDeletion($companyId, $creationId);
    }

    /** Poll do estado do apagar de artigo (mesma tabela de auditoria da criação). */
    public function articleDeletion(int $companyId, int $creationId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $write = \App\Models\PingwinCatalogWrite::where('company_id', $companyId)->find($creationId);
        if (! $write) {
            return ApiResponse::error('Operação não encontrada.', 404);
        }

        return ApiResponse::success([
            'creation_id'   => $write->id,
            'status'        => $write->status,
            'code'          => $write->code,
            'pingwin_id'    => $write->pingwin_id,
            'error_message' => $write->error_message,
            'description'   => $write->description,
        ], 'Estado do apagar.');
    }

    /**
     * Famílias PingWin (Fase 1): a ÁRVORE (flat→nested por parent, montada no
     * backend) + última sincronização + total de famílias. Só leitura.
     */
    public function families(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $base = \App\Models\PingwinFamily::where('company_id', $companyId);
        $lastSynced = (clone $base)->max('synced_at');

        return ApiResponse::success([
            'tree'           => $this->service->familyTree($companyId),
            'total'          => (clone $base)->where('is_active', true)->count(),
            'last_synced_at' => $lastSynced ? Carbon::parse($lastSynced)->toIso8601String() : null,
        ], 'Famílias carregadas.');
    }

    /** Gatilho: sincroniza as famílias PingWin + religa artigos (fila; notifica no fim). */
    public function syncFamilies(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        \App\Jobs\SyncPingwinFamiliesJob::dispatch($companyId);

        return ApiResponse::success(['queued' => true], 'A sincronizar famílias… serás notificado quando terminar.');
    }

    /**
     * Fornecedores PingWin (Fase 1): lista da BD com paginação Laravel (EXIBIÇÃO,
     * page/perPage) + pesquisa (nome/código/NIF) + filtro (estado ativo/inativo).
     * Devolve última sincronização. Só leitura.
     */
    public function suppliers(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'page'    => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search'  => ['nullable', 'string', 'max:120'],
            'active'  => ['nullable', 'boolean'],
        ]);
        $perPage = (int) ($data['perPage'] ?? 20);

        $base = \App\Models\PingwinSupplier::where('company_id', $companyId);

        $query = (clone $base)
            ->when($data['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('tax_number', 'like', "%{$s}%"));
            })
            ->when(array_key_exists('active', $data) && $data['active'] !== null,
                fn ($q) => $q->where('is_active', (bool) $data['active']))
            ->orderBy('name')->orderBy('code');

        $lastSynced = (clone $base)->max('synced_at');

        return ApiResponse::success([
            'suppliers'      => $query->paginate($perPage)->appends($request->query()),
            'last_synced_at' => $lastSynced ? Carbon::parse($lastSynced)->toIso8601String() : null,
        ], 'Fornecedores carregados.');
    }

    /** Gatilho: sincroniza os fornecedores PingWin (fila; notifica no fim). */
    public function syncSuppliers(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        \App\Jobs\SyncPingwinSuppliersJob::dispatch($companyId);

        return ApiResponse::success(['queued' => true], 'A sincronizar fornecedores… serás notificado quando terminar.');
    }

    /**
     * Unidades PingWin (Fase 1): lista da BD com paginação Laravel + pesquisa
     * (descrição/abreviatura) + filtro de estado (ativas/anuladas). Resolve a
     * CONVERSÃO de forma legível: para unidades com parent, devolve parent_description
     * e conversion_label ("1 Barril 50lt = 50 Litros"). Só leitura.
     */
    public function units(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'page'    => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search'  => ['nullable', 'string', 'max:120'],
            'active'  => ['nullable', 'boolean'],
        ]);
        $perPage = (int) ($data['perPage'] ?? 20);

        $base = \App\Models\PingwinUnit::where('company_id', $companyId);

        // Mapa pingwin_id → descrição (para resolver a unidade-base da conversão).
        $nameById = (clone $base)->pluck('description', 'pingwin_id');

        $query = (clone $base)
            ->when($data['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('shortname', 'like', "%{$s}%"));
            })
            // Por defeito mostra ATIVAS; active=0 → só anuladas; sem filtro → todas só se pedido.
            ->when(array_key_exists('active', $data) && $data['active'] !== null,
                fn ($q) => $q->where('is_active', (bool) $data['active']))
            ->orderByDesc('is_active')->orderBy('description');

        $page = $query->paginate($perPage)->appends($request->query());

        $page->getCollection()->transform(function (\App\Models\PingwinUnit $u) use ($nameById) {
            $parentDesc = $u->parent_pingwin_id ? ($nameById[$u->parent_pingwin_id] ?? null) : null;
            $factor = $u->unit_value;
            // Flags do objeto completo (raw) — p/ pré-preencher o editar sem perder valores.
            $raw = is_array($u->raw) ? $u->raw : [];
            // "1 [unidade] = [fator] [unidade-base]" — só quando há parent.
            $label = null;
            if ($parentDesc) {
                $qty = ($factor !== null && (float) $factor != 1.0) ? rtrim(rtrim(number_format((float) $factor, 5, ',', ''), '0'), ',') : '1';
                $label = "1 {$u->description} = {$qty} {$parentDesc}";
            }

            return [
                'id'                 => $u->id,
                'pingwin_id'         => $u->pingwin_id,
                'description'        => $u->description,
                'shortname'          => $u->shortname,
                'is_global'          => empty($u->product_pingwin_id),
                'parent_pingwin_id'  => $u->parent_pingwin_id,   // para pré-preencher o editar
                'parent_description' => $parentDesc,
                'unit_value'         => $factor !== null ? (float) $factor : null,
                'net_weight'         => $u->net_weight !== null ? (float) $u->net_weight : null,
                'external_measure'   => (bool) ($raw['external_measure'] ?? false),   // checkbox
                'frac_unit'          => (bool) ($raw['frac_unit'] ?? false),          // checkbox
                'warn_maxsale_qnt'   => isset($raw['warn_maxsale_qnt']) && is_numeric($raw['warn_maxsale_qnt']) ? (float) $raw['warn_maxsale_qnt'] : null,
                'conversion_label'   => $label,
                'purchase'           => (bool) $u->purchase,
                'sale'               => (bool) $u->sale,
                'stock'              => (bool) $u->stock,
                'is_active'          => (bool) $u->is_active,
            ];
        });

        $lastSynced = (clone $base)->max('synced_at');

        return ApiResponse::success([
            'units'          => $page,
            'last_synced_at' => $lastSynced ? Carbon::parse($lastSynced)->toIso8601String() : null,
        ], 'Unidades carregadas.');
    }

    /** Gatilho: sincroniza as unidades PingWin (fila; notifica no fim). */
    public function syncUnits(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        \App\Jobs\SyncPingwinUnitsJob::dispatch($companyId);

        return ApiResponse::success(['queued' => true], 'A sincronizar unidades… serás notificado quando terminar.');
    }

    /** Regras de validação partilhadas por CRIAR e EDITAR unidade (mesmos campos). */
    private function unitWriteRules(): array
    {
        return [
            'confirm'          => ['required', 'accepted'], // trava de segurança: sem confirmação não escreve
            'description'      => ['required', 'string', 'max:120'],
            'shortname'        => ['required', 'string', 'max:60'],
            'parent_id'        => ['required', 'string', 'max:60'], // pingwin_id de uma unidade-base
            'parent_qnt'       => ['nullable', 'numeric', 'min:0'], // Conv. factor / fator
            'net_weight'       => ['nullable', 'numeric', 'min:0'], // Peso líquido
            'warn_maxsale_qnt' => ['nullable', 'numeric', 'min:0'], // Qnt. máx. venda
            'frac_unit'        => ['nullable', 'boolean'],          // Unidade fracionária (checkbox)
            'external_measure' => ['nullable', 'boolean'],          // Medição externa (checkbox)
            // 'deleted' NÃO existe no criar/editar — anular é a ação separada.
        ];
    }

    /** Snapshot dos campos do form para o registo de auditoria (create/edit). */
    private function unitWriteSnapshot(array $data): array
    {
        return [
            'description'       => $data['description'],
            'shortname'         => $data['shortname'],
            'parent_pingwin_id' => $data['parent_id'],
            'parent_qnt'        => $data['parent_qnt'] ?? 1,
            'net_weight'        => $data['net_weight'] ?? null,
            'warn_maxsale_qnt'  => $data['warn_maxsale_qnt'] ?? null,
            'frac_unit'         => array_key_exists('frac_unit', $data) ? (bool) $data['frac_unit'] : null,
            'external_measure'  => array_key_exists('external_measure', $data) ? ((bool) $data['external_measure'] ? '1' : '0') : null,
        ];
    }

    /**
     * ⚠️ 1ª ESCRITA no PingWin: CRIAR uma unidade (Action NEW). Ação DELIBERADA:
     * exige `confirm=true` (a UI pergunta explicitamente antes). Valida do lado da
     * XPLENDOR (obrigatórios + parent válido/da empresa), regista a auditoria e
     * despacha o job (worker, que tem o docker socket). Devolve o id do registo para
     * a UI fazer POLLING do resultado. NÃO escreve em fila silenciosa.
     */
    public function createUnit(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate($this->unitWriteRules());

        // A unidade-base tem de existir e ser da própria empresa (tenancy + validade).
        $parentOk = \App\Models\PingwinUnit::where('company_id', $companyId)
            ->where('pingwin_id', $data['parent_id'])->where('is_active', true)->exists();
        if (! $parentOk) {
            return ApiResponse::error('A unidade-base escolhida não existe (sincroniza as unidades primeiro).', 422);
        }

        $creation = \App\Models\PingwinUnitCreation::create(array_merge(
            ['company_id' => $companyId, 'user_id' => Auth::id(), 'status' => 'a_criar'],
            $this->unitWriteSnapshot($data)
        ));

        \App\Jobs\CreatePingwinUnitJob::dispatch($companyId, $creation->id);

        return ApiResponse::success(
            ['creation_id' => $creation->id, 'status' => $creation->status],
            'A criar a unidade no PingWin… aguarda o resultado.'
        );
    }

    /**
     * ⚠️ ESCRITA: EDITAR uma unidade (Action EDIT,SAVE, deleted=0). Ação DELIBERADA:
     * exige confirm. Grava o objeto completo (raw + campos novos). Regista auditoria
     * (action=edit) e despacha o job (worker). UI faz polling do resultado.
     */
    public function editUnit(Request $request, int $companyId, int $unitId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $unit = \App\Models\PingwinUnit::where('company_id', $companyId)->where('id', $unitId)->first();
        if (! $unit) {
            return ApiResponse::error('Unidade não encontrada.', 404);
        }

        $data = $request->validate($this->unitWriteRules());

        $parentOk = \App\Models\PingwinUnit::where('company_id', $companyId)
            ->where('pingwin_id', $data['parent_id'])->where('is_active', true)->exists();
        if (! $parentOk) {
            return ApiResponse::error('A unidade-base escolhida não existe.', 422);
        }

        $write = \App\Models\PingwinUnitCreation::create(array_merge(
            ['company_id' => $companyId, 'user_id' => Auth::id(), 'action' => 'edit', 'unit_id' => $unit->id, 'status' => 'a_criar'],
            $this->unitWriteSnapshot($data)
        ));
        \App\Jobs\SavePingwinUnitJob::dispatch($companyId, $write->id);

        return ApiResponse::success(['creation_id' => $write->id, 'status' => 'a_criar'], 'A alterar a unidade no PingWin… aguarda o resultado.');
    }

    /**
     * ⚠️ ESCRITA (mais destrutiva): ANULAR uma unidade (Action EDIT,SAVE, deleted=1).
     * Exige confirm. É a mesma gravação do editar, só com a flag. Auditoria
     * (action=anular) + job + polling.
     */
    public function anularUnit(Request $request, int $companyId, int $unitId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $unit = \App\Models\PingwinUnit::where('company_id', $companyId)->where('id', $unitId)->first();
        if (! $unit) {
            return ApiResponse::error('Unidade não encontrada.', 404);
        }

        $request->validate(['confirm' => ['required', 'accepted']]);

        $write = \App\Models\PingwinUnitCreation::create([
            'company_id' => $companyId, 'user_id' => Auth::id(), 'action' => 'anular', 'unit_id' => $unit->id,
            'description' => $unit->description ?? $unit->shortname ?? ('#' . $unit->id),
            'shortname' => $unit->shortname ?? '', 'status' => 'a_criar',
        ]);
        \App\Jobs\SavePingwinUnitJob::dispatch($companyId, $write->id);

        return ApiResponse::success(['creation_id' => $write->id, 'status' => 'a_criar'], 'A anular a unidade no PingWin… aguarda o resultado.');
    }

    /**
     * Uso de uma unidade nos artigos (para AVISAR antes de anular): conta os artigos
     * cuja unidade de venda/compra bate com a abreviatura/descrição desta unidade.
     * Aproximado (o catálogo guarda a unidade como texto) — mas alerta o utilizador.
     */
    public function unitUsage(int $companyId, int $unitId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $unit = \App\Models\PingwinUnit::where('company_id', $companyId)->where('id', $unitId)->first();
        if (! $unit) {
            return ApiResponse::error('Unidade não encontrada.', 404);
        }

        $needles = array_values(array_unique(array_filter([$unit->shortname, $unit->description])));
        $count = 0;
        if (! empty($needles)) {
            $count = \App\Models\PingwinCatalogItem::where('company_id', $companyId)
                ->where(function ($q) use ($needles) {
                    foreach ($needles as $n) {
                        $q->orWhere('saleunit', $n)->orWhere('purchaseunit', $n);
                    }
                })->count();
        }

        return ApiResponse::success([
            'unit_id'     => $unit->id,
            'description' => $unit->description,
            'usage_count' => $count, // nº de artigos que (aparentemente) usam esta unidade
        ], 'Uso da unidade.');
    }

    /** Estado de uma criação de unidade (polling da UI): a_criar|criada|erro. */
    public function unitCreation(int $companyId, int $creationId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $creation = \App\Models\PingwinUnitCreation::where('company_id', $companyId)->find($creationId);
        if (! $creation) {
            return ApiResponse::error('Criação não encontrada.', 404);
        }

        return ApiResponse::success([
            'creation_id'   => $creation->id,
            'status'        => $creation->status,
            'pingwin_id'    => $creation->pingwin_id,
            'error_message' => $creation->error_message,
            'description'   => $creation->description,
        ], 'Estado da criação.');
    }

    /** Faturação mensal por loja (uma série por loja) de um ano — gráfico de linha. */
    public function monthlyBilling(Request $request, int $companyId, PingwinDashboardService $dashboard)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $year = (int) ($data['year'] ?? now()->year);

        return ApiResponse::success($dashboard->monthlyByLocation($companyId, $year), 'Faturação mensal carregada.');
    }

    /** Calendário de faturação: números por dia do mês (+ filtro por loja). */
    public function calendar(Request $request, int $companyId, PingwinDashboardService $dashboard)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],           // ex.: 2026-09
            'location_id' => ['nullable', 'integer'],             // filtro por loja (default: todas)
        ]);
        [$year, $month] = array_map('intval', explode('-', $data['month']));

        // Tenancy da loja: só lojas da própria empresa.
        $locationId = null;
        if (! empty($data['location_id'])) {
            $loc = PingwinLocation::where('company_id', $companyId)->where('id', $data['location_id'])->first();
            $locationId = $loc?->id;
        }

        return ApiResponse::success([
            'month' => $data['month'],
            'location_id' => $locationId,
            'days' => $dashboard->calendar($companyId, $year, $month, $locationId),
            'locations' => PingwinLocation::where('company_id', $companyId)->where('is_active', true)
                ->orderBy('display_name')->get(['id', 'display_name', 'winrest_name', 'winrest_store_id']),
        ], 'Calendário carregado.');
    }

    /** Cards (anual/mensal/diário) + tabela de lojas do dashboard de restauração. */
    public function dashboard(Request $request, int $companyId, PingwinDashboardService $dashboard)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return ApiResponse::success($dashboard->build($companyId, $data['date'] ?? null), 'Dashboard PingWin carregado.');
    }

    /**
     * Gatilho do dashboard: mete a sincronização na FILA (não trava a tela). O
     * utilizador é notificado no sino quando o job termina. Serializado por
     * empresa dentro do Job (WithoutOverlapping).
     */
    public function queueSync(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        // Sem lojas ativas cadastradas → mensagem clara já aqui (o relatório precisa
        // dos winrest_store_id). Não despacha um job que iria falhar.
        $hasLocations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)->exists();
        if (! $hasLocations) {
            return ApiResponse::error('Cadastra pelo menos uma loja (ID PingWin) para sincronizar vendas.', 422);
        }

        // Etapa 4: sincronização COMPLETA (PingWin + CoverManager de TODAS as lojas)
        // orquestrada num só job, com UMA notificação no fim. Serializada por empresa.
        SyncRestaurantJob::dispatch($companyId, $data['date'] ?? null);

        return ApiResponse::success([
            'queued' => true,
            'date' => $data['date'] ?? null,
        ], 'A atualizar… vais ser notificado quando os dados estiverem prontos.');
    }

    /** Teto de dias por período (evita despachar milhares de jobs de uma vez). */
    private const MAX_PERIOD_DAYS = 92;

    /**
     * Sincroniza um PERÍODO [de, até]: UM job por dia (SyncRestaurantJob, PingWin +
     * CoverManager de todas as lojas), num Bus batch. Um dia a falhar não aborta os
     * outros (allowFailures). UMA notificação no FIM (callback finally do batch).
     */
    public function syncPeriod(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();
        if ($to->lt($from)) {
            return ApiResponse::error('A data final não pode ser anterior à inicial.', 422);
        }
        $days = $from->diffInDays($to) + 1;
        if ($days > self::MAX_PERIOD_DAYS) {
            return ApiResponse::error('Período demasiado longo (máx. ' . self::MAX_PERIOD_DAYS . ' dias).', 422);
        }

        if (! PingwinLocation::where('company_id', $companyId)->where('is_active', true)->exists()) {
            return ApiResponse::error('Cadastra pelo menos uma loja (ID PingWin) para sincronizar.', 422);
        }

        // Um job por dia (notify:false → não notifica por dia; o batch notifica no fim).
        $jobs = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $jobs[] = new SyncRestaurantJob($companyId, $d->toDateString(), notify: false);
        }
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        Bus::batch($jobs)
            ->name("restaurant-sync:{$companyId}:{$fromStr}:{$toStr}")
            ->allowFailures() // um dia a falhar não aborta os outros; o finally corre na mesma
            ->finally(function (Batch $batch) use ($companyId, $fromStr, $toStr) {
                // UMA notificação quando TODOS os dias terminam.
                $alerts = app(AlertService::class);
                if ($batch->failedJobs === 0) {
                    $alerts->createSystemAlert($companyId, 'opportunity', 'Período importado',
                        "Período de {$fromStr} a {$toStr} importado com sucesso.", 'low', '/restauracao');
                } else {
                    $alerts->createSystemAlert($companyId, 'warning', 'Período importado com erros',
                        "Período de {$fromStr} a {$toStr} importado; {$batch->failedJobs} dia(s) com erro.", 'high', '/restauracao');
                }
            })
            ->dispatch();

        return ApiResponse::success(
            ['queued' => true, 'days' => $days, 'from' => $fromStr, 'to' => $toStr],
            "A importar {$days} dia(s)… serás notificado no fim."
        );
    }

    // ── Gestão manual de lojas (o match automático fica para fase futura) ──────

    private function locationRules(): array
    {
        return [
            'winrest_store_id' => ['required', 'string', 'max:120'],
            'winrest_name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'opened_on' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['nullable', 'boolean'],
            // CoverManager (por loja) — slug não-secreto; token cifrado (cast).
            'cm_slug' => ['nullable', 'string', 'max:255'],
            'cm_token' => ['nullable', 'string', 'max:255'],
            'cm_base_url' => ['nullable', 'url', 'max:255'],
        ];
    }

    /** Campos não-secretos do CoverManager a gravar (o token trata-se à parte). */
    private function coverManagerFields(array $data): array
    {
        return [
            'cm_slug' => $data['cm_slug'] ?? null,
            'cm_base_url' => $data['cm_base_url'] ?? null,
        ];
    }

    /** Lista as lojas cadastradas da empresa. */
    public function listLocations(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        // Última sincronização de RESERVAS por loja (max synced_at das reservas).
        $lastReserv = \App\Models\CmReservationShiftSummary::where('company_id', $companyId)
            ->selectRaw('location_id, MAX(synced_at) as last')->groupBy('location_id')->pluck('last', 'location_id');

        $locations = PingwinLocation::where('company_id', $companyId)->orderBy('display_name')->get()
            ->map(function (PingwinLocation $l) use ($lastReserv) {
                $l->setAttribute('cm_last_synced_at', isset($lastReserv[$l->id]) ? \Carbon\Carbon::parse($lastReserv[$l->id])->toIso8601String() : null);
                return $l;
            });

        return ApiResponse::success($locations, 'Lojas carregadas.');
    }

    /** Cadastra uma loja (winrest_store_id único por empresa). */
    public function storeLocation(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate($this->locationRules());

        if (PingwinLocation::where('company_id', $companyId)->where('winrest_store_id', $data['winrest_store_id'])->exists()) {
            return ApiResponse::error('Já existe uma loja com esse ID PingWin.', 422);
        }

        $location = PingwinLocation::create(array_merge([
            'company_id' => $companyId,
            'winrest_store_id' => $data['winrest_store_id'],
            'winrest_name' => $data['winrest_name'] ?? null,
            'display_name' => $data['display_name'] ?? ($data['winrest_name'] ?? $data['winrest_store_id']),
            'opened_on' => $data['opened_on'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ], $this->coverManagerFields($data), [
            // Token só se veio preenchido (cifrado pelo cast).
            ...(! empty($data['cm_token']) ? ['cm_token' => $data['cm_token']] : []),
        ]));

        return ApiResponse::success($location, 'Loja cadastrada.');
    }

    /** Atualiza uma loja (tenancy: só lojas da própria empresa). */
    public function updateLocation(Request $request, int $companyId, int $locationId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $location = PingwinLocation::where('company_id', $companyId)->where('id', $locationId)->first();
        if (! $location) {
            return ApiResponse::error('Loja não encontrada.', 404);
        }

        $data = $request->validate($this->locationRules());

        $clash = PingwinLocation::where('company_id', $companyId)
            ->where('winrest_store_id', $data['winrest_store_id'])
            ->where('id', '!=', $locationId)->exists();
        if ($clash) {
            return ApiResponse::error('Já existe uma loja com esse ID PingWin.', 422);
        }

        $location->update(array_merge([
            'winrest_store_id' => $data['winrest_store_id'],
            'winrest_name' => $data['winrest_name'] ?? null,
            'display_name' => $data['display_name'] ?? ($data['winrest_name'] ?? $data['winrest_store_id']),
            'opened_on' => $data['opened_on'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ], $this->coverManagerFields($data), [
            // Token só é reescrito se veio preenchido (em branco = manter o atual).
            ...(! empty($data['cm_token']) ? ['cm_token' => $data['cm_token']] : []),
        ]));

        return ApiResponse::success($location->fresh(), 'Loja atualizada.');
    }

    /** Remove uma loja (tenancy). */
    public function deleteLocation(int $companyId, int $locationId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $location = PingwinLocation::where('company_id', $companyId)->where('id', $locationId)->first();
        if (! $location) {
            return ApiResponse::error('Loja não encontrada.', 404);
        }

        $location->delete();

        return ApiResponse::success(null, 'Loja removida.');
    }

    // ── CoverManager (reservas) — Etapa 1: sincroniza o agregado por turno ─────

    /**
     * Sincroniza as reservas (agregado por turno, SEM PII) de uma data para as
     * lojas com credencial CoverManager. Síncrono e leve (HTTP normal); uma loja
     * a falhar não aborta as outras. Notifica no sino e devolve SÓ os números.
     */
    public function coverManagerSync(Request $request, int $companyId, CoverManagerService $cover)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        // Sincronizável = loja com slug + token resolvível (loja OU empresa).
        $companyToken = $cover->companyToken($companyId);
        if ($cover->syncableLocations($companyId, $companyToken)->isEmpty()) {
            return ApiResponse::error('Liga o CoverManager (token de empresa em Integrações) e mete o slug nas lojas.', 422);
        }

        $result = $cover->sync($companyId, $data['date']);

        return ApiResponse::success($result, 'Reservas sincronizadas.');
    }

    // ── CoverManager — token AO NÍVEL DA EMPRESA (Integrações) ────────────────

    /** Estado da integração CoverManager da empresa (token de empresa configurado?). */
    public function coverManagerIndex(int $companyId, CoverManagerService $cover)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(['connected' => $cover->companyHasIntegration($companyId)], 'CoverManager (empresa).');
    }

    /** Liga o CoverManager à EMPRESA: guarda o token (cifrado). NUNCA o expõe. */
    public function coverManagerConnect(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        \App\Models\CompanyIntegration::updateOrCreate(
            ['company_id' => $companyId, 'platform' => 'covermanager'],
            ['access_token' => $data['token'], 'status' => 'active', 'error_message' => null], // cifrado pelo cast
        );

        return ApiResponse::success(['connected' => true], 'CoverManager ligado à empresa.');
    }

    /** Desliga o CoverManager da empresa (o token deixa de ser fallback). */
    public function coverManagerDisconnect(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        \App\Models\CompanyIntegration::where('company_id', $companyId)->where('platform', 'covermanager')
            ->update(['status' => 'revoked']);

        return ApiResponse::success(null, 'CoverManager desligado.');
    }

    /** Lê a flag do ticket médio (CoverManager) da empresa. */
    public function coverManagerSettings(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $enabled = (bool) (\App\Models\Company::where('id', $companyId)->value('cm_avg_ticket_enabled') ?? true);

        return ApiResponse::success(['avg_ticket_enabled' => $enabled], 'Definições CoverManager.');
    }

    /** Liga/desliga o ticket médio com o CoverManager (por empresa). */
    public function updateCoverManagerSettings(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['avg_ticket_enabled' => ['required', 'boolean']]);

        \App\Models\Company::where('id', $companyId)->update(['cm_avg_ticket_enabled' => $data['avg_ticket_enabled']]);

        return ApiResponse::success(['avg_ticket_enabled' => (bool) $data['avg_ticket_enabled']], 'Definições atualizadas.');
    }

    /** Lista as lojas descobertas + estado da ligação. */
    public function index(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $integration = CompanyIntegration::where('company_id', $companyId)->where('platform', 'pingwin')->first();

        return ApiResponse::success([
            'connected' => $integration && $integration->status === 'active',
            'status' => $integration->status ?? null,
            // Motivo real da última falha de validação (para o ecrã mostrar).
            'error_message' => $integration?->error_message,
            'last_synced_at' => optional($integration?->last_synced_at)->toIso8601String(),
            // IDs não-secretos (para pré-preencher o formulário ao reconfigurar).
            // A SENHA vive em access_token (cifrada) e NUNCA é devolvida aqui.
            'config' => $integration?->config ?? null,
            // Lojas descobertas (mapeadas para o formato que o cartão de Integrações usa).
            'stores' => PingwinLocation::where('company_id', $companyId)->get()->map(fn ($l) => [
                'id' => $l->id,
                'external_id' => $l->winrest_store_id,
                'code' => $l->winrest_store_id,
                'description' => $l->display_name ?: $l->winrest_name,
            ]),
        ], 'PingWin carregado.');
    }
}
