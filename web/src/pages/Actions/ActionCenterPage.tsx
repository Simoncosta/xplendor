import { useEffect, useMemo, useState } from "react";
import { Container, Row, Col, Button, Spinner } from "reactstrap";
import { ToastContainer } from "react-toastify";

import { getCompanyDecisionsApi } from "../../helpers/laravel_helper";
import { getDecisionLabel } from "./components/DecisionBadge";
import CarDecisionCard from "./components/CarDecisionCard";
import { ActionCenterCarItem, CarDecisionResponse, DecisionType, GuardrailSeverity } from "./types";

const decisionOrder: Record<DecisionType, number> = {
    PARAR: 1,
    CORRIGIR: 2,
    MANTER: 3,
    ESCALAR: 4,
    NO_ACTIVE_CAMPAIGN: 5,
};

const summaryOrder: DecisionType[] = ["PARAR", "CORRIGIR", "MANTER", "ESCALAR", "NO_ACTIVE_CAMPAIGN"];

const guardrailSeverityOrder: Record<GuardrailSeverity, number> = {
    high: 3,
    medium: 2,
    low: 1,
};

const isActionDecision = (decision: string): decision is DecisionType =>
    ["NO_ACTIVE_CAMPAIGN", "ESCALAR", "MANTER", "CORRIGIR", "PARAR"].includes(decision);

const readCompanyId = () => {
    const authUser = sessionStorage.getItem("authUser");
    if (!authUser) return 0;

    return Number(JSON.parse(authUser).company_id || 0);
};

export default function ActionCenterPage() {
    document.title = "Ações | Xplendor";

    const [companyId, setCompanyId] = useState(0);
    const [items, setItems] = useState<ActionCenterCarItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        setCompanyId(readCompanyId());
    }, []);

    useEffect(() => {
        let active = true;

        const fetchAllDecisions = async () => {
            if (!companyId) {
                if (active) {
                    setItems([]);
                    setError("Não foi possível identificar a empresa ativa.");
                    setLoading(false);
                }
                return;
            }

            setLoading(true);
            setError(null);

            try {
                const res: any = await getCompanyDecisionsApi(companyId);
                const decisions = (res?.data ?? []) as CarDecisionResponse[];

                if (!active) return;

                setItems(
                    decisions
                        .filter((item) => isActionDecision(item.decision))
                        .map((item) => ({
                            ...item,
                            id: Number(item.car_id),
                            detailPath: `/cars/${item.car_id}/ficha`,
                        }))
                        .sort((a, b) => {
                            const orderA = decisionOrder[a.decision as DecisionType] ?? 99;
                            const orderB = decisionOrder[b.decision as DecisionType] ?? 99;

                            if (orderA !== orderB) return orderA - orderB;

                            const guardrailA = Math.max(
                                0,
                                ...(a.guardrails ?? []).map((guardrail) => guardrailSeverityOrder[guardrail.severity] ?? 0)
                            );
                            const guardrailB = Math.max(
                                0,
                                ...(b.guardrails ?? []).map((guardrail) => guardrailSeverityOrder[guardrail.severity] ?? 0)
                            );

                            if (guardrailA !== guardrailB) return guardrailB - guardrailA;
                            if ((a.guardrails?.length ?? 0) !== (b.guardrails?.length ?? 0)) {
                                return (b.guardrails?.length ?? 0) - (a.guardrails?.length ?? 0);
                            }

                            return b.confidence - a.confidence;
                        })
                );
            } catch (err: any) {
                if (!active) return;
                setError(typeof err === "string" ? err : "Não foi possível carregar o Action Center.");
                setItems([]);
            } finally {
                if (active) {
                    setLoading(false);
                }
            }
        };

        fetchAllDecisions();

        return () => {
            active = false;
        };
    }, [companyId, refreshKey]);

    const summary = useMemo(() => {
        return items.reduce<Record<DecisionType, number>>(
            (acc, item) => {
                acc[item.decision as DecisionType] += 1;
                return acc;
            },
            { NO_ACTIVE_CAMPAIGN: 0, PARAR: 0, CORRIGIR: 0, MANTER: 0, ESCALAR: 0 }
        );
    }, [items]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-4">
                    <Col>
                        <section
                            style={{
                                borderRadius: 24,
                                padding: "24px 24px 20px",
                                background: "linear-gradient(135deg, #fff7ed 0%, #ffffff 55%, #eff6ff 100%)",
                                border: "1px solid #e9ebec",
                            }}
                        >
                            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-4">
                                <div>
                                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                        Action Center
                                    </p>
                                    <h3 className="mb-2">O que fazer agora, por carro</h3>
                                    <p className="text-muted mb-0 fs-14">
                                        Recomendações operacionais do motor, sem gráficos e focadas na próxima ação.
                                    </p>
                                </div>
                                <Button color="light" className="border" onClick={() => setRefreshKey((value) => value + 1)}>
                                    Atualizar
                                </Button>
                            </div>

                            <div className="d-flex gap-2 flex-wrap">
                                {summaryOrder.map((decision) => (
                                    <span key={decision} className="badge bg-light text-dark px-3 py-2 fs-12">
                                        {getDecisionLabel(decision)}: {summary[decision]}
                                    </span>
                                ))}
                            </div>
                        </section>
                    </Col>
                </Row>

                {loading ? (
                    <Row>
                        <Col>
                            <div className="d-flex align-items-center justify-content-center gap-2 py-5 text-muted">
                                <Spinner size="sm" />
                                A carregar decisões reais dos carros...
                            </div>
                        </Col>
                    </Row>
                ) : error ? (
                    <Row>
                        <Col>
                            <div className="alert alert-warning mb-0">
                                {error}
                            </div>
                        </Col>
                    </Row>
                ) : items.length === 0 ? (
                    <Row>
                        <Col>
                            <div className="alert alert-light border mb-0">
                                Ainda não existem decisões acionáveis para mostrar. Corre o seeder demo ou aguarda sinais reais.
                            </div>
                        </Col>
                    </Row>
                ) : (
                    <Row className="g-4">
                        {items.map((item) => (
                            <Col xl={6} key={item.id}>
                                <CarDecisionCard item={item} />
                            </Col>
                        ))}
                    </Row>
                )}
            </Container>
        </div>
    );
}
