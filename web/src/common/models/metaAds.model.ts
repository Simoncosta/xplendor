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

export interface MetaOverviewResponse {
    connected: boolean;
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
