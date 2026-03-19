import { useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { Link, useParams } from "react-router-dom";
import { Card, CardBody, Col, Container, Row } from "reactstrap";
import { createSelector } from "reselect";
import { toast, ToastContainer } from "react-toastify";
import { generateCarMarketing, getCarMarketing } from "slices/thunks";
import TabMarketing from "./components/TabMarketing";

const ipsClassBadge = (cls: string) =>
    cls === "hot" ? "bg-success-subtle text-success" : cls === "warm" ? "bg-warning-subtle text-warning" : "bg-danger-subtle text-danger";

const selectCarState = (state: any) => state.Car;

const selectCarMarketingViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        carMarketing: carState.data.carMarketing,
        loading: carState.loading.marketing || carState.loading.generate,
    })
);

export default function CarMarketing() {
    document.title = "Marketing | Xplendor";

    const dispatch: any = useDispatch();
    const { id } = useParams();
    const [companyId, setCompanyId] = useState<number>(0);

    const { carMarketing, loading } = useSelector(selectCarMarketingViewModel);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const obj = JSON.parse(authUser);
        setCompanyId(Number(obj.company_id));
        dispatch(getCarMarketing({ companyId: obj.company_id, id: Number(id) }));
    }, [dispatch, id]);

    if (loading || !carMarketing) return null;

    const ips = carMarketing.ips;
    const ideas = carMarketing.marketing_ideas ?? [];
    const weekLabel = new Date(carMarketing?.created_at)?.toLocaleDateString("pt-PT", {
        month: "long", day: "numeric", year: "numeric",
    });

    const handleGenerateIdeas = async () => {
        if (!companyId || !id) return;

        try {
            await dispatch(generateCarMarketing({ companyId, carId: Number(id) })).unwrap();
            toast.success("Geração de ideias enviada para processamento.");
            dispatch(getCarMarketing({ companyId, id: Number(id) }));
        } catch (error: any) {
            toast.error(error?.message ?? error ?? "Erro ao gerar ideias.");
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>

                {/* ── Header ──────────────────────────────────────────── */}
                <Row className="mb-3">
                    <Col>
                        <Card className="mb-0">
                            <CardBody className="py-3">
                                <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <div>
                                        <p className="text-muted text-uppercase fw-semibold mb-1" style={{ fontSize: 11, letterSpacing: ".05em" }}>
                                            Semana de {weekLabel} — Briefing de Conteúdo
                                        </p>
                                        <h4 className="mb-2 fw-semibold">
                                            {carMarketing?.brand?.name} {carMarketing?.model?.name}
                                            {carMarketing?.version && (
                                                <span className="badge bg-primary-subtle text-primary ms-2 fw-medium" style={{ fontSize: "12px", verticalAlign: "middle" }}>
                                                    {carMarketing.version}
                                                </span>
                                            )}
                                        </h4>

                                        {/* Métricas de performance */}
                                        <div className="hstack gap-2 flex-wrap">
                                            {[
                                                { icon: "ri-eye-line", label: "Views", value: carMarketing.views_count ?? 0, color: "primary" },
                                                { icon: "ri-user-follow-line", label: "Leads", value: carMarketing.leads_count ?? 0, color: carMarketing.leads_count > 0 ? "success" : "secondary" },
                                                { icon: "ri-time-line", label: "dias em stock", value: carMarketing.days_in_stock ?? 0, color: "secondary" },
                                            ].map((chip, idx) => (
                                                <span
                                                    key={idx}
                                                    className="d-inline-flex align-items-center gap-1 badge bg-light text-body rounded-pill px-3 py-2 fs-12"
                                                >
                                                    <i className={`${chip.icon} text-${chip.color} fs-13`} />
                                                    <strong>{chip.value}</strong>
                                                    <span className="text-muted">{chip.label}</span>
                                                </span>
                                            ))}
                                            {ips && (
                                                <span className={`badge ${ipsClassBadge(ips.classification)} rounded-pill px-3 py-2 fs-12`}>
                                                    <i className="ri-award-line me-1" />
                                                    IPS {ips.score}/100
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div className="d-flex gap-2">
                                        <Link to={`/cars/${carMarketing?.id}/analytics`} className="btn btn-soft-secondary btn-sm">
                                            <i className="ri-brain-line me-1" /> Análises
                                        </Link>
                                        <Link to={`/cars/${carMarketing?.id}`} className="btn btn-soft-primary btn-sm">
                                            <i className="ri-pencil-fill me-1" /> Editar viatura
                                        </Link>
                                    </div>
                                </div>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>

                {ideas.length > 0 ? (
                    <TabMarketing ideas={ideas} />
                ) : (
                    <Row>
                        <Col>
                            <Card className="mb-0">
                                <CardBody className="py-5 text-center">
                                    <i className="ri-lightbulb-flash-line fs-1 text-muted d-block mb-3" />
                                    <h5 className="mb-2">Ainda não existem ideias para esta viatura</h5>
                                    <p className="text-muted mb-4">
                                        Gera ideias de marketing para este carro sem alterar o fluxo semanal da empresa.
                                    </p>
                                    <button
                                        className="btn btn-primary"
                                        onClick={handleGenerateIdeas}
                                        disabled={loading}
                                    >
                                        <i className="ri-magic-line me-1" /> Gerar ideias
                                    </button>
                                </CardBody>
                            </Card>
                        </Col>
                    </Row>
                )}
            </Container>
        </div>
    );
}
