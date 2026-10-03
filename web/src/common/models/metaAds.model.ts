// XPLENDOR — Meta (Facebook/Instagram Ads), LEITURA. Dados FACTUAIS que o
// pipeline já ingere (gasto/cliques/CTR + vendas atribuídas). Zero interpretação.

export interface MetaOverview {
    spend: number;
    impressions: number;
    clicks: number;
    ctr: number;   // %
    cpc: number;   // €/clique
}

export interface MetaCampaignRow {
    campaign_id: string | null;
    campaign_name: string;
    spend: number;
    impressions: number;
    clicks: number;
    ctr: number;
}

export interface MetaTrendPoint { date: string; spend: number; clicks: number; }

export interface MetaAttributedCampaign { campaign_id: string | null; campaign_name: string; sales: number; revenue: number; }

export interface MetaAttributed {
    sales: number;
    revenue: number;
    avg_confidence: number | null;
    by_campaign: MetaAttributedCampaign[];
}

/** Estado honesto calculado no backend (o ecrã só reflecte), por precedência. */
export type MetaOverviewState =
    | "not_connected"
    | "token_expired"   // "Sessão Meta expirada — reconectar"
    | "needs_account"   // "Falta escolher a conta de anúncios"
    | "syncing_first"   // "A sincronizar pela primeira vez…"
    | "sync_failed"     // 1.º sync falhou (sync.error)
    | "no_spend"        // zero REAL: tudo certo mas sem gasto no período
    | "ok";

/** De onde vêm os números: conta (todas as verticais), legado por carro (stands
 *  ainda sem backfill da conta) ou nada. */
export type MetaOverviewSource = "account" | "car_legacy" | "none";

export interface MetaSyncInfo {
    status: string | null;
    backfilled_at: string | null;
    last_run_at: string | null;
    error: string | null;
}

export interface MetaOverviewResponse {
    connected: boolean;
    state?: MetaOverviewState;
    source?: MetaOverviewSource;
    account_id?: string | null;
    sync?: MetaSyncInfo;
    status?: string | null;
    last_synced_at?: string | null;
    range: { start: string; end: string; days: number };
    overview: MetaOverview;
    by_campaign: MetaCampaignRow[];
    trend: MetaTrendPoint[];
    attributed: MetaAttributed;
}

export const eur = (v: number | null | undefined) =>
    v == null ? "—" : new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(v);
export const nfmt = (v: number | null | undefined) =>
    v == null ? "—" : new Intl.NumberFormat("pt-PT").format(v);
export const pct = (v: number | null | undefined) => (v == null ? "—" : `${v}%`);
