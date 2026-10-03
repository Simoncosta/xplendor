import { useEffect, useState } from "react";
import { Card, CardBody, Spinner } from "reactstrap";
import { getRecommendations } from "helpers/laravel_helper";
import type { RecommendationLevel, RecommendationsResponse } from "common/models/recommendation.model";

/**
 * XPLENDOR — Cartão "Recomendações" do hub (motor de regras explicáveis).
 * Até 3, por prioridade. Cada uma: nível, título, porquê (com números) e ação.
 * Avisos honestos quando uma regra não consegue avaliar (ex.: sem permissão).
 * Não depende do mês escolhido: são recomendações para agora.
 */

const MAX_SHOWN = 3;

const LEVEL_UI: Record<RecommendationLevel, { label: string; color: string }> = {
    high: { label: "Prioridade alta", color: "danger" },
    medium: { label: "Prioridade média", color: "warning" },
    low: { label: "Prioridade baixa", color: "info" },
};

const isExternal = (url: string) => /^https?:\/\//i.test(url);

export default function RecommendationsCard({ companyId, vertical }: { companyId: number; vertical: "restaurant" | "automotive" }) {
    const [data, setData] = useState<RecommendationsResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        setLoading(true);
        setError(false);
        getRecommendations(companyId, vertical)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setError(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, vertical]);

    const recs = (data?.recommendations ?? []).slice(0, MAX_SHOWN);
    const notices = data?.notices ?? [];

    return (
        <Card className="mb-3">
            <CardBody>
                <h6 className="text-uppercase text-muted fs-12 mb-3">
                    <i className="ri-lightbulb-flash-line me-1 text-warning" />Recomendações
                </h6>

                {loading ? (
                    <div className="py-2"><Spinner size="sm" color="primary" /></div>
                ) : error ? (
                    <p className="text-muted mb-0 fs-13">Não foi possível carregar as recomendações. Tente novamente dentro de momentos.</p>
                ) : (
                    <>
                        {recs.length === 0 ? (
                            <p className="text-muted mb-0 fs-13">Sem recomendações neste momento.</p>
                        ) : (
                            <div className="vstack gap-2">
                                {recs.map((r, i) => {
                                    const lvl = LEVEL_UI[r.level];
                                    return (
                                        <div
                                            key={`${r.rule_key}-${i}`}
                                            className="d-flex flex-wrap align-items-start justify-content-between gap-3 rounded p-3"
                                            style={{ border: "1px solid var(--vz-border-color)", borderLeft: `4px solid var(--vz-${lvl.color})`, background: "var(--vz-tertiary-bg)" }}
                                        >
                                            <div style={{ minWidth: 0, flex: "1 1 320px" }}>
                                                <div className="d-flex align-items-center flex-wrap gap-2 mb-1">
                                                    <span className={`badge bg-${lvl.color}-subtle text-${lvl.color}`}>{lvl.label}</span>
                                                    <span className="fw-semibold text-body">{r.title}</span>
                                                </div>
                                                <p className="text-muted fs-13 mb-0">{r.why}</p>
                                            </div>
                                            <a
                                                href={r.action.url}
                                                {...(isExternal(r.action.url) ? { target: "_blank", rel: "noopener noreferrer" } : {})}
                                                className="btn btn-sm btn-soft-primary flex-shrink-0"
                                            >
                                                {r.action.label}
                                                {isExternal(r.action.url) && <i className="ri-external-link-line ms-1" />}
                                            </a>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        {notices.map((n) => (
                            <p key={n.code} className="text-muted fs-12 mb-0 mt-2">
                                <i className="ri-information-line me-1" />{n.message}
                            </p>
                        ))}
                    </>
                )}
            </CardBody>
        </Card>
    );
}
