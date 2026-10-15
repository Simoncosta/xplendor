import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Alert, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import InfoTip from "Components/Common/InfoTip";
import Skeleton from "Components/Common/Skeleton";
import { createStockCount, getRealConsumption, getStockCounts, refreshCountableProducts } from "helpers/laravel_helper";
import { StockCountSummary } from "common/models/stockcount.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { fmtDate, fmtWhen } from "pages/Auditoria/auditFormat";
import { StockMoney, fmtStockQty } from "./stock/stockFormat";

/**
 * XPLENDOR — Restauração › Stock › Contagens (Fatia 7b). As contagens (contar, rever, enviar), uma
 * nova contagem (armazém, âmbito, momento, cega por omissão) e o consumo real × teórico de um armazém
 * quando há duas contagens enviadas e confirmadas.
 */

export const COUNT_STATUS: Record<StockCountSummary["status"], { label: string; color: string }> = {
    em_contagem: { label: "Em contagem", color: "info" },
    em_revisao: { label: "Em revisão", color: "warning" },
    a_enviar: { label: "A enviar", color: "secondary" },
    enviada: { label: "Enviada", color: "success" },
    falhou: { label: "Falhou", color: "danger" },
    anulada: { label: "Anulada", color: "secondary" },
};
const SCOPES = [{ value: "completa", label: "Completa" }, { value: "artigos_a", label: "Só artigos A" }, { value: "zona", label: "Uma zona" }];
const MOMENT_TIP = "O PingWin guarda só o dia da contagem. Antes da abertura, a contagem fica com a data de hoje. Depois do fecho, fica com a data de amanhã, porque conta o stock com que o dia seguinte começa.";

export default function StockCountsPage() {
    document.title = "Contagens | Stock | Xplendor";
    const navigate = useNavigate();
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<any | null>(null);
    const [tab, setTab] = useState<"contagens" | "consumo">("contagens");
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ warehouse_id: "", scope: "completa", zone_id: "", moment: "antes_abertura", blind: true });
    const [cw, setCw] = useState("");
    const [cons, setCons] = useState<any | null>(null);
    const [consLoading, setConsLoading] = useState(false);

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const res: any = await getStockCounts(companyId);
            setData(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar as contagens.");
        }
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    const create = async () => {
        if (!companyId) return;
        setBusy(true);
        try {
            const res: any = await createStockCount(companyId, { warehouse_id: form.warehouse_id, scope: form.scope, zone_id: form.zone_id ? Number(form.zone_id) : null, moment: form.moment, blind: form.blind });
            navigate(`/restauracao/stock/contagens/${res?.data?.count?.id}`);
        } catch (e: any) {
            toast.error(e?.response?.data?.message ?? e?.message ?? "Não foi possível criar a contagem.");
        } finally {
            setBusy(false);
        }
    };
    const refresh = async () => {
        if (!companyId) return;
        setBusy(true);
        try {
            await refreshCountableProducts(companyId);
            toast.info("A ler os artigos contáveis no PingWin. Atualize a página dentro de momentos.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível ler os artigos contáveis.");
        } finally {
            setBusy(false);
        }
    };
    const loadConsumption = async (wid: string, attempt = 0) => {
        setCw(wid);
        if (!companyId || !wid) return;
        setConsLoading(true);
        try {
            const res: any = await getRealConsumption(companyId, wid);
            // Os movimentos leem-se no worker: enquanto não estão prontos, pergunta de 2 em 2 segundos.
            if (res?.data?.pending && attempt < 90) { setTimeout(() => loadConsumption(wid, attempt + 1), 2000); return; }
            setCons(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível calcular o consumo real.");
        }
        setConsLoading(false);
    };

    const warehouses: { id: string; name: string }[] = data?.warehouses ?? [];
    const whOptions = warehouses.map((w) => ({ value: w.id, label: w.name }));
    const zones = (data?.zones ?? []).filter((z: any) => z.warehouse_id === form.warehouse_id);
    const hidden = cons?.financial?.visible === false;

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Contagens" breadcrumbs={[{ label: "Restauração" }, { label: "Stock", to: "/restauracao/stock" }]}
                    info="Contar o stock de um armazém no telemóvel, rever as diferenças e enviar a contagem para o PingWin." />
                <Nav tabs className="nav-tabs-custom mb-3">
                    <NavItem><NavLink className={tab === "contagens" ? "active" : ""} onClick={() => setTab("contagens")} style={{ cursor: "pointer" }}>Contagens</NavLink></NavItem>
                    <NavItem><NavLink className={tab === "consumo" ? "active" : ""} onClick={() => setTab("consumo")} style={{ cursor: "pointer" }} data-testid="tab-consumo">Consumo real × teórico</NavLink></NavItem>
                </Nav>
                {tab === "contagens" ? (
                    <PageCard
                        title="Contagens"
                        status={<>Artigos contáveis lidos do PingWin: {data?.countable_refreshed_at ? fmtWhen(data.countable_refreshed_at) : "ainda não lidos"}</>}
                        actions={<>
                            <Link to="/restauracao/stock/zonas" className="btn btn-sm btn-outline-primary"><i className="ri-layout-grid-line me-1" />Zonas</Link>
                            {data?.can_manage && <Button size="sm" color="outline-primary" onClick={refresh} disabled={busy}><i className="ri-refresh-line me-1" />Reler artigos contáveis</Button>}
                            <Button size="sm" color="primary" onClick={() => { setForm((f) => ({ ...f, warehouse_id: f.warehouse_id || warehouses[0]?.id || "" })); setOpen(true); }} data-testid="count-new">
                                <i className="ri-add-line me-1" />Nova contagem
                            </Button>
                        </>}
                    >
                        {!data ? <div className="p-3"><Skeleton variant="lines" lines={3} label="A carregar" /></div> : (
                            <div className="table-responsive">
                                <Table className="align-middle mb-0" data-testid="counts-table">
                                    <thead className="table-light"><tr><th>Data</th><th>Armazém</th><th>Âmbito</th><th>Estado</th><th className="text-end">Contados</th><th>Documento</th><th>Criada por</th><th /></tr></thead>
                                    <tbody>
                                        {data.counts.length === 0 && <tr><td colSpan={8} className="text-center text-muted py-4">Ainda não há contagens.</td></tr>}
                                        {data.counts.map((c: StockCountSummary) => (
                                            <tr key={c.id} style={{ cursor: "pointer" }} onClick={() => navigate(`/restauracao/stock/contagens/${c.id}`)}>
                                                <td className="text-nowrap">{fmtDate(c.inventory_date)}<div className="text-muted fs-12">{c.moment === "depois_fecho" ? "Depois do fecho" : "Antes da abertura"}</div></td>
                                                <td>{c.warehouse}</td>
                                                <td>{SCOPES.find((s) => s.value === c.scope)?.label}{c.blind && <span className="badge bg-light text-body ms-1">Cega</span>}</td>
                                                <td><span className={`badge bg-${COUNT_STATUS[c.status].color}-subtle text-${COUNT_STATUS[c.status].color}`}>{COUNT_STATUS[c.status].label}</span></td>
                                                <td className="text-end">{c.counted ?? 0} de {c.lines ?? 0}</td>
                                                <td>{c.pingwin_doc_number ?? <span className="text-muted">—</span>}</td>
                                                <td className="fs-13">{c.created_by}</td>
                                                <td className="text-end"><Button size="sm" color="outline-primary">{c.status === "em_contagem" ? "Contar" : "Abrir"}</Button></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            </div>
                        )}
                    </PageCard>
                ) : (
                    <PageCard title="Consumo real × teórico" info="Consumo real = Stock inicial + Entradas − Saídas por transferência − Stock final, entre as duas últimas contagens enviadas e confirmadas no armazém. Desvio = Consumo real − Consumo teórico − Desperdício registado.">
                        <div className="p-3">
                            <Alert color="warning" className="fs-13">Só é fiável com fichas técnicas corretas e transferências registadas no PingWin.</Alert>
                            <div style={{ maxWidth: 320 }} className="mb-3">
                                <XSelect ariaLabel="Armazém" options={whOptions} value={cw} onChange={(v) => loadConsumption(v)} searchable={false} placeholder="Escolha o armazém" />
                            </div>
                            {consLoading && <Skeleton variant="lines" lines={3} label="A ler os movimentos no PingWin" />}
                            {!consLoading && cw && cons && !cons.consumption && <p className="text-muted">Aparece quando houver duas contagens enviadas e confirmadas neste armazém.</p>}
                            {!consLoading && cons?.consumption && (
                                <>
                                    <p className="text-muted fs-13">De {fmtDate(cons.consumption.from)} a {fmtDate(cons.consumption.to)}: {cons.consumption.counts.map((c: any) => c.document).join(" e ")}.</p>
                                    <div className="table-responsive">
                                        <Table size="sm" className="align-middle" data-testid="real-consumption">
                                            <thead className="table-light"><tr><th>Artigo</th><th className="text-end">Inicial</th><th className="text-end">Entradas</th><th className="text-end">Transferências</th><th className="text-end">Final</th><th className="text-end">Real</th><th className="text-end">Teórico</th><th className="text-end">Desperdício</th><th className="text-end">Desvio</th><th className="text-end">Desvio (€)</th></tr></thead>
                                            <tbody>
                                                {cons.consumption.rows.map((r: any, i: number) => (
                                                    <tr key={r.product_id}>
                                                        <td><div className="fw-medium">{r.description}</div><div className="text-muted fs-12">{r.code} · {r.unit}</div>
                                                            {r.without_conversion.length > 0 && <div className="text-warning fs-12">Sem conversão confirmada para {r.without_conversion.join(", ")}: fora das contas.</div>}</td>
                                                        <td className="text-end">{fmtStockQty(r.initial)}</td><td className="text-end">{fmtStockQty(r.entries)}</td><td className="text-end">{fmtStockQty(r.transfers_out)}</td>
                                                        <td className="text-end">{fmtStockQty(r.final)}</td><td className="text-end">{fmtStockQty(r.real)}</td><td className="text-end">{fmtStockQty(r.theoretical)}</td>
                                                        <td className="text-end">{fmtStockQty(r.waste)}</td><td className="text-end fw-medium">{fmtStockQty(r.deviation)}</td>
                                                        <td className="text-end"><StockMoney value={r.deviation_eur} hidden={hidden} seed={i} /></td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </Table>
                                    </div>
                                </>
                            )}
                        </div>
                    </PageCard>
                )}

                <Modal isOpen={open} centered toggle={() => !busy && setOpen(false)} data-testid="count-new-modal">
                    <ModalHeader toggle={() => !busy && setOpen(false)}>Nova contagem</ModalHeader>
                    <ModalBody>
                        <Row className="g-3">
                            <Col xs={12}><Label className="form-label">Armazém</Label>
                                <XSelect ariaLabel="Armazém" options={whOptions} value={form.warehouse_id} onChange={(v) => setForm((f) => ({ ...f, warehouse_id: v, zone_id: "" }))} searchable={false} /></Col>
                            <Col xs={12}><Label className="form-label">Âmbito</Label>
                                <XSelect ariaLabel="Âmbito" options={SCOPES} value={form.scope} onChange={(v) => setForm((f) => ({ ...f, scope: v }))} searchable={false} /></Col>
                            {form.scope === "zona" && (
                                <Col xs={12}><Label className="form-label">Zona</Label>
                                    {zones.length ? <XSelect ariaLabel="Zona" options={zones.map((z: any) => ({ value: String(z.id), label: z.name }))} value={form.zone_id} onChange={(v) => setForm((f) => ({ ...f, zone_id: v }))} searchable={false} />
                                        : <p className="text-muted fs-13 mb-0">Este armazém ainda não tem zonas. <Link to="/restauracao/stock/zonas">Criar zonas</Link></p>}
                                </Col>
                            )}
                            <Col xs={12}><Label className="form-label">Momento <InfoTip label="Sobre o momento" text={MOMENT_TIP} /></Label>
                                <div className="d-flex flex-column gap-1">
                                    {[["antes_abertura", "Antes da abertura"], ["depois_fecho", "Depois do fecho"]].map(([v, l]) => (
                                        <div className="form-check" key={v}>
                                            <Input type="radio" className="form-check-input" id={`m-${v}`} checked={form.moment === v} onChange={() => setForm((f) => ({ ...f, moment: v }))} />
                                            <Label className="form-check-label" for={`m-${v}`}>{l} <span className="text-muted fs-12">(fica com a data de {fmtDate(data?.dates?.[v])})</span></Label>
                                        </div>
                                    ))}
                                </div>
                            </Col>
                            <Col xs={12}>
                                <div className="form-check">
                                    <Input type="checkbox" className="form-check-input" id="c-blind" checked={form.blind} onChange={(e) => setForm((f) => ({ ...f, blind: e.target.checked }))} disabled={!data?.can_manage} />
                                    <Label className="form-check-label" for="c-blind">Contagem cega (quem conta não vê o stock teórico)</Label>
                                </div>
                                {!data?.can_manage && <div className="text-muted fs-12">Só o gestor pode desligar a contagem cega.</div>}
                            </Col>
                        </Row>
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setOpen(false)} disabled={busy}>Cancelar</Button>
                        <Button color="primary" onClick={create} disabled={busy || !form.warehouse_id || (form.scope === "zona" && !form.zone_id)} data-testid="count-create">
                            {busy ? <Spinner size="sm" /> : "Começar a contar"}
                        </Button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}
