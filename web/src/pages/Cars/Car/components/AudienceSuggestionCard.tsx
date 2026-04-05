type AudienceAnalysis = {
    status: "good" | "needs_optimization" | "critical";
    summary: string;
    actions: {
        keep: string[];
        remove: string[];
        add: string[];
    };
    insights: string[];
    confidence: number;
    impact_estimate: string;
};

type Props = {
    analysis: AudienceAnalysis | null;
    loading?: boolean;
};

const shellStyle = {
    border: "1px solid #e9ebec",
    borderRadius: "18px",
    background: "#fff",
    overflow: "hidden",
} as const;

const toneMap = {
    good: {
        badgeClass: "bg-success-subtle text-success",
        title: "Ajustado ao contexto atual",
    },
    needs_optimization: {
        badgeClass: "bg-warning-subtle text-warning",
        title: "A melhorar",
    },
    critical: {
        badgeClass: "bg-danger-subtle text-danger",
        title: "Prioridade de otimização",
    },
};

export default function AudienceSuggestionCard({ analysis, loading = false }: Props) {
    const tone = analysis ? toneMap[analysis.status] : toneMap.needs_optimization;

    return (
        <section style={shellStyle}>
            <div style={{ padding: "18px 20px" }}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Análise do público atual
                        </p>
                        <h5 className="mb-1 fw-semibold">{tone.title}</h5>
                        <p className="text-muted fs-13 mb-0">
                            Leitura assistida do público atual com base na campanha real e no público sugerido.
                        </p>
                    </div>

                    {analysis && (
                        <div className={`badge ${tone.badgeClass} px-3 py-2 fs-12`}>
                            {analysis.confidence}% confiança
                        </div>
                    )}
                </div>

                {loading ? (
                    <div className="text-muted fs-14">A analisar o público atual...</div>
                ) : analysis ? (
                    <div className="d-flex flex-column gap-3">
                        <div
                            className="rounded-3"
                            style={{ background: "#fafbfc", border: "1px solid #e9ebec", padding: 14 }}
                        >
                            <p className="mb-1 fw-medium text-body">{analysis.summary}</p>
                            <p className="mb-0 text-muted fs-13">{analysis.impact_estimate}</p>
                        </div>

                        <div className="row g-3">
                            <div className="col-lg-4">
                                <ActionBlock
                                    title="A rever"
                                    tone="danger"
                                    items={analysis.actions.remove}
                                    emptyLabel="Sem interesses a rever agora."
                                />
                            </div>
                            <div className="col-lg-4">
                                <ActionBlock
                                    title="Manter"
                                    tone="warning"
                                    items={analysis.actions.keep}
                                    emptyLabel="Nada a manter com força neste momento."
                                />
                            </div>
                            <div className="col-lg-4">
                                <ActionBlock
                                    title="Adicionar"
                                    tone="success"
                                    items={analysis.actions.add}
                                    emptyLabel="Sem adições prioritárias neste momento."
                                />
                            </div>
                        </div>

                        {analysis.insights.length > 0 && (
                            <div className="d-flex flex-column gap-2">
                                {analysis.insights.map((insight, index) => (
                                    <div key={index} className="text-muted fs-13">
                                        <i className="ri-arrow-right-s-line me-1" />
                                        {insight}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="text-muted fs-14">
                        Ainda nao existe leitura suficiente do público atual para esta viatura.
                    </div>
                )}
            </div>
        </section>
    );
}

function ActionBlock({
    title,
    tone,
    items,
    emptyLabel,
}: {
    title: string;
    tone: "danger" | "warning" | "success";
    items: string[];
    emptyLabel: string;
}) {
    const tones = {
        danger: {
            border: "#f7c9c3",
            background: "#fff7f5",
            badgeClass: "bg-danger-subtle text-danger",
        },
        warning: {
            border: "#f3dfb3",
            background: "#fffaf0",
            badgeClass: "bg-warning-subtle text-warning",
        },
        success: {
            border: "#bfe8d1",
            background: "#f4fbf7",
            badgeClass: "bg-success-subtle text-success",
        },
    }[tone];

    return (
        <div
            style={{
                border: `1px solid ${tones.border}`,
                background: tones.background,
                borderRadius: 14,
                padding: 14,
                minHeight: "100%",
            }}
        >
            <div className="mb-2">
                <span className={`badge ${tones.badgeClass}`}>{title}</span>
            </div>

            {items.length > 0 ? (
                <div className="d-flex flex-column gap-2">
                    {items.map((item) => (
                        <div key={item} className="fw-medium text-body fs-14">
                            {item}
                        </div>
                    ))}
                </div>
            ) : (
                <div className="text-muted fs-13">{emptyLabel}</div>
            )}
        </div>
    );
}
