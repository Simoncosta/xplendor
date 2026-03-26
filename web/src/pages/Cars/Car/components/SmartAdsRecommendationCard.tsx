type SmartAdsRecommendation = {
    action: "scale_ads" | "test_campaign" | "review_campaign" | "do_not_invest";
    total_budget: number;
    daily_budget: number;
    duration_days: number;
    confidence_score: number;
    expected_leads: string;
    source?: "smart_ads" | "ai_analysis";
    is_fallback?: boolean;
    title: string;
    summary: string;
    reason: string;
    cta_primary_label: string;
    cta_secondary_label?: string;
};

export type RecommendedCreative = {
    source_idea_id: number;
    content_type: "sale" | "authority" | "engagement";
    title?: string | null;
    hook?: string | null;
    primary_texts?: string[];
    headlines?: string[];
    descriptions?: string[];
    cta?: string | null;
    format?: string | null;
    caption?: string | null;
    angle?: string | null;
    goal?: string | null;
    why_now?: string | null;
    reason?: string | null;
};

type Props = {
    recommendation: SmartAdsRecommendation | null;
    recommendedCreative?: RecommendedCreative | null;
    recommendedPlatform?: "meta" | "google" | null;
    marketingUrl?: string;
    metrics?: {
        views?: number;
        leads?: number;
        interactions?: number;
        interest_rate?: number;
    } | null;
};

type ToneConfig = {
    badgeClass: string;
    badgeIcon: string;
    urgencyLabel: string;
    accent: string;
    softBackground: string;
    heroIcon: string;
};

const actionConfig: Record<SmartAdsRecommendation["action"], ToneConfig> = {
    scale_ads: {
        badgeClass: "bg-success-subtle text-success",
        badgeIcon: "ri-rocket-line",
        urgencyLabel: "Escalar investimento",
        accent: "#0ab39c",
        softBackground: "linear-gradient(180deg, #ffffff 0%, rgba(10,179,156,0.04) 100%)",
        heroIcon: "ri-line-chart-line",
    },
    test_campaign: {
        badgeClass: "bg-primary-subtle text-primary",
        badgeIcon: "ri-flask-line",
        urgencyLabel: "Testar campanha",
        accent: "#405189",
        softBackground: "linear-gradient(180deg, #ffffff 0%, rgba(64,81,137,0.04) 100%)",
        heroIcon: "ri-megaphone-line",
    },
    review_campaign: {
        badgeClass: "bg-warning-subtle text-warning",
        badgeIcon: "ri-error-warning-line",
        urgencyLabel: "Rever campanha",
        accent: "#f7b84b",
        softBackground: "linear-gradient(180deg, #ffffff 0%, rgba(247,184,75,0.05) 100%)",
        heroIcon: "ri-search-eye-line",
    },
    do_not_invest: {
        badgeClass: "bg-light text-muted",
        badgeIcon: "ri-pause-circle-line",
        urgencyLabel: "Sem investimento",
        accent: "#6c757d",
        softBackground: "linear-gradient(180deg, #ffffff 0%, rgba(108,117,125,0.04) 100%)",
        heroIcon: "ri-shield-check-line",
    },
};

const sourceMeta = {
    smart_ads: {
        label: "Decisão automática",
        badgeClass: "bg-dark-subtle text-dark",
    },
    ai_analysis: {
        label: "Baseado em análise",
        badgeClass: "bg-light text-muted",
    },
} as const;

const platformMeta = {
    meta: {
        label: "Meta Ads",
        icon: "ri-facebook-circle-line",
    },
    google: {
        label: "Google Ads",
        icon: "ri-google-line",
    },
    none: {
        label: "Sem canal prioritário",
        icon: "ri-subtract-line",
    },
} as const;

const formatCurrency = (value: number) =>
    new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value || 0);

const shellStyle = {
    border: "1px solid #e9ebec",
    borderRadius: "18px",
    background: "#fff",
    overflow: "hidden",
} as const;

export default function SmartAdsRecommendationCard({
    recommendation,
    recommendedCreative,
    recommendedPlatform,
    marketingUrl,
    metrics,
}: Props) {
    if (!recommendation) {
        return (
            <section style={shellStyle}>
                <div style={{ padding: "18px 20px" }}>
                    <div className="d-flex align-items-start gap-3">
                        <div
                            className="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center flex-shrink-0"
                            style={{ width: 52, height: 52 }}
                        >
                            <i className="ri-brain-line fs-3" />
                        </div>
                        <div>
                            <span className="badge bg-primary-subtle text-primary mb-2">Decisão recomendada</span>
                            <h5 className="mb-2">Ainda não existe recomendação automática para esta viatura</h5>
                            <p className="text-muted mb-0 fs-13">
                                Assim que o motor de recomendação estiver disponível, este espaço vai apresentar a melhor decisão de investimento e o criativo recomendado.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        );
    }

    const config = actionConfig[recommendation.action];
    const source = recommendation.source ?? "smart_ads";
    const sourceConfig = sourceMeta[source];
    const platform = recommendedPlatform ? platformMeta[recommendedPlatform] : platformMeta.none;
    const headlines = (recommendedCreative?.headlines || []).slice(0, 3);
    const primaryTexts = (recommendedCreative?.primary_texts || []).slice(0, 3);
    const descriptions = (recommendedCreative?.descriptions || []).slice(0, 2);

    return (
        <section style={{ ...shellStyle, background: config.softBackground }}>
            <div className="row g-0">
                <div className="col-xl-5" style={{ borderRight: "1px solid #e9ebec" }}>
                    <div style={{ padding: "20px" }}>
                        <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                            <span className={`badge ${config.badgeClass} px-3 py-2 fs-12`}>
                                <i className={`${config.badgeIcon} me-1`} />
                                Decisão recomendada
                            </span>
                            <span className={`badge ${sourceConfig.badgeClass} px-3 py-2 fs-11`}>
                                {sourceConfig.label}
                            </span>
                        </div>

                        <div className="d-flex align-items-start gap-3 mb-3">
                            <div
                                className="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                style={{
                                    width: 50,
                                    height: 50,
                                    background: `${config.accent}16`,
                                    color: config.accent,
                                }}
                            >
                                <i className={`${config.heroIcon} fs-2`} />
                            </div>
                            <div>
                                <p className="text-muted text-uppercase fw-semibold mb-1 fs-11" style={{ letterSpacing: "0.08em" }}>
                                    SmartAds Assistido
                                </p>
                                <h3 className="mb-2 fw-semibold" style={{ lineHeight: 1.15 }}>{recommendation.title}</h3>
                                <p className="text-muted fs-14 mb-0" style={{ lineHeight: 1.6 }}>
                                    {recommendation.summary}
                                </p>
                            </div>
                        </div>

                        <div className="mb-4">
                            <div className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                Porque
                            </div>
                            <p className="mb-0 fs-14 text-body" style={{ lineHeight: 1.65 }}>
                                {recommendation.reason}
                            </p>
                        </div>

                        <div className="d-flex gap-2 flex-wrap">
                            <KpiPill label="Views" value={metrics?.views ?? 0} />
                            <KpiPill label="Leads" value={metrics?.leads ?? 0} />
                            <KpiPill label="Interações" value={metrics?.interactions ?? 0} />
                            <KpiPill label="Conversão" value={`${Number(metrics?.interest_rate ?? 0).toFixed(2)}%`} />
                        </div>
                    </div>
                </div>

                <div className="col-xl-7">
                    <div style={{ padding: "20px" }}>
                        <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-4">
                            <div>
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                    Ação
                                </p>
                                <div className="d-flex align-items-center gap-3">
                                    <div
                                        className="rounded-circle d-flex align-items-center justify-content-center"
                                        style={{
                                            width: 44,
                                            height: 44,
                                            background: `${config.accent}16`,
                                            color: config.accent,
                                        }}
                                    >
                                        <i className={`${platform.icon} fs-3`} />
                                    </div>
                                    <div>
                                        <div className="fw-semibold text-body">{platform.label}</div>
                                        <div className="text-muted fs-12">Canal recomendado para executar agora</div>
                                    </div>
                                </div>
                            </div>

                            <div className="d-flex gap-2 flex-wrap">
                                <InlineMetric label="Budget" value={formatCurrency(recommendation.total_budget)} />
                                <InlineMetric label="Leads esperados" value={recommendation.expected_leads} />
                                <InlineMetric label="Confiança" value={`${recommendation.confidence_score}%`} />
                            </div>
                        </div>

                        {recommendedCreative && (
                            <div>
                                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                            Criativo recomendado
                                        </p>
                                        <h5 className="mb-1 fw-semibold">{recommendedCreative.title || "Criativo pronto a usar"}</h5>
                                        <p className="text-muted fs-12 mb-0">
                                            {recommendedCreative.reason || "Criativo alinhado com a decisão de investimento atual."}
                                        </p>
                                    </div>
                                    {marketingUrl && (
                                        <a href={marketingUrl} className="btn btn-sm btn-primary">
                                            <i className="ri-arrow-right-line me-1" />
                                            Ver briefing completo
                                        </a>
                                    )}
                                </div>

                                <div
                                    style={{
                                        borderTop: "1px solid #e9ebec",
                                        paddingTop: "16px",
                                    }}
                                >
                                    <div className="mb-3">
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                            Criativo pronto a usar
                                        </p>
                                        <div className="row g-2">
                                            <div className="col-md-7">
                                                <HighlightBlock
                                                    label="Hook"
                                                    value={recommendedCreative.hook || "Sem hook definido."}
                                                />
                                            </div>
                                            <div className="col-md-5">
                                                <HighlightBlock
                                                    label="CTA"
                                                    value={recommendedCreative.cta || "Sem CTA definido."}
                                                />
                                            </div>
                                            <div className="col-md-6">
                                                <MetaBlock
                                                    label="Formato"
                                                    value={recommendedCreative.format || "Sem formato definido."}
                                                />
                                            </div>
                                            <div className="col-md-6">
                                                <MetaBlock
                                                    label="Tipo"
                                                    value={recommendedCreative.content_type}
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    {(headlines.length > 0 || primaryTexts.length > 0 || descriptions.length > 0) && (
                                        <div>
                                            <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                                Variações
                                            </p>

                                            {headlines.length > 0 && (
                                                <div className="mb-3">
                                                    <div className="text-muted fs-12 fw-semibold mb-2">Headlines</div>
                                                    <div className="d-flex flex-wrap gap-2">
                                                        {headlines.map((headline, index) => (
                                                            <span key={`${headline}-${index}`} className="badge rounded-pill bg-light text-body px-3 py-2 fs-12">
                                                                {headline}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {primaryTexts.length > 0 && (
                                                <div className="mb-3">
                                                    <div className="text-muted fs-12 fw-semibold mb-2">Primary texts</div>
                                                    <div className="vstack gap-2">
                                                        {primaryTexts.map((text, index) => (
                                                            <div key={`${text}-${index}`} className="fs-13 text-body py-2 border-top">
                                                                {text}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {descriptions.length > 0 && (
                                                <div>
                                                    <div className="text-muted fs-12 fw-semibold mb-2">Descriptions</div>
                                                    <div className="vstack gap-2">
                                                        {descriptions.map((description, index) => (
                                                            <div key={`${description}-${index}`} className="fs-13 text-muted">
                                                                {description}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}

function KpiPill({ label, value }: { label: string; value: string | number }) {
    return (
        <div
            className="d-inline-flex align-items-center gap-2 rounded-pill"
            style={{ padding: "10px 12px", background: "rgba(255,255,255,0.85)", border: "1px solid #e9ebec" }}
        >
            <span className="text-muted fs-12">{label}</span>
            <span className="fw-semibold fs-13 text-body">{value}</span>
        </div>
    );
}

function InlineMetric({ label, value }: { label: string; value: string }) {
    return (
        <div
            className="rounded-pill"
            style={{ padding: "10px 12px", background: "rgba(255,255,255,0.85)", border: "1px solid #e9ebec" }}
        >
            <div className="text-muted fs-11 text-uppercase fw-semibold mb-1">{label}</div>
            <div className="fw-semibold fs-13 text-body">{value}</div>
        </div>
    );
}

function HighlightBlock({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-3" style={{ padding: "14px 16px", background: "#f8fafc", border: "1px solid #e9ebec" }}>
            <div className="text-muted fs-11 text-uppercase fw-semibold mb-1" style={{ letterSpacing: "0.08em" }}>
                {label}
            </div>
            <div className="fs-14 text-body fw-medium">{value}</div>
        </div>
    );
}

function MetaBlock({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-3" style={{ padding: "12px 14px", background: "#fff", border: "1px solid #e9ebec" }}>
            <div className="text-muted fs-11 text-uppercase fw-semibold mb-1" style={{ letterSpacing: "0.08em" }}>
                {label}
            </div>
            <div className="fs-13 text-body">{value}</div>
        </div>
    );
}
