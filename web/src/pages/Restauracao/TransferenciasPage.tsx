import { useCallback, useEffect, useState } from "react";
import { Alert, Badge, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import { useSearchParams } from "react-router-dom";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import { FinancialNote } from "Components/Common/HiddenMoney";
import AuditButton from "pages/Auditoria/AuditButton";
import { getStockTransfer, getStockTransfers, receiveStockTransfer, refreshTransferOrders, updateTransferRoute, voidStockTransfer } from "helpers/laravel_helper";
import { TransferRequest, TransferRoute, TransferSummary } from "common/models/transfer.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { fmtDate, fmtWhen } from "pages/Auditoria/auditFormat";
import { StockMoney, fmtStockQty } from "./stock/stockFormat";
import SendTransferModal from "./transfers/SendTransferModal";
import HowTransfersWork, { HELP_KEY } from "./transfers/HowTransfersWork";
import { CircuitMap, TransferPath } from "./transfers/TransferCircuit";
import { TRANSFER_STATUS } from "./transfers/transferFormat";

/**
 * XPLENDOR — Restauração › Transferências (Fatia 7c). Pedidos por enviar (as encomendas dos restaurantes
 * lidas do PingWin), Envios (cada Guia e Entrada, com o caminho Pedido → Envio → Receção) e Circuito
 * (o mapa e a configuração: documento de saída, moradas de carga e descarga, receção automática).
 */
type Tab = "pedidos" | "envios" | "circuito";
const TABS: { id: Tab; label: string }[] = [{ id: "pedidos", label: "Pedidos por enviar" }, { id: "envios", label: "Envios" }, { id: "circuito", label: "Circuito" }];

export default function TransferenciasPage() {
    document.title = "Transferências | Restauração | Xplendor";
    const companyId = useWorkingCompanyId();
    const [params, setParams] = useSearchParams();
    const tab = (TABS.find((t) => t.id === params.get("tab"))?.id ?? "pedidos") as Tab;
    const [data, setData] = useState<any | null>(null);
    const [send, setSend] = useState<{ request: TransferRequest | null } | null>(null);
    const [detail, setDetail] = useState<{ transfer: TransferSummary; writes: any[] } | null>(null);
    const [help, setHelp] = useState(() => { try { return !localStorage.getItem(HELP_KEY); } catch { return false; } });
    const [busy, setBusy] = useState(false);
    const [auditKey, setAuditKey] = useState(0);

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const res: any = await getStockTransfers(companyId);
            setData(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar as transferências.");
        }
    }, [companyId]);
    useEffect(() => { load(); }, [load, auditKey]);

    const transfers: TransferSummary[] = data?.transfers ?? [];
    const syncing = ["queued", "running"].includes(data?.orders_sync?.status);
    const sending = transfers.some((t) => t.status === "a_enviar");
    // Acompanha a leitura das encomendas e os envios em curso.
    useEffect(() => {
        if (!syncing && !sending) return;
        const t = setTimeout(load, 3000);
        return () => clearTimeout(t);
    }, [syncing, sending, data, load]);

    const openDetail = useCallback(async (id: number) => {
        if (!companyId) return;
        try {
            const res: any = await getStockTransfer(companyId, id);
            setDetail(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível abrir o envio.");
        }
    }, [companyId]);
    // O detalhe aberto acompanha o envio até acabar.
    useEffect(() => {
        if (!detail) return;
        const running = detail.transfer.status === "a_enviar" || detail.writes.some((w: any) => ["queued", "running"].includes(w.status));
        if (!running) return;
        const t = setTimeout(() => openDetail(detail.transfer.id), 2500);
        return () => clearTimeout(t);
    }, [detail, openDetail]);

    const act = async (fn: () => Promise<any>, ok: string) => {
        setBusy(true);
        try {
            await fn();
            toast.info(ok);
            await load();
            if (detail) await openDetail(detail.transfer.id);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível concluir.");
        } finally {
            setBusy(false);
        }
    };

    const hidden = data?.financial?.visible === false;
    const routes: TransferRoute[] = data?.routes ?? [];
    const pending: TransferRequest[] = data?.pending ?? [];
    const legacy: TransferRequest[] = data?.legacy ?? [];
    const can = data?.can ?? {};

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Transferências"
                    breadcrumbs={[{ label: "Restauração" }, { label: "Stock" }]}
                    info="Os envios do Economato para os restaurantes: a Guia que tira o stock do Economato e a Entrada que o põe no restaurante, ao preço médio do Economato."
                />
                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Transferências"
                            loading={!data && !!companyId}
                            status={<>Encomendas lidas do PingWin: {data?.last_orders_sync ? fmtWhen(data.last_orders_sync) : "ainda não lidas"}{syncing && <> · <Spinner size="sm" /> a ler</>}</>}
                            actions={<>
                                <Button size="sm" color="light" onClick={() => setHelp(true)} title="Como funciona" aria-label="Como funciona"><i className="ri-question-line" /></Button>
                                <AuditButton companyId={companyId} area="restauracao" origin="transferencias" onFinished={() => setAuditKey((k) => k + 1)} />
                                <Button size="sm" color="light" disabled={syncing || busy} onClick={() => companyId && act(() => refreshTransferOrders(companyId), "A ler as encomendas do PingWin.")}>
                                    <i className="ri-refresh-line me-1" />Ler encomendas
                                </Button>
                                {can.send && <Button size="sm" color="primary" onClick={() => setSend({ request: null })}><i className="ri-add-line me-1" />Envio sem encomenda</Button>}
                            </>}
                        >
                            {hidden && <FinancialNote className="mb-2" />}
                            {data && !data.flags?.send_proven && (
                                <Alert color="warning" className="py-2">O envio para o PingWin ainda não foi provado neste ambiente: pode preparar os envios, mas o botão Enviar fica bloqueado.</Alert>
                            )}
                            <Nav tabs className="nav-tabs-custom mb-3">
                                {TABS.map((t) => (
                                    <NavItem key={t.id}>
                                        <NavLink href="#" active={tab === t.id} onClick={(e) => { e.preventDefault(); setParams({ tab: t.id }, { replace: true }); }}>
                                            {t.label}{t.id === "pedidos" && pending.length > 0 && <Badge color="danger" pill className="ms-1">{pending.length}</Badge>}
                                        </NavLink>
                                    </NavItem>
                                ))}
                            </Nav>

                            {tab === "pedidos" && (
                                <>
                                    {pending.length === 0 && <Alert color="light">Sem encomendas por enviar nos últimos 60 dias.</Alert>}
                                    <Row>
                                        {pending.map((r) => (
                                            <Col key={r.id} xs={12} lg={6} className="mb-3">
                                                <div className="border rounded p-3 h-100" data-testid="pending-order">
                                                    <div className="d-flex justify-content-between gap-2">
                                                        <div>
                                                            <div className="fw-semibold">{r.document} · {r.store_name}</div>
                                                            <div className="small text-muted">{r.doctype} · {fmtDate(r.doc_date)}{r.employee_name ? ` · ${r.employee_name}` : ""}</div>
                                                        </div>
                                                        {(r.age_days ?? 0) > 3 && <Badge color="warning" className="align-self-start">Há {r.age_days} dias</Badge>}
                                                    </div>
                                                    <ul className="small mb-2 mt-2 ps-3">
                                                        {r.lines.slice(0, 6).map((l) => <li key={l.id}>{fmtStockQty(l.qnt)} {l.unit_desc} · {l.description}</li>)}
                                                        {r.lines.length > 6 && <li className="text-muted">mais {r.lines.length - 6}</li>}
                                                    </ul>
                                                    {can.send && (
                                                        <Button size="sm" color="primary" onClick={() => setSend({ request: r })} disabled={!r.route_id} title={r.route_id ? undefined : "Sem circuito para este restaurante."}>
                                                            <i className="ri-truck-line me-1" />Preparar o envio
                                                        </Button>
                                                    )}
                                                </div>
                                            </Col>
                                        ))}
                                    </Row>
                                    {legacy.length > 0 && (
                                        <details className="mt-2">
                                            <summary className="text-muted small">Pedidos antigos (Requisição interna), só para consulta: {legacy.length}</summary>
                                            <ul className="small mt-2">{legacy.map((r) => <li key={r.id}>{r.label}: {r.document} · {r.store_name} · {fmtDate(r.doc_date)} · {r.lines.length} linhas</li>)}</ul>
                                        </details>
                                    )}
                                </>
                            )}

                            {tab === "envios" && (
                                <div className="table-responsive">
                                    <Table hover size="sm" className="align-middle mb-0">
                                        <thead><tr><th>Envio</th><th>Para</th><th>Estado</th><th>Documentos</th><th className="text-end">Valor</th><th /></tr></thead>
                                        <tbody>
                                            {transfers.length === 0 && <tr><td colSpan={6} className="text-muted">Ainda não há envios feitos na XPLENDOR.</td></tr>}
                                            {transfers.map((t) => {
                                                const s = TRANSFER_STATUS[t.status];
                                                return (
                                                    <tr key={t.id} data-testid="transfer-row">
                                                        <td>#{t.id}{t.request ? ` · ${t.request.document}` : ""}<div className="small text-muted">{fmtWhen(t.created_at)}{t.created_by ? ` · ${t.created_by}` : ""}</div></td>
                                                        <td>{t.dest_name}</td>
                                                        <td><Badge color={s.color}><i className={`${s.icon} me-1`} />{s.label}</Badge>{t.error && <div className="small text-danger">{t.error}</div>}</td>
                                                        <td className="small">{t.exit_doc_number ?? "—"}<br />{t.entry_doc_number ?? (t.auto_receive ? "" : "receção pelo restaurante")}</td>
                                                        <td className="text-end"><StockMoney value={t.total_value_eur} hidden={hidden} /></td>
                                                        <td className="text-end"><Button size="sm" color="light" onClick={() => openDetail(t.id)}>Abrir</Button></td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </Table>
                                </div>
                            )}

                            {tab === "circuito" && (
                                <>
                                    <CircuitMap routes={routes} />
                                    <Row className="mt-3">
                                        {routes.map((r) => <Col key={r.id} xs={12} xl={6} className="mb-3"><RouteForm route={r} canEdit={!!can.configure} onSave={(body) => companyId && act(() => updateTransferRoute(companyId, r.id, body), "Circuito guardado.")} /></Col>)}
                                    </Row>
                                </>
                            )}
                        </PageCard>
                    </Col>
                </Row>
            </Container>

            <HowTransfersWork open={help} onClose={() => setHelp(false)} />
            <SendTransferModal open={!!send} onClose={() => setSend(null)} companyId={companyId} routes={routes} request={send?.request ?? null} canSend={!!can.send} hidden={hidden}
                onSent={(id) => { setSend(null); setParams({ tab: "envios" }, { replace: true }); load(); if (id) openDetail(id); }} />
            {detail && (
                <TransferDetail transfer={detail.transfer} hidden={hidden} busy={busy} can={can} onClose={() => setDetail(null)}
                    onReceive={(received) => companyId && act(() => receiveStockTransfer(companyId, detail.transfer.id, received), "A gravar a entrada no PingWin.")}
                    onVoid={() => companyId && act(() => voidStockTransfer(companyId, detail.transfer.id), "A anular no PingWin.")} />
            )}
        </div>
    );
}

function TransferDetail({ transfer: t, hidden, busy, can, onClose, onReceive, onVoid }: {
    transfer: TransferSummary; hidden: boolean; busy: boolean; can: any; onClose: () => void; onReceive: (r: Record<string, string>) => void; onVoid: () => void;
}) {
    const [received, setReceived] = useState<Record<string, string>>({});
    const [confirmVoid, setConfirmVoid] = useState(false);
    const s = TRANSFER_STATUS[t.status];
    const manual = t.status === "enviado" && !t.auto_receive;
    const retry = t.status === "falhou_rececao";
    const voidable = ["enviado", "recebido", "recebido_com_diferenca", "falhou_rececao"].includes(t.status);
    return (
        <Modal isOpen toggle={onClose} size="xl" scrollable fullscreen="md">
            <ModalHeader toggle={onClose}>Envio #{t.id} para o {t.dest_name} <Badge color={s.color} className="ms-2">{s.label}</Badge></ModalHeader>
            <ModalBody>
                <TransferPath transfer={t} />
                {t.error && <Alert color="danger" className="mt-3 py-2">{t.error}</Alert>}
                <div className="table-responsive mt-3">
                    <Table size="sm" className="align-middle mb-0">
                        <thead><tr><th>Artigo</th><th className="text-end">Enviado</th><th className="text-end">PCM</th><th className="text-end">Valor</th><th className="text-end">Recebido</th></tr></thead>
                        <tbody>
                            {(t.lines ?? []).map((l) => (
                                <tr key={l.id}>
                                    <td>{l.code} · {l.description}<div className="small text-muted">{fmtStockQty(l.qty)} {l.unit}{l.factor !== 1 ? ` × ${fmtStockQty(l.factor)}` : ""}</div></td>
                                    <td className="text-end">{fmtStockQty(l.qty_base)}</td>
                                    <td className="text-end"><StockMoney value={l.origin_pcm_eur} hidden={hidden} unit /></td>
                                    <td className="text-end"><StockMoney value={l.value_eur} hidden={hidden} /></td>
                                    <td className="text-end" style={{ width: 130 }}>
                                        {manual || retry ? (
                                            <Input bsSize="sm" inputMode="decimal" className="text-end" aria-label={`Recebido de ${l.description}`}
                                                value={received[String(l.id)] ?? String(l.received_qty_base ?? l.qty_base)} onChange={(e) => setReceived((r) => ({ ...r, [String(l.id)]: e.target.value.replace(",", ".") }))} />
                                        ) : l.received_qty_base !== null ? fmtStockQty(l.received_qty_base) : "—"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
                {confirmVoid && (
                    <Alert color="danger" className="mt-3 mb-0">
                        Vai anular no PingWin {t.entry_doc_number ? `a Entrada ${t.entry_doc_number} e depois ` : ""}a Guia {t.exit_doc_number}. O stock volta ao Economato. Confirma?
                        <div className="mt-2 d-flex gap-2"><Button size="sm" color="danger" disabled={busy} onClick={onVoid}>Anular no PingWin</Button><Button size="sm" color="light" onClick={() => setConfirmVoid(false)}>Não</Button></div>
                    </Alert>
                )}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <div>{voidable && can.void && !confirmVoid && <Button color="outline-danger" disabled={busy} onClick={() => setConfirmVoid(true)}><i className="ri-forbid-line me-1" />Anular envio</Button>}</div>
                <div className="d-flex gap-2">
                    <Button color="light" onClick={onClose}>Fechar</Button>
                    {manual && can.receive && <Button color="primary" disabled={busy} onClick={() => onReceive(received)}><i className="ri-inbox-archive-line me-1" />Confirmar a receção</Button>}
                    {retry && can.send && <Button color="primary" disabled={busy} onClick={() => onReceive(received)}><i className="ri-restart-line me-1" />Repetir só a entrada</Button>}
                </div>
            </ModalFooter>
        </Modal>
    );
}

function RouteForm({ route, canEdit, onSave }: { route: TransferRoute; canEdit: boolean; onSave: (body: Record<string, unknown>) => void }) {
    const [f, setF] = useState(() => ({
        name: route.name, auto_receive: route.auto_receive, active: route.active,
        load_address: { address: "", postalcode: "", postalcode_description: "", city: "", ...(route.load_address ?? {}) },
        unload_address: { address: "", postalcode: "", postalcode_description: "", city: "", ...(route.unload_address ?? {}) },
    }));
    const addr = (k: "load_address" | "unload_address", label: string) => (
        <fieldset className="mb-2">
            <legend className="fs-6 mb-1">{label}</legend>
            <Input bsSize="sm" className="mb-1" placeholder="Morada" value={f[k].address} disabled={!canEdit} onChange={(e) => setF({ ...f, [k]: { ...f[k], address: e.target.value } })} aria-label={`${label}: morada`} />
            <div className="d-flex gap-1">
                <Input bsSize="sm" style={{ width: 110 }} placeholder="Código postal" value={f[k].postalcode} disabled={!canEdit} onChange={(e) => setF({ ...f, [k]: { ...f[k], postalcode: e.target.value } })} aria-label={`${label}: código postal`} />
                <Input bsSize="sm" placeholder="Localidade" value={f[k].city} disabled={!canEdit} onChange={(e) => setF({ ...f, [k]: { ...f[k], city: e.target.value, postalcode_description: e.target.value } })} aria-label={`${label}: localidade`} />
            </div>
        </fieldset>
    );
    return (
        <div className="border rounded p-3 h-100">
            <div className="d-flex justify-content-between mb-2">
                <div className="fw-semibold">{route.origin_name} → {route.dest_name}</div>
                {!route.addresses_ok && <Badge color="warning">Falta a morada</Badge>}
            </div>
            <div className="small text-muted mb-2">Documento de saída: {route.exit_doc_label}</div>
            <div className="form-check form-switch mb-1">
                <Input type="switch" className="form-check-input" id={`ar-${route.id}`} checked={f.auto_receive} disabled={!canEdit} onChange={(e) => setF({ ...f, auto_receive: e.target.checked })} />
                <Label className="form-check-label" for={`ar-${route.id}`}>Receção automática (a Entrada segue com o envio)</Label>
            </div>
            <div className="form-check form-switch mb-2">
                <Input type="switch" className="form-check-input" id={`ac-${route.id}`} checked={f.active} disabled={!canEdit} onChange={(e) => setF({ ...f, active: e.target.checked })} />
                <Label className="form-check-label" for={`ac-${route.id}`}>Circuito ligado</Label>
            </div>
            {addr("load_address", "Morada de carga")}
            {addr("unload_address", "Morada de descarga")}
            {canEdit && <Button size="sm" color="primary" onClick={() => onSave(f)}>Guardar o circuito</Button>}
        </div>
    );
}
