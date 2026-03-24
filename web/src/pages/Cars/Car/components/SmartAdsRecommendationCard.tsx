import { Card, CardBody, Col, Row } from "reactstrap";

export type SmartAdsRecommendation = {
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

const sourceMeta = {
    smart_ads: {
        label: "Decisão automática",
        badgeClass: "bg-dark-subtle text-dark",
        cardOpacity: 1,
    },
    ai_analysis: {
        label: "Baseado em análise",
        badgeClass: "bg-light text-muted",
        cardOpacity: 0.88,
    },
} as const;

type Props = {
    recommendation: SmartAdsRecommendation | null;
    recommendedPlatform?: "meta" | "google" | null;
    onPrimaryAction?: () => void;
    onSecondaryAction?: () => void;
};

type ToneConfig = {
    badgeClass: string;
    badgeIcon: string;
    urgencyLabel: string;
    accent: string;
    softBackground: string;
    iconWrapClass: string;
    heroIcon: string;
};

const actionConfig: Record<SmartAdsRecommendation["action"], ToneConfig> = {
    scale_ads: {
        badgeClass: "bg-success-subtle text-success",
        badgeIcon: "ri-rocket-line",
        urgencyLabel: "Alta prioridade",
        accent: "#0ab39c",
        softBackground: "linear-gradient(135deg, rgba(10,179,156,0.16) 0%, rgba(10,179,156,0.03) 100%)",
        iconWrapClass: "bg-success-subtle text-success",
        heroIcon: "ri-line-chart-line",
    },
    test_campaign: {
        badgeClass: "bg-primary-subtle text-primary",
        badgeIcon: "ri-flask-line",
        urgencyLabel: "Testar campanha",
        accent: "#405189",
        softBackground: "linear-gradient(135deg, rgba(64,81,137,0.14) 0%, rgba(64,81,137,0.03) 100%)",
        iconWrapClass: "bg-primary-subtle text-primary",
        heroIcon: "ri-megaphone-line",
    },
    review_campaign: {
        badgeClass: "bg-warning-subtle text-warning",
        badgeIcon: "ri-error-warning-line",
        urgencyLabel: "Rever campanha",
        accent: "#f7b84b",
        softBackground: "linear-gradient(135deg, rgba(247,184,75,0.18) 0%, rgba(247,184,75,0.04) 100%)",
        iconWrapClass: "bg-warning-subtle text-warning",
        heroIcon: "ri-search-eye-line",
    },
    do_not_invest: {
        badgeClass: "bg-light text-muted",
        badgeIcon: "ri-pause-circle-line",
        urgencyLabel: "Sem investimento recomendado",
        accent: "#6c757d",
        softBackground: "linear-gradient(135deg, rgba(108,117,125,0.14) 0%, rgba(108,117,125,0.03) 100%)",
        iconWrapClass: "bg-light text-muted",
        heroIcon: "ri-shield-check-line",
    },
};

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
        label: "Sem investimento recomendado",
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

const metricCardStyle = {
    border: "1px solid rgba(233, 235, 236, 0.9)",
    borderRadius: "0.85rem",
    background: "rgba(255,255,255,0.82)",
    padding: "0.8rem 0.95rem",
    height: "100%",
    boxShadow: "0 10px 30px rgba(15, 23, 42, 0.04)",
} as const;

export default function SmartAdsRecommendationCard({
    recommendation,
    recommendedPlatform,
    onPrimaryAction,
    onSecondaryAction,
}: Props) {
    if (!recommendation) {
        return (
            <Card
                className="mb-0 border-0"
                style={{
                    background: "linear-gradient(135deg, rgba(64,81,137,0.08) 0%, rgba(64,81,137,0.02) 100%)",
                    boxShadow: "0 18px 42px rgba(15, 23, 42, 0.08)",
                }}
            >
                <CardBody className="p-4">
                    <div className="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
                        <div className="d-flex align-items-start gap-3">
                            <div
                                className="avatar-md rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center flex-shrink-0"
                                style={{ width: 56, height: 56 }}
                            >
                                <i className="ri-brain-line fs-3" />
                            </div>
                            <div>
                                <span className="badge bg-primary-subtle text-primary mb-2">SmartAds Assistido</span>
                                <h5 className="mb-2">Ainda não existe recomendação automática para esta viatura</h5>
                                <p className="text-muted mb-0 fs-13">
                                    Assim que o motor de recomendação estiver disponível, este espaço vai apresentar a melhor
                                    decisão de investimento para este anúncio.
                                </p>
                            </div>
                        </div>
                        <div
                            className="rounded-3 px-3 py-2 text-muted fs-13"
                            style={{ border: "1px dashed #d0d6e1", background: "rgba(255,255,255,0.72)" }}
                        >
                            Bloco preparado para integração com backend
                        </div>
                    </div>
                </CardBody>
            </Card>
        );
    }

    const config = actionConfig[recommendation.action];
    const source = recommendation.source ?? "smart_ads";
    const sourceConfig = sourceMeta[source];
    const platform = recommendedPlatform ? platformMeta[recommendedPlatform] : null;

    return (
        <Card
            className="mb-0 border-0 overflow-hidden"
            style={{
                background: config.softBackground,
                boxShadow: "0 18px 46px rgba(15, 23, 42, 0.1)",
                opacity: sourceConfig.cardOpacity,
            }}
        >
            <CardBody className="p-4">
                <Row className="g-3 g-xl-4 align-items-stretch">
                    <Col xl={5}>
                        <div className="h-100 d-flex flex-column">
                            <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                <span className={`badge ${config.badgeClass} fs-12 px-3 py-2`}>
                                    <i className={`${config.badgeIcon} me-1`} />
                                    {config.urgencyLabel}
                                </span>
                                <div className="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                    <span className={`badge ${sourceConfig.badgeClass} fs-11 px-3 py-2`}>
                                        {sourceConfig.label}
                                    </span>
                                    <span className="text-muted fs-12 text-uppercase fw-semibold" style={{ letterSpacing: "0.08em" }}>
                                        SmartAds Assistido
                                    </span>
                                </div>
                            </div>

                            <div className="d-flex align-items-start gap-3 mb-3">
                                <div
                                    className={`rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 ${config.iconWrapClass}`}
                                    style={{ width: 54, height: 54 }}
                                >
                                    <i className={`${config.heroIcon} fs-2`} />
                                </div>
                                <div>
                                    <p className="text-muted text-uppercase fw-semibold mb-2 fs-11" style={{ letterSpacing: "0.08em" }}>
                                        Decisão recomendada
                                    </p>
                                    <h3 className="mb-2 fw-semibold" style={{ lineHeight: 1.15 }}>{recommendation.title}</h3>
                                    <p className="text-muted fs-14 mb-0" style={{ lineHeight: 1.7 }}>
                                        {recommendation.summary}
                                    </p>
                                </div>
                            </div>

                            <div
                                className="rounded-3 p-3 p-xl-4 mb-2"
                                style={{
                                    borderLeft: `5px solid ${config.accent}`,
                                    background: "rgba(255,255,255,0.88)",
                                    boxShadow: "0 12px 30px rgba(15, 23, 42, 0.06)",
                                }}
                            >
                                <p className="text-muted text-uppercase fw-semibold mb-2 fs-11" style={{ letterSpacing: "0.08em" }}>
                                    💡 Porque esta decisão?
                                </p>
                                <p className="mb-0 fs-14 text-body" style={{ lineHeight: 1.7 }}>
                                    {recommendation.reason}
                                </p>
                            </div>
                        </div>
                    </Col>

                    <Col xl={7}>
                        <div
                            className="h-100 rounded-4 p-3"
                            style={{
                                background: "rgba(255,255,255,0.6)",
                                boxShadow: "inset 0 0 0 1px rgba(255,255,255,0.55)",
                            }}
                        >
                            <Row className="g-3">
                                <Col xs={12}>
                                    <div
                                        style={{
                                            ...metricCardStyle,
                                            border: `1px solid ${config.accent}22`,
                                            background: "rgba(255,255,255,0.94)",
                                        }}
                                    >
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                            Canal recomendado
                                        </p>
                                        {platform ? (
                                            <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                                <div className="d-flex align-items-center gap-3">
                                                    <div
                                                        className="rounded-circle d-flex align-items-center justify-content-center"
                                                        style={{
                                                            width: 48,
                                                            height: 48,
                                                            background: `${config.accent}18`,
                                                            color: config.accent,
                                                        }}
                                                    >
                                                        <i className={`${platform.icon} fs-2`} />
                                                    </div>
                                                    <div>
                                                        <div className="fs-5 fw-semibold text-body">{platform.label}</div>
                                                        <p className="mb-0 fs-12 text-muted">Baseado em análise inteligente</p>
                                                    </div>
                                                </div>
                                                <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                                    <i className="ri-brain-line me-1" />
                                                    Fonte: IA
                                                </span>
                                            </div>
                                        ) : (
                                            <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                                <div>
                                                    <div className="fs-6 fw-semibold text-body">Canal ainda não definido</div>
                                                    <p className="mb-0 fs-12 text-muted">Necessário gerar análise para definir o canal recomendado.</p>
                                                </div>
                                                <span className="badge bg-light text-muted fs-12 px-3 py-2">
                                                    <i className="ri-information-line me-1" />
                                                    Aguardando IA
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </Col>

                                <Col sm={6}>
                                    <div
                                        style={{
                                            ...metricCardStyle,
                                            border: `1px solid ${config.accent}26`,
                                            background: "rgba(255,255,255,0.94)",
                                        }}
                                    >
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                            Budget total
                                        </p>
                                        <div className="fs-1 fw-bold" style={{ color: config.accent, lineHeight: 1 }}>
                                            {formatCurrency(recommendation.total_budget)}
                                        </div>
                                        <p className="mb-0 fs-12 text-muted mt-2">Investimento sugerido para esta ação</p>
                                    </div>
                                </Col>
                                <Col sm={6}>
                                    <div
                                        style={{
                                            ...metricCardStyle,
                                            border: `1px solid ${config.accent}26`,
                                            background: "rgba(255,255,255,0.94)",
                                        }}
                                    >
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                            Leads esperados
                                        </p>
                                        <div className="fs-2 fw-bold" style={{ color: config.accent, lineHeight: 1 }}>
                                            {recommendation.expected_leads}
                                        </div>
                                        <p className="mb-0 fs-12 text-muted mt-2">Resultado estimado com esta recomendação</p>
                                    </div>
                                </Col>

                                <Col sm={4}>
                                    <div style={metricCardStyle}>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                            Budget diário
                                        </p>
                                        <div className="fs-4 fw-semibold text-body">{formatCurrency(recommendation.daily_budget)}</div>
                                    </div>
                                </Col>
                                <Col sm={4}>
                                    <div style={metricCardStyle}>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                            Duração
                                        </p>
                                        <div className="fs-4 fw-semibold text-body">{recommendation.duration_days} dias</div>
                                    </div>
                                </Col>
                                <Col sm={4}>
                                    <div style={metricCardStyle}>
                                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.08em" }}>
                                            Confiança
                                        </p>
                                        <div className="fs-4 fw-semibold text-body">{recommendation.confidence_score}%</div>
                                    </div>
                                </Col>
                            </Row>
                        </div>
                    </Col>
                </Row>
            </CardBody>
        </Card>
    );
}
