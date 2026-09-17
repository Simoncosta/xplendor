import React, { useEffect, useMemo, useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner, Badge } from "reactstrap";
import BreadCrumb from "Components/Common/BreadCrumb";
import { getCustomerHub } from "helpers/laravel_helper";
import { LEAD_STATUS_META, LeadStatus } from "common/models/lead.model";

/**
 * XPLENDOR — Fase 3: ficha-HUB do cliente. Junta num só sítio tudo o que se liga
 * a ele: vendas (customer_id), leads (match de contacto — Fase 2), documentos
 * (não guardados → "sem registos") e um histórico derivado. Scoped à empresa.
 */

interface HubSale {
    id: number; car_id: number; car: string; sold_at: string | null;
    sale_price: number | null; advertised_price: number | null; discount_amount: number | null;
    has_trade_in: boolean | null; trade_in_value: number | null;
    has_financing: boolean | null; financed_amount: number | null; first_motorhome: boolean | null;
}
interface HubLead {
    id: number; name: string; status: LeadStatus; channel: string | null;
    utm_source: string | null; utm_campaign: string | null; car_id: number | null; created_at: string | null;
}
interface HubHistory { type: "sale" | "lead"; date: string | null; title: string; amount?: number | null; status?: string }
interface HubData {
    customer: { id: number; name: string; email: string | null; phone: string | null; nif: string | null };
    sales: HubSale[]; leads: HubLead[]; documents: unknown[]; history: HubHistory[];
}

const eur = (v: number | null | undefined) =>
    v == null ? "—" : new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(v);
const fmtDate = (s: string | null | undefined) => (s ? new Date(s).toLocaleDateString("pt-PT") : "—");

const EmptyBlock: React.FC<{ icon: string; text: string }> = ({ icon, text }) => (
    <div className="text-center text-muted py-4">
        <i className={icon + " fs-24 d-block mb-2"} />
        <span className="fs-13">{text}</span>
    </div>
);

const CustomerHub = () => {
    document.title = "Ficha do cliente | Xplendor";
    const { id } = useParams();
    const navigate = useNavigate();
    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [data, setData] = useState<HubData | null>(null);
    const [loading, setLoading] = useState(true);
    const [notFound, setNotFound] = useState(false);

    useEffect(() => {
        if (!companyId || !id) { setLoading(false); setNotFound(true); return; }
        let alive = true;
        setLoading(true); setNotFound(false);
        getCustomerHub(companyId, Number(id))
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setNotFound(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, id]);

    if (loading) return <div className="page-content"><div className="d-flex justify-content-center py-5"><Spinner color="primary" /></div></div>;
    if (notFound || !data) {
        return (
            <div className="page-content"><Container fluid>
                <BreadCrumb title="Ficha do cliente" pageTitle="Clientes" pageLink="/customers" />
                <Card><CardBody>
                    <p className="text-muted mb-2">Cliente não encontrado.</p>
                    <button className="btn btn-primary" onClick={() => navigate("/customers")}>Voltar aos clientes</button>
                </CardBody></Card>
            </Container></div>
        );
    }

    const c = data.customer;
    const leadBadge = (s: LeadStatus) => LEAD_STATUS_META[s] ?? { label: s, color: "secondary" };

    return (
        <div className="page-content">
            <Container fluid>
                <BreadCrumb title="Ficha do cliente" pageTitle="Clientes" pageLink="/customers" />

                {/* Cabeçalho do cliente */}
                <Card>
                    <CardBody className="d-flex align-items-center gap-3">
                        <span className="avatar-md flex-shrink-0"><span className="avatar-title bg-primary-subtle text-primary rounded fs-24">{(c.name?.[0] ?? "?").toUpperCase()}</span></span>
                        <div className="flex-grow-1">
                            <h4 className="mb-1">{c.name}</h4>
                            <div className="text-muted fs-13">
                                {[c.phone, c.email, c.nif ? `NIF ${c.nif}` : null].filter(Boolean).join(" · ") || "—"}
                            </div>
                        </div>
                        <div className="d-flex gap-3 text-center">
                            <div><div className="fs-20 fw-semibold">{data.sales.length}</div><small className="text-muted">Vendas</small></div>
                            <div><div className="fs-20 fw-semibold">{data.leads.length}</div><small className="text-muted">Leads</small></div>
                        </div>
                    </CardBody>
                </Card>

                <Row className="g-3">
                    {/* VENDAS */}
                    <Col xl={7}>
                        <Card className="h-100 mb-0"><CardBody>
                            <h6 className="mb-3 text-uppercase"><i className="ri-car-line me-1" />Vendas</h6>
                            {data.sales.length === 0 ? (
                                <EmptyBlock icon="ri-car-line" text="Sem vendas registadas." />
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle mb-0">
                                        <thead className="text-muted"><tr><th>Viatura</th><th>Data</th><th className="text-end">Anunciado</th><th className="text-end">Final</th><th>Extras</th></tr></thead>
                                        <tbody>
                                            {data.sales.map((s) => (
                                                <tr key={s.id} style={{ cursor: "pointer" }} onClick={() => navigate(`/cars/${s.car_id}`)}>
                                                    <td className="fw-medium">{s.car}</td>
                                                    <td>{fmtDate(s.sold_at)}</td>
                                                    <td className="text-end">{eur(s.advertised_price)}</td>
                                                    <td className="text-end fw-semibold">{eur(s.sale_price)}</td>
                                                    <td>
                                                        {s.has_trade_in && <Badge color="info" className="me-1">Retoma {s.trade_in_value != null ? eur(s.trade_in_value) : ""}</Badge>}
                                                        {s.has_financing && <Badge color="warning" className="me-1">Financiado</Badge>}
                                                        {s.first_motorhome && <Badge color="success">1ª autocaravana</Badge>}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardBody></Card>
                    </Col>

                    {/* LEADS (match por contacto) */}
                    <Col xl={5}>
                        <Card className="h-100 mb-0"><CardBody>
                            <h6 className="mb-3 text-uppercase"><i className="ri-user-search-line me-1" />Leads</h6>
                            {data.leads.length === 0 ? (
                                <EmptyBlock icon="ri-user-search-line" text="Sem leads associadas (por contacto)." />
                            ) : (
                                <ul className="list-unstyled vstack gap-2 mb-0">
                                    {data.leads.map((l) => (
                                        <li key={l.id} className="d-flex align-items-center gap-2 border rounded p-2">
                                            <div className="flex-grow-1 min-w-0">
                                                <div className="fw-medium text-truncate">{l.name}</div>
                                                <small className="text-muted">{[l.channel, l.utm_source].filter(Boolean).join(" · ") || "origem —"} · {fmtDate(l.created_at)}</small>
                                            </div>
                                            <Badge color={leadBadge(l.status).color} className="flex-shrink-0">{leadBadge(l.status).label}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="mt-2"><Link to="/leads" className="text-muted fs-12"><i className="ri-arrow-right-line me-1" />Ver funil</Link></div>
                        </CardBody></Card>
                    </Col>

                    {/* DOCUMENTOS */}
                    <Col xl={6}>
                        <Card className="h-100 mb-0"><CardBody>
                            <h6 className="mb-3 text-uppercase"><i className="ri-file-text-line me-1" />Documentos</h6>
                            <EmptyBlock icon="ri-file-text-line" text="Sem documentos guardados. Os documentos são gerados na ficha da viatura (modelo + venda)." />
                        </CardBody></Card>
                    </Col>

                    {/* HISTÓRICO (derivado) */}
                    <Col xl={6}>
                        <Card className="h-100 mb-0"><CardBody>
                            <h6 className="mb-3 text-uppercase"><i className="ri-history-line me-1" />Histórico</h6>
                            {data.history.length === 0 ? (
                                <EmptyBlock icon="ri-history-line" text="Sem registos." />
                            ) : (
                                <ul className="list-unstyled vstack gap-3 mb-0">
                                    {data.history.map((h, i) => (
                                        <li key={i} className="d-flex align-items-start gap-2">
                                            <span className="avatar-xs flex-shrink-0"><span className={`avatar-title rounded-circle bg-${h.type === "sale" ? "success" : "info"}-subtle text-${h.type === "sale" ? "success" : "info"}`}><i className={h.type === "sale" ? "ri-money-euro-circle-line" : "ri-user-search-line"} /></span></span>
                                            <div className="flex-grow-1">
                                                <div className="fs-13">{h.title}{h.amount != null ? ` · ${eur(h.amount)}` : ""}{h.status ? ` · ${leadBadge(h.status as LeadStatus).label}` : ""}</div>
                                                <small className="text-muted">{fmtDate(h.date)}</small>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardBody></Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default CustomerHub;
