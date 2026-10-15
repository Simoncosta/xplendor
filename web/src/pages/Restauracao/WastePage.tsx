import { useCallback, useEffect, useMemo, useState } from "react";
import { Alert, Button, Col, Container, Input, Label, ListGroup, ListGroupItem, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { deleteWaste, getWaste, getWasteProducts, getWasteReport, saveWaste, sendWaste, voidWasteBatch } from "helpers/laravel_helper";
import { WasteRow } from "common/models/stockcount.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { fmtDate, fmtWhen } from "pages/Auditoria/auditFormat";
import { StockMoney, WRITE_STATUS, fmtStockQty, useStockWrite, writeMessage } from "./stock/stockFormat";

/**
 * XPLENDOR — Restauração › Stock › Desperdício (Fatia 7b). Registar no telemóvel (artigo contável ou
 * prato com ficha, quantidade e unidade, motivo, nota), editar e apagar enquanto não se envia; o gestor
 * envia um documento por armazém para o PingWin e pode anulá-lo; o relatório por motivo, artigo e semana.
 */
const WH_KEY = "xp-waste-warehouse";
type Product = { product_id: string; code: string | null; description: string; is_dish: boolean; units: { unit: string; factor: number }[] };
const EMPTY = { product: null as Product | null, qty: "", unit: "", reason: "", note: "" };

export default function WastePage() {
    document.title = "Desperdício | Stock | Xplendor";
    const companyId = useWorkingCompanyId();
    const [tab, setTab] = useState<"registar" | "relatorio">("registar");
    const [wid, setWid] = useState<string>(() => { try { return localStorage.getItem(WH_KEY) ?? ""; } catch { return ""; } });
    const [data, setData] = useState<any | null>(null);
    const [form, setForm] = useState({ ...EMPTY });
    const [editing, setEditing] = useState<number | null>(null);
    const [search, setSearch] = useState("");
    const [found, setFound] = useState<Product[]>([]);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<{ kind: "send" } | { kind: "void"; id: number; document: string | null } | null>(null);
    const [report, setReport] = useState<any | null>(null);

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const res: any = await getWaste(companyId, { warehouse_id: wid || undefined });
            setData(res?.data ?? null);
            if (!wid) {
                // Por omissão: o armazém da primeira loja de vendas (depois, o último que se usou neste aparelho).
                const first = (res?.data?.store_warehouses ?? []).find((s: any) => s.warehouse_id)?.warehouse_id ?? res?.data?.warehouses?.[0]?.id ?? "";
                if (first) setWid(first);
            }
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar o desperdício.");
        }
    }, [companyId, wid]);
    useEffect(() => { load(); }, [load]);
    useEffect(() => { try { if (wid) localStorage.setItem(WH_KEY, wid); } catch { /* sem armazenamento */ } }, [wid]);
    useEffect(() => {
        if (!companyId || search.trim().length < 2 || form.product) { setFound([]); return; }
        const t = setTimeout(() => getWasteProducts(companyId, search.trim()).then((r: any) => setFound(r?.data?.products ?? [])).catch(() => null), 300);
        return () => clearTimeout(t);
    }, [companyId, search, form.product]);
    useEffect(() => {
        if (tab !== "relatorio" || !companyId) return;
        getWasteReport(companyId, { warehouse_id: wid || undefined }).then((r: any) => setReport(r?.data ?? null)).catch(() => toast.error("Não foi possível carregar o relatório."));
    }, [tab, companyId, wid]);
    const { write, setWrite, busy: writing } = useStockWrite(companyId, (w) => { if (w.status === "ok") toast.success(writeMessage(w)); else toast.error(writeMessage(w)); load(); });

    const hidden = data?.financial?.visible === false;
    const entries: WasteRow[] = data?.entries ?? [];
    const pending = entries.filter((e) => !e.sent);
    const today = entries.filter((e) => e.sent);
    const reasons: { id: string; label: string }[] = data?.reasons ?? [];
    const unitOptions = useMemo(() => (form.product?.units ?? []).map((u) => ({ value: u.unit, label: u.unit })), [form.product]);
    const factor = form.product?.units.find((u) => u.unit === form.unit)?.factor ?? 1;
    const qtyNum = Number(String(form.qty).replace(",", "."));

    const submit = async () => {
        if (!companyId || !form.product) return;
        setBusy(true);
        try {
            await saveWaste(companyId, { warehouse_id: wid, product_id: form.product.product_id, qty: String(form.qty).replace(",", "."), unit: form.unit, reason: form.reason, note: form.note || undefined }, editing ?? undefined);
            toast.success(editing ? "Registo alterado." : "Desperdício registado.");
            setForm({ ...EMPTY }); setSearch(""); setEditing(null);
            load();
        } catch (e: any) {
            toast.error(e?.response?.data?.message ?? e?.message ?? "Não foi possível gravar.");
        } finally {
            setBusy(false);
        }
    };
    const edit = (e: WasteRow) => {
        setEditing(e.id);
        setForm({ product: { product_id: e.product_id, code: e.code, description: e.description, is_dish: e.is_dish, units: [{ unit: e.unit ?? "Unidade", factor: 1 }] },
            qty: String(e.qty).replace(".", ","), unit: e.unit ?? "Unidade", reason: e.reason, note: e.note ?? "" });
        window.scrollTo({ top: 0, behavior: "smooth" });
    };
    const remove = async (e: WasteRow) => {
        if (!companyId || !window.confirm(`Apagar o registo de ${e.description}?`)) return;
        try { await deleteWaste(companyId, e.id); toast.success("Registo apagado."); load(); } catch (x: any) { toast.error(x?.message ?? "Não foi possível apagar."); }
    };
    const doConfirm = async () => {
        if (!companyId || !confirm) return;
        setBusy(true);
        try {
            const res: any = confirm.kind === "send" ? await sendWaste(companyId, wid) : await voidWasteBatch(companyId, confirm.id);
            setWrite(res?.data?.write ?? null);
        } catch (e: any) {
            toast.error(e?.response?.data?.message ?? e?.message ?? "Não foi possível.");
        } finally {
            setBusy(false);
            setConfirm(null);
        }
    };
    const whName = (data?.warehouses ?? []).find((w: any) => w.id === wid)?.name ?? "";
    const valid = form.product && Number.isFinite(qtyNum) && qtyNum > 0 && form.reason && (form.reason !== "outro" || form.note.trim());

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Desperdício" breadcrumbs={[{ label: "Restauração" }, { label: "Stock", to: "/restauracao/stock" }]}
                    info="Registar o que se estragou, partiu ou devolveu. O gestor envia para o PingWin um documento de Desperdício por armazém." />
                <div className="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <div style={{ minWidth: 240 }}><XSelect ariaLabel="Armazém" options={(data?.warehouses ?? []).map((w: any) => ({ value: w.id, label: w.name }))} value={wid} onChange={setWid} searchable={false} /></div>
                    <Nav pills className="ms-md-3">
                        <NavItem><NavLink className={tab === "registar" ? "active" : ""} onClick={() => setTab("registar")} style={{ cursor: "pointer" }}>Registar</NavLink></NavItem>
                        <NavItem><NavLink className={tab === "relatorio" ? "active" : ""} onClick={() => setTab("relatorio")} style={{ cursor: "pointer" }}>Relatório</NavLink></NavItem>
                    </Nav>
                </div>
                {hidden && <FinancialNote className="mb-2" />}
                {write && (writing || write.status !== "ok") && <Alert color={WRITE_STATUS[write.status].color} className="fs-13">{writing && <Spinner size="sm" className="me-2" />}{WRITE_STATUS[write.status].label}{!writing && write.error ? `: ${write.error}` : ""}</Alert>}

                {tab === "registar" ? (
                    <Row className="g-3">
                        <Col lg={5}>
                            <PageCard title={editing ? "Alterar registo" : "Novo registo"}>
                                <div className="p-3" data-testid="waste-form">
                                    <Label className="form-label">Artigo</Label>
                                    {form.product ? (
                                        <div className="d-flex align-items-center gap-2 mb-3">
                                            <div className="flex-grow-1"><strong>{form.product.description}</strong> <span className="text-muted fs-12">{form.product.code}</span>
                                                {form.product.is_dish && <div className="text-muted fs-12">Prato com ficha: ao enviar, entra no PingWin pelos ingredientes da ficha em vigor.</div>}</div>
                                            {!editing && <Button size="sm" color="link" onClick={() => { setForm({ ...EMPTY }); setSearch(""); }}>Mudar</Button>}
                                        </div>
                                    ) : (
                                        <div className="mb-3">
                                            <Input type="search" placeholder="Pesquisar artigo ou prato…" value={search} onChange={(e) => setSearch(e.target.value)} style={{ minHeight: 44 }} />
                                            {found.length > 0 && (
                                                <ListGroup className="mt-1">
                                                    {found.map((p) => (
                                                        <ListGroupItem key={p.product_id} action tag="button" onClick={() => setForm((f) => ({ ...f, product: p, unit: p.units[0]?.unit ?? "Unidade" }))}>
                                                            {p.description} <span className="text-muted fs-12">{p.code}</span>{p.is_dish && <span className="badge bg-info-subtle text-info ms-1">Prato</span>}
                                                        </ListGroupItem>
                                                    ))}
                                                </ListGroup>
                                            )}
                                        </div>
                                    )}
                                    <Row className="g-2 mb-3">
                                        <Col xs={6}><Label className="form-label">Quantidade</Label>
                                            <Input inputMode="decimal" value={form.qty} onChange={(e) => setForm((f) => ({ ...f, qty: e.target.value }))} style={{ minHeight: 44, fontSize: 18 }} /></Col>
                                        <Col xs={6}><Label className="form-label">Unidade</Label>
                                            <XSelect ariaLabel="Unidade" options={unitOptions} value={form.unit} onChange={(v) => setForm((f) => ({ ...f, unit: v }))} searchable={false} disabled={!form.product} /></Col>
                                    </Row>
                                    <Label className="form-label">Motivo</Label>
                                    <div className="d-flex flex-wrap gap-1 mb-3">
                                        {reasons.map((r) => (
                                            <Button key={r.id} size="sm" color={form.reason === r.id ? "primary" : "light"} onClick={() => setForm((f) => ({ ...f, reason: r.id }))} style={{ minHeight: 36 }}>{r.label}</Button>
                                        ))}
                                    </div>
                                    <Label className="form-label">{form.reason === "outro" ? "O que aconteceu (obrigatório)" : "Nota (opcional)"}</Label>
                                    <Input value={form.note} maxLength={255} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} className="mb-3" />
                                    {form.product && Number.isFinite(qtyNum) && qtyNum > 0 && factor !== 1 && <p className="text-muted fs-12">= {fmtStockQty(qtyNum * factor)} na unidade de stock</p>}
                                    <div className="d-flex gap-2 justify-content-end">
                                        {editing && <Button color="light" onClick={() => { setEditing(null); setForm({ ...EMPTY }); }}>Cancelar</Button>}
                                        <Button color="primary" disabled={!valid || busy} onClick={submit} style={{ minHeight: 44 }} data-testid="waste-save">{busy ? <Spinner size="sm" /> : editing ? "Guardar" : "Registar"}</Button>
                                    </div>
                                </div>
                            </PageCard>
                        </Col>
                        <Col lg={7}>
                            <PageCard title="Por enviar" status={<>{pending.length} registos · ≈ <StockMoney value={data?.pending?.value_eur} hidden={hidden} seed={1} /></>}
                                actions={data?.can_manage && <Button size="sm" color="primary" disabled={!pending.length || writing || !!data?.send_guard} title={data?.send_guard?.reason} onClick={() => setConfirm({ kind: "send" })} data-testid="waste-send">Enviar para o PingWin</Button>}>
                                {data?.send_guard && pending.length > 0 && <Alert color="warning" className="m-3 fs-13"><i className="ri-lock-line me-1" />{data.send_guard.reason}</Alert>}
                                <WasteTable rows={pending} hidden={hidden} onEdit={edit} onDelete={remove} />
                            </PageCard>
                            <PageCard title={`Enviados hoje (${fmtDate(data?.date)})`}><WasteTable rows={today} hidden={hidden} /></PageCard>
                            <PageCard title="Documentos enviados">
                                <Table size="sm" className="mb-0 align-middle">
                                    <tbody>
                                        {(data?.batches ?? []).map((b: any, i: number) => (
                                            <tr key={b.id}>
                                                <td>{fmtDate(b.date)}</td><td>{b.document ?? "—"}</td><td>{b.lines} linhas</td>
                                                <td><StockMoney value={b.total_eur} hidden={hidden} seed={i} /></td>
                                                <td><span className="badge bg-light text-body">{({ a_enviar: "A enviar", enviada: "Enviado", falhou: "Falhou", anulada: "Anulado" } as any)[b.status] ?? b.status}</span>{b.error && <div className="text-danger fs-12">{b.error}</div>}</td>
                                                <td className="text-end">{data?.can_manage && b.status === "enviada" && <Button size="sm" color="outline-danger" onClick={() => setConfirm({ kind: "void", id: b.id, document: b.document })}>Anular no PingWin</Button>}</td>
                                            </tr>
                                        ))}
                                        {!(data?.batches ?? []).length && <tr><td className="text-muted">Ainda não há documentos.</td></tr>}
                                    </tbody>
                                </Table>
                            </PageCard>
                        </Col>
                    </Row>
                ) : (
                    <PageCard title="Relatório do desperdício" status={report?.report ? <>De {fmtDate(report.report.from)} a {fmtDate(report.report.to)} · {report.report.entries} registos · <StockMoney value={report.report.total_eur} hidden={hidden} seed={2} /></> : null}>
                        {report?.report && (
                            <Row className="g-3 p-3" data-testid="waste-report">
                                {[["Por motivo", "by_reason"], ["Por artigo", "by_product"], ["Por semana", "by_week"]].map(([title, key]) => (
                                    <Col lg={4} key={key}>
                                        <h6 className="fs-13 text-uppercase text-muted">{title}</h6>
                                        <Table size="sm"><tbody>
                                            {report.report[key].slice(0, 15).map((r: any, i: number) => (
                                                <tr key={r.key}><td>{key === "by_week" ? `Semana de ${fmtDate(r.label)}` : r.label}</td><td className="text-end text-nowrap"><StockMoney value={r.value_eur} hidden={hidden} seed={i} /></td></tr>
                                            ))}
                                        </tbody></Table>
                                    </Col>
                                ))}
                            </Row>
                        )}
                    </PageCard>
                )}

                <Modal isOpen={confirm !== null} centered toggle={() => !busy && setConfirm(null)}>
                    <ModalHeader toggle={() => !busy && setConfirm(null)}>{confirm?.kind === "send" ? "Enviar o desperdício para o PingWin" : "Anular no PingWin"}</ModalHeader>
                    <ModalBody className="fs-14">
                        {confirm?.kind === "send" ? (
                            <p className="mb-0">Vai gravar no PingWin um documento de Desperdício Fechado no <strong>{whName}</strong>, com {pending.length} registos (≈ <StockMoney value={data?.pending?.value_eur} hidden={hidden} seed={3} />). Os pratos entram pelos ingredientes da ficha em vigor.</p>
                        ) : (
                            <p className="mb-0">Vai anular o documento <strong>{confirm?.kind === "void" ? confirm.document : ""}</strong> no PingWin. O stock volta ao que era.</p>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setConfirm(null)} disabled={busy}>Cancelar</Button>
                        <Button color={confirm?.kind === "send" ? "primary" : "danger"} onClick={doConfirm} disabled={busy}>{busy ? <Spinner size="sm" /> : confirm?.kind === "send" ? "Enviar para o PingWin" : "Anular no PingWin"}</Button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}

function WasteTable({ rows, hidden, onEdit, onDelete }: { rows: WasteRow[]; hidden: boolean; onEdit?: (e: WasteRow) => void; onDelete?: (e: WasteRow) => void }) {
    if (!rows.length) return <p className="text-muted p-3 mb-0">Nada.</p>;
    return (
        <div className="table-responsive">
            <Table size="sm" className="align-middle mb-0" data-testid="waste-table">
                <tbody>
                    {rows.map((e, i) => (
                        <tr key={e.id}>
                            <td>
                                <div className="fw-medium">{e.description}{e.is_dish && <span className="badge bg-info-subtle text-info ms-1">Prato</span>}</div>
                                <div className="text-muted fs-12">{fmtStockQty(e.qty)} {e.unit} · {e.reason_label}{e.note ? ` · ${e.note}` : ""} · {e.registered_by} · {fmtWhen(e.registered_at)}</div>
                                {e.explosion && e.explosion.length > 0 && (
                                    <div className="fs-12 text-muted">Vai como: {e.explosion.map((x) => `${fmtStockQty(x.qty)} ${x.description}`).join(", ")}</div>
                                )}
                                {e.is_dish && e.explosion && e.explosion.length === 0 && <div className="fs-12 text-warning">Sem ficha em vigor: não tem ingredientes para enviar.</div>}
                            </td>
                            <td className="text-end text-nowrap">≈ <StockMoney value={e.value_eur} hidden={hidden} seed={i} /></td>
                            {(onEdit || onDelete) && (
                                <td className="text-end text-nowrap">
                                    {onEdit && <Button size="sm" color="link" onClick={() => onEdit(e)} aria-label="Alterar"><i className="ri-pencil-line" /></Button>}
                                    {onDelete && <Button size="sm" color="link" className="text-danger" onClick={() => onDelete(e)} aria-label="Apagar"><i className="ri-delete-bin-line" /></Button>}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </Table>
        </div>
    );
}
