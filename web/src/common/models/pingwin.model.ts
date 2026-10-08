// XPLENDOR — PingWin (POS restauração). Tipos da UI de cadastro/estado.
// O backend (CompanyPingwinController) cifra a senha; nunca a devolve. Por isso
// o estado (index) NÃO tem password — só se reescreve ao (re)configurar.

/**
 * Campos que o endpoint /integrations/pingwin/connect exige.
 * SÓ estes 3 variam por restaurante — o resto (URLs, versão, report_id, etc.) é
 * global e vive no .env do servidor (config('services.pingwin')).
 */
export interface PingwinConnectPayload {
    username: string;   // login do restaurante
    database: string;   // X-Database (identificador do restaurante no PingWin)
    password: string;   // cifrada no servidor; nunca devolvida
}

/** Uma loja descoberta (fetch_stores) com o último resumo de vendas. */
export interface PingwinStore {
    id: number;
    external_id: string;
    code?: string | null;
    description?: string | null;
    city?: string | null;
    last_summary?: Record<string, any> | null;
    last_synced_at?: string | null;
}

/** Resposta do GET /integrations/pingwin (estado da ligação). */
export interface PingwinStatus {
    connected: boolean;                 // true só quando validada (status 'active')
    status: string | null;              // 'validating' | 'active' | 'error' | 'revoked' | null
    error_message?: string | null;      // motivo real da última falha de validação
    last_synced_at: string | null;
    /** IDs não-secretos guardados (para pré-preencher ao reconfigurar). Sem senha. */
    config: Partial<PingwinConfigFields> | null;
    stores: PingwinStore[];
}

/** Campos não-secretos (tudo menos password) — para pré-preencher ao reconfigurar. */
export type PingwinConfigFields = Omit<PingwinConnectPayload, "password">;

/** Loja PingWin cadastrada (manual). winrest_store_id vai no "Stores" do relatório. */
export interface PingwinLocationEntity {
    id: number;
    winrest_store_id: string;
    winrest_name: string | null;
    display_name: string | null;
    opened_on: string | null; // YYYY-MM-DD | null
    is_active: boolean;
    // CoverManager (por loja) — o token nunca é devolvido; cm_connected indica se está ligado.
    cm_slug?: string | null;
    cm_base_url?: string | null;
    cm_connected?: boolean;
    cm_has_override?: boolean;
    cm_last_synced_at?: string | null; // última sincronização de reservas desta loja
}

// ── Dashboard de restauração (valores em CÊNTIMOS inteiros) ──────────────────
export interface PingwinMoneyPair {
    invoiced_cents: number; // faturado c/IVA
    net_cents: number;      // vendas líquidas
}

export interface PingwinAnnualCard extends PingwinMoneyPair {
    year: number;
    prev: PingwinMoneyPair | null;
    delta_pct_invoiced: number | null; // null = "—" (portão de honestidade)
    delta_pct_net: number | null;
    comparable: boolean;
}

export interface PingwinMonthlyCard extends PingwinMoneyPair {
    month: string; // "YYYY-MM"
}

export interface PingwinDailyCard extends PingwinMoneyPair {
    date: string;
    prev_date: string;
    prev: PingwinMoneyPair | null;
    delta_pct_invoiced: number | null;
    delta_pct_net: number | null;
    comparable: boolean;
}

export interface PingwinLocationRow {
    id: number;
    display_name: string;
    annual: PingwinMoneyPair;
    monthly: PingwinMoneyPair;
    daily: PingwinMoneyPair;
    occupancy: PingwinOccupancy; // lotação (pessoas) por período, desta loja
    last_synced_at: string | null;
}

/** Ticket médio (cêntimos por pessoa) por período; null = "—" (sem dados/flag off). */
export interface PingwinAvgTicket {
    annual: number | null;
    monthly: number | null;
    daily: number | null;
}

/** Lotação (pessoas que reservaram) num período. has_data false → "—" (sem reservas). */
export interface PingwinOccupancyPeriod {
    has_data: boolean;
    total: number;
    lunch: number;
    dinner: number;
    other: number;
}

export interface PingwinOccupancy {
    annual: PingwinOccupancyPeriod;
    monthly: PingwinOccupancyPeriod;
    daily: PingwinOccupancyPeriod;
}

/** Tipo de documento PingWin (Definições→Documentos), só leitura. */
export interface PingwinDocumentConfig {
    id: number;
    external_id: string;
    code: string | null;
    description: string | null;
    entitytype: string | null;
    fiscaltype: string | null;
    fiscaltype_description: string | null;
    deleted: boolean;
    synced_at: string | null;
    // Fase D0 (leitura rica) — só presentes no DETALHE (a lista é leve). Tudo opcional.
    taxscenario_id?: string | null;
    doctype_id?: string | null;
    docfiscaltype_id?: string | null;
    default_paycond_id?: string | null;
    stock_signal?: string | null;
    docseries_id?: string | null;
    rich_synced_at?: string | null;
    raw?: Record<string, any> | null;                 // maindataset (~50 campos, incl. _descr)
    options?: Record<string, Array<Record<string, any>>> | null;
    additionalfields_maindataset?: Array<Record<string, any>> | null;
    additionalfields_storedataset?: Array<Record<string, any>> | null;
    // 14 filhas (nome = chave do servidor; cada linha com deleted 0/1).
    entitytype_docconfig?: Array<Record<string, any>> | null;
    docconfig_detailstatus?: Array<Record<string, any>> | null;
    default_detailstatus?: Array<Record<string, any>> | null;
    docconfig_docmovreason?: Array<Record<string, any>> | null;
    docconfig_docstatus?: Array<Record<string, any>> | null;
    default_docsatatus?: Array<Record<string, any>> | null;
    docconfig_docaccount?: Array<Record<string, any>> | null;   // tem credit/debit por linha
    docconfig_local?: Array<Record<string, any>> | null;
    docconfig_import?: Array<Record<string, any>> | null;
    docconfig_paymethod?: Array<Record<string, any>> | null;
    docconfig_docreference?: Array<Record<string, any>> | null;
    docconfig_paycond?: Array<Record<string, any>> | null;      // traz paycond_id
    userrole_docconfig?: Array<Record<string, any>> | null;
    store_docconfig?: Array<Record<string, any>> | null;
}

/** Ligação resolvida documento→condição de pagamento (detalhe rico). */
export interface PingwinDocPaycondLink {
    paycond_id: string;
    description: string | null;
    linked: boolean;      // deleted:0 = vinculada ao documento
    in_mirror: boolean;   // existe em pingwin_payment_conditions
    is_active: boolean | null;
}

/** Artigo (produto) do catálogo PingWin, só leitura. Preços em CÊNTIMOS inteiros. */
export interface PingwinCatalogItem {
    id: number;
    pingwin_id: string;
    code: string | null;
    description: string | null;
    family: string | null;
    family_pingwin_id: string | null;
    forsale: boolean;
    forpurchase: boolean;
    has_bom: boolean;
    product_type: string | null;
    product_status: string | null;
    taxgroup: string | null;
    printzone: string | null;
    saleprice_cents: number | null;
    purchaseprice_cents: number | null;
    saleunit: string | null;
    purchaseunit: string | null;
    order_code: string | null;
    supplier_code: string | null; // preparado; matching é fase futura
    is_active: boolean;
    synced_at: string | null;
}

/** Fornecedor do PingWin, só leitura. */
export interface PingwinSupplier {
    id: number;
    pingwin_id: string;
    code: string | null;
    name: string | null;
    fiscal_name: string | null;  // nome fiscal (fiscalname)
    tax_number: string | null;   // NIF
    address: string | null;
    city: string | null;
    postal_code: string | null;
    phone: string | null;
    email: string | null;
    is_active: boolean;
    synced_at: string | null;
}

/** Unidade PingWin (base de conversão), só leitura. A conversão vem pronta a ler. */
export interface PingwinUnitRow {
    id: number;
    pingwin_id: string;
    description: string | null;
    shortname: string | null;
    is_global: boolean;               // true = unidade global; false = específica de um artigo
    parent_pingwin_id: string | null;  // unidade-base (id) — para pré-preencher o editar
    parent_description: string | null; // unidade-base (se houver conversão)
    unit_value: number | null;         // fator de conversão
    net_weight: number | null;
    external_measure: boolean;         // medição externa (checkbox)
    frac_unit: boolean;                // unidade fracionária (checkbox)
    warn_maxsale_qnt: number | null;   // qnt. máx. venda
    conversion_label: string | null;   // "1 Barril 50lt = 50 Litros" (pronto a mostrar)
    purchase: boolean;
    sale: boolean;
    stock: boolean;
    is_active: boolean;                 // = NOT deleted
}

/** Nó da árvore de famílias PingWin (montada no backend, flat→nested). */
export interface PingwinFamilyNode {
    id: number;
    pingwin_id: string;
    description: string | null;
    item_count: number;          // artigos ligados a ESTA família (só o próprio nó)
    children: PingwinFamilyNode[];
}

/** Paginador do Laravel (paginate()) — página de EXIBIÇÃO (aos poucos). */
/** Documento vinculado a uma condição de pagamento (filho tbdocs do PingWin). */
export interface PingwinPaymentConditionDoc {
    docconfig_id?: string | number | null;
    description?: string | null;
    entitytype?: string | null;   // Cliente / Fornecedor / Armazém / Empregado
    deleted?: boolean | number | null;
    [key: string]: unknown;
}

/** Condição de pagamento do PingWin (paycond), só leitura. discount em % (não cêntimos). */
export interface PingwinPaymentCondition {
    id: number;
    pingwin_id: string;
    code: string | null;
    description: string | null;
    discount: string | null;   // percentagem (decimal:2 → string, ex.: "2.50")
    days: number | null;        // dias de vencimento
    is_active: boolean;
    tbdocs: PingwinPaymentConditionDoc[] | null;
    synced_at: string | null;
}

export interface LaravelPaginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/** Faturação mensal por loja: uma série por loja (12 valores em EUROS; null = mês sem sync). */
export interface PingwinMonthlySeries {
    location_id: number;
    name: string;
    data: (number | null)[];
}
export interface PingwinMonthlyBilling {
    year: number;
    series: PingwinMonthlySeries[];
}

/** Um dia no calendário de faturação (cêntimos; guests/ticket null = sem dados). */
export interface PingwinCalendarDay {
    date: string; // YYYY-MM-DD
    invoiced_cents: number;
    net_cents: number;
    guests: number | null;
    avg_ticket_cents: number | null;
}

export interface PingwinCalendarResponse {
    month: string; // YYYY-MM
    location_id: number | null;
    days: PingwinCalendarDay[];
    locations: { id: number; display_name: string | null; winrest_name: string | null; winrest_store_id: string }[];
}

export interface PingwinDashboard {
    date: string;
    currency: string;
    annual: PingwinAnnualCard;
    monthly: PingwinMonthlyCard;
    daily: PingwinDailyCard;
    locations: PingwinLocationRow[];
    avg_ticket_enabled: boolean;
    avg_ticket: PingwinAvgTicket;
    occupancy: PingwinOccupancy;
}

/** F1-3: cartão "Dados para o marketing" (GET .../pingwin/marketing-data). */
export interface MarketingDataLocation {
    location_id: number;
    name: string;
    opened_on: string | null;
    detected_start: string | null;
    detected_is_month: boolean;
    effective_start: string | null;
    start_warning: boolean;
    start_difference_days: number | null;
    start_checked: boolean;
    days_read: number;
    oldest_day_read: string | null;
    history_complete: boolean;
    history_complete_at: string | null;
}

export interface MarketingData {
    enabled: boolean;
    last_read_at: string | null;
    catalog: { sold: number; missing: number; coverage_pct: number | null };
    days: { checked: number; ok: number; marked: number };
    families: { total: number; unconfirmed: number; revenue_unconfirmed_pct: number | null };
    locations: MarketingDataLocation[];
    /** Reservas do CoverManager com um código de estado fora do mapa (não contam nas válidas nem nas anuladas). */
    reservations?: { days: number; unclassified: number; codes: Record<string, number> };
    computed_at: string | null;
    can_manage: boolean;
    /** Postos de venda do relatório anual: só o root os vê e edita. */
    can_edit_locals: boolean;
    annual_locals: string | null;
}

/** F1-3: categorias das famílias (GET .../pingwin/family-categories). */
export interface FamilyCategoryRow {
    family_pingwin_id: string;
    family_path: string | null;
    family: string;
    /** Teve vendas nos últimos 90 dias (as outras ficam numa secção à parte). */
    recent: boolean;
    net_cents_90d: number;
    net_cents_total: number;
    last_sale_date: string | null;
    share_pct: number;
    suggested_category: string | null;
    suggested_by: "rules" | "ai" | null;
    category: string | null;
    confirmed_by: string | null;
    confirmed_at: string | null;
}

export interface FamilyCategoriesData {
    categories: { value: string; label: string }[];
    families: FamilyCategoryRow[];
    total_net_cents_90d: number;
    pending: number;
    can_manage: boolean;
    /** Artigos que não voltam a ser sugeridos (Bússola), com quem e quando. */
    excluded_items?: { id: number; product_pingwin_id: string; name: string; excluded_by: string | null; excluded_at: string | null }[];
}

/** F3: um sinal de "O que publicar e quando" (GET .../pingwin/signals). */
export interface RestaurantSignalItem {
    key: string;
    type: "top_items" | "top_categories" | "item_up" | "item_down" | "weak_period" | "stale_item" | "lead_time" | "delivery_share" | "channels";
    kind: "suggestion" | "info";
    location_id: number | null;
    location: string | null;
    confidence: "alta" | "media";
    title: string;
    sentence: string;
    numbers: Record<string, any>;
    sample: Record<string, any>;
    theme: string | null;
    suggested_date: string | null;
    priority: number;
    hidden: boolean;
    hidden_until: string | null;
    post: { id: number; title: string; publish_date: string; stage: string } | null;
}

export interface SignalAvailability {
    location_id: number;
    name: string;
    start: string | null;
    yoy_from: string | null;
    signals: Record<string, { available: boolean; from: string | null; reason: string | null; mode?: string }>;
}

export interface RestaurantSignalsData {
    enabled: boolean;
    computed_at: string | null;
    locations: { id: number; name: string }[];
    location_id: number | null;
    can_act: boolean;
    can_act_reason: string | null;
    formats: string[];
    networks: string[];
    /** Dias especiais dos próximos 90 dias (data => nome): o modal sugere "Sazonal" nessas datas. */
    special_days: Record<string, string>;
    ignore_days: number;
    show_ignored: boolean;
    suggestions: RestaurantSignalItem[];
    changes: RestaurantSignalItem[];
    top_items: RestaurantSignalItem[];
    top_categories: RestaurantSignalItem[];
    lead_time: RestaurantSignalItem[];
    delivery: RestaurantSignalItem[];
    channels: RestaurantSignalItem[];
    availability: SignalAvailability[];
    hidden_count: number;
    categories_pending: number;
}
