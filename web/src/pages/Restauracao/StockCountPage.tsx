import { useCallback, useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import { Alert, Button, Container, Input, Modal, ModalBody, ModalFooter, ModalHeader, Progress, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { FinancialNote } from "Components/Common/HiddenMoney";
import {
    discardStockCount, finishStockCount, getRecipeConversionInfo, getStockCount, recountStockCount, saveStockCountLine, sendStockCount, voidStockCount,
} from "helpers/laravel_helper";
import { CountLine, StockCountDetail } from "common/models/stockcount.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { fmtDate, fmtWhen } from "pages/Auditoria/auditFormat";
import ConfirmFactorModal, { FactorLine } from "./fichas/ConfirmFactorModal";
import CountLineEditor from "./stock/CountLineEditor";
import { StockMoney, WRITE_STATUS, fmtStockQty, useStockWrite, writeMessage } from "./stock/stockFormat";
import { COUNT_STATUS } from "./StockCountsPage";

/**
 * XPLENDOR — Uma contagem (Fatia 7b). Contar: no telemóvel, por zona e posição ("Sem zona" no fim),
 * pesquisa fixa no topo, filtro "Por contar", progresso, gravação automática por linha. Rever (o gestor):
 * contado × teórico do último sync, diferença em € e % por ordem de |€|, pedir recontagem, aprovar e
 * enviar para o PingWin, anular. O envio fica bloqueado com a explicação quando não é permitido.
 */
export default function StockCountPage() {
    const { countId } = useParams<{ countId: string }>();
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<StockCountDetail | null>(null);
    const [search, setSearch] = useState("");
    const [onlyPending, setOnlyPending] = useState(false);
    const [busy, setBusy] = useState(false);
    const [factorLine, setFactorLine] = useState<FactorLine | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [confirm, setConfirm] = useState<"send" | "void" | null>(null);
    document.title = "Contagem | Stock | Xplendor";

    const load = useCallback(async () => {
        if (!companyId || !countId) return;
        try {
            const res: any = await getStockCount(companyId, Number(countId));
            setData(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a contagem.");
        }
    }, [companyId, countId]);
    useEffect(() => { load(); }, [load]);
    const { write, setWrite, busy: writing } = useStockWrite(companyId, (w) => {
        if (w.status === "ok") toast.success(writeMessage(w)); else toast.error(writeMessage(w));
        load();
    });

    const count = data?.count;
    const counting = count?.status === "em_contagem";
    const lines = data?.lines ?? [];
    const zoneName = useMemo(() => Object.fromEntries((data?.zones ?? []).map((z) => [z.id, z.name])), [data?.zones]);
    const [local, setLocal] = useState<Record<number, number | null>>({});
    const counted = (l: CountLine) => (l.id in local ? local[l.id] : l.qty_base) !== null;
    const visible = lines.filter((l) => (!onlyPending || !counted(l)) && (!search.trim() || `${l.description} ${l.code}`.toLowerCase().includes(search.trim().toLowerCase())));
    const groups = useMemo(() => {
        const out: { key: string; name: string; rows: CountLine[] }[] = [];
        for (const l of visible) {
            const key = l.zone_id ? String(l.zone_id) : "none";
            let g = out.find((x) => x.key === key);
            if (!g) { g = { key, name: l.zone_id ? (zoneName[l.zone_id] ?? "Zona") : "Sem zona", rows: [] }; out.push(g); }
            g.rows.push(l);
        }
        return out.sort((a, b) => (a.key === "none" ? 1 : 0) - (b.key === "none" ? 1 : 0));
    }, [visible, zoneName]);
    const total = lines.length;
    const done = lines.filter(counted).length;

    const saveLine = async (l: CountLine, entries: { qty: string; unit: string }[]) => {
        if (!companyId || !count) return;
        const res: any = await saveStockCountLine(companyId, count.id, l.id, entries);
        setLocal((p) => ({ ...p, [l.id]: res?.data?.line?.qty_base ?? null }));
    };
    const openFactor = async (l: CountLine) => {
        if (!companyId) return;
        try {
            const res: any = await getRecipeConversionInfo(companyId, l.product_id);
            if (res?.data?.line) setFactorLine(res.data.line);
            else toast.info("Este artigo não tem compras noutra embalagem: conte na unidade de stock.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível abrir a confirmação da embalagem.");
        }
    };
    const act = async (fn: () => Promise<any>, ok: string) => {
        setBusy(true);
        try {
            const res: any = await fn();
            if (res?.data?.write) setWrite(res.data.write); else toast.success(ok);
            await load();
        } catch (e: any) {
            toast.error(e?.response?.data?.message ?? e?.message ?? "Não foi possível.");
        } finally {
            setBusy(false);
            setConfirm(null);
        }
    };

    if (!data || !count) return <div className="page-content"><Container fluid><Skeleton variant="lines" lines={6} label="A carregar a contagem" /></Container></div>;
    const review = data.review;
    const hidden = data.financial?.visible === false;
    const st = COUNT_STATUS[count.status];

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title={`Contagem · ${count.warehouse ?? ""}`} crumbLabel="Contagem"
                    breadcrumbs={[{ label: "Restauração" }, { label: "Contagens", to: "/restauracao/stock/contagens" }]} />
                <div className="d-flex flex-wrap align-items-center gap-2 mb-3 fs-13">
                    <span className={`badge bg-${st.color}-subtle text-${st.color}`}>{st.label}</span>
                    <span>Data no PingWin: <strong>{fmtDate(count.inventory_date)}</strong> ({count.moment === "depois_fecho" ? "depois do fecho" : "antes da abertura"})</span>
                    {count.blind && <span className="badge bg-light text-body">Cega</span>}
                    {count.pingwin_doc_number && <span>Documento: <strong data-testid="count-doc">{count.pingwin_doc_number}</strong></span>}
                    {count.error_message && <span className="text-danger">{count.error_message}</span>}
                </div>
                {write && (writing || write.status !== "ok") && (
                    <Alert color={WRITE_STATUS[write.status].color} className="fs-13">{writing && <Spinner size="sm" className="me-2" />}{WRITE_STATUS[write.status].label}{!writing && write.error ? `: ${write.error}` : ""}</Alert>
                )}

                {(counting || !data.can_manage) && (
                    <PageCard title="Contar" status={<>{done} de {total} artigos contados</>}>
                        <div className="position-sticky bg-body p-2 border-bottom" style={{ top: 70, zIndex: 5 }}>
                            <Progress value={total ? (done / total) * 100 : 0} className="mb-2" style={{ height: 6 }} />
                            <div className="d-flex gap-2">
                                <Input type="search" placeholder="Pesquisar artigo…" value={search} onChange={(e) => setSearch(e.target.value)} style={{ minHeight: 44 }} aria-label="Pesquisar artigo" />
                                <Button color={onlyPending ? "primary" : "light"} onClick={() => setOnlyPending((v) => !v)} className="text-nowrap" data-testid="count-pending">Por contar</Button>
                            </div>
                        </div>
                        <div className="px-2">
                            {groups.map((g) => {
                                const gd = g.rows.filter(counted).length;
                                return (
                                    <div key={g.key}>
                                        <div className="d-flex justify-content-between fs-12 text-uppercase text-muted fw-semibold pt-3"><span>{g.name}</span><span>{gd} de {g.rows.length}</span></div>
                                        {g.rows.map((l) => (
                                            <CountLineEditor key={l.id} line={l} disabled={!counting} onSave={(e) => saveLine(l, e)} onConfirmUnit={() => openFactor(l)} />
                                        ))}
                                    </div>
                                );
                            })}
                            {groups.length === 0 && <p className="text-muted p-3 mb-0">Nada para estes filtros.</p>}
                        </div>
                        {counting && (
                            <div className="p-3 d-flex flex-wrap gap-2 justify-content-end">
                                {data.can_manage && <Button color="light" disabled={busy} onClick={() => companyId && act(() => discardStockCount(companyId, count.id), "Contagem anulada na XPLENDOR.")}>Desistir da contagem</Button>}
                                <Button color="primary" disabled={busy || done === 0} onClick={() => companyId && act(() => finishStockCount(companyId, count.id), "Contagem terminada: passa para revisão.")} data-testid="count-finish">
                                    Terminar contagem
                                </Button>
                            </div>
                        )}
                    </PageCard>
                )}

                {review && data.can_manage && (
                    <PageCard title="Revisão" status={<>Teórico do último sync: {review.theoretical_at ? fmtWhen(review.theoretical_at) : "—"}</>}
                        info={`"Recontar?" quando a diferença vale ${review.limits.eur} € ou mais, ou é de ${review.limits.pct}% ou mais e de pelo menos ${review.limits.min_qty} unidade.`}>
                        {hidden && <FinancialNote className="m-3" />}
                        <div className="d-flex flex-wrap gap-4 px-3 py-3 border-bottom fs-13" data-testid="count-totals">
                            <div><div className="text-muted fs-12">Valor contado</div><strong><StockMoney value={review.totals.counted_eur} hidden={hidden} seed={1} /></strong></div>
                            <div><div className="text-muted fs-12">Valor teórico</div><strong><StockMoney value={review.totals.theoretical_eur} hidden={hidden} seed={2} /></strong></div>
                            <div><div className="text-muted fs-12">Diferença</div><strong><StockMoney value={review.totals.diff_eur} hidden={hidden} seed={3} /></strong></div>
                            <div><div className="text-muted fs-12">Linhas com diferença</div><strong>{review.totals.with_diff}</strong></div>
                            {review.totals.not_counted > 0 && <div className="text-warning align-self-center">{review.totals.not_counted} artigos não contados não vão no documento.</div>}
                        </div>
                        {review.send_block && count.status === "em_revisao" && <Alert color="warning" className="m-3 fs-13" data-testid="count-send-block"><i className="ri-lock-line me-1" />{review.send_block.reason}</Alert>}
                        <div className="table-responsive">
                            <Table size="sm" className="align-middle mb-0" data-testid="count-review">
                                <thead className="table-light"><tr>
                                    {count.status === "em_revisao" && <th />}
                                    <th>Artigo</th><th>ABC</th><th className="text-end">Contado</th><th className="text-end">Teórico</th><th className="text-end">Diferença</th><th className="text-end">€</th><th className="text-end">%</th><th>PingWin</th>
                                </tr></thead>
                                <tbody>
                                    {review.rows.map((r, i) => (
                                        <tr key={r.id} className={r.counted === null ? "text-muted" : undefined}>
                                            {count.status === "em_revisao" && <td><Input type="checkbox" aria-label={`Recontar ${r.description}`} disabled={r.counted === null} checked={selected.includes(r.id)}
                                                onChange={(e) => setSelected((p) => (e.target.checked ? [...p, r.id] : p.filter((x) => x !== r.id)))} /></td>}
                                            <td><div className="fw-medium">{r.description}</div><div className="fs-12 text-muted">{r.code} · {r.unit}{r.previous !== null && ` · antes: ${fmtStockQty(r.previous)}`}</div></td>
                                            <td>{r.abc}</td>
                                            <td className="text-end">{r.counted === null ? "Não contado" : fmtStockQty(r.counted)}</td>
                                            <td className="text-end">{fmtStockQty(r.theoretical)}</td>
                                            <td className="text-end">{fmtStockQty(r.diff)}</td>
                                            <td className="text-end"><StockMoney value={r.diff_eur} hidden={hidden} seed={i} /></td>
                                            <td className="text-end">{r.diff_pct === null ? "—" : `${r.diff_pct.toLocaleString("pt-PT")}%`}{r.recount_suggested && <div><span className="badge bg-warning-subtle text-warning">Recontar?</span></div>}</td>
                                            <td className="fs-12">{r.pingwin ? (r.pingwin.status === "confirmado" ? <span className="text-success">Confirmado</span> : <span className="text-danger">Diferente (PingWin: {fmtStockQty(r.pingwin.confirmed_qty)})</span>) : "—"}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                        <div className="p-3 d-flex flex-wrap gap-2 justify-content-end">
                            {count.status === "em_revisao" && (
                                <>
                                    <Button color="outline-primary" disabled={busy || !selected.length} onClick={() => companyId && act(() => recountStockCount(companyId, count.id, selected), "Recontagem pedida.").then(() => setSelected([]))}>
                                        Pedir recontagem ({selected.length})
                                    </Button>
                                    <Button color="primary" disabled={busy || writing || !!review.send_block} title={review.send_block?.reason} onClick={() => setConfirm("send")} data-testid="count-send">
                                        Aprovar e enviar para o PingWin
                                    </Button>
                                </>
                            )}
                            {count.status === "enviada" && <Button color="outline-danger" disabled={busy || writing} onClick={() => setConfirm("void")}>Anular no PingWin</Button>}
                        </div>
                    </PageCard>
                )}

                <Modal isOpen={confirm !== null} centered toggle={() => !busy && setConfirm(null)}>
                    <ModalHeader toggle={() => !busy && setConfirm(null)}>{confirm === "send" ? "Enviar a contagem para o PingWin" : "Anular a contagem no PingWin"}</ModalHeader>
                    <ModalBody className="fs-14">
                        {confirm === "send" && review ? (
                            <>
                                <p>Vai gravar no PingWin uma Contagem Fechada:</p>
                                <ul>
                                    <li>Armazém: <strong>{count.warehouse}</strong></li>
                                    <li>Data: <strong>{fmtDate(count.inventory_date)}</strong></li>
                                    <li>Artigos: <strong>{review.totals.counted}</strong></li>
                                    <li>Diferença: <strong><StockMoney value={review.totals.diff_eur} hidden={hidden} seed={5} /></strong></li>
                                </ul>
                                <p className="text-muted fs-13 mb-0">O stock destes artigos passa a ser o que contou. Depois a XPLENDOR relê cada artigo para confirmar.</p>
                            </>
                        ) : (
                            <p className="mb-0">Vai anular o documento <strong>{count.pingwin_doc_number}</strong> no PingWin. O stock volta a ser o de antes da contagem.</p>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setConfirm(null)} disabled={busy}>Cancelar</Button>
                        <Button color={confirm === "send" ? "primary" : "danger"} disabled={busy}
                            onClick={() => companyId && (confirm === "send" ? act(() => sendStockCount(companyId, count.id), "A enviar.") : act(() => voidStockCount(companyId, count.id), "A anular."))}>
                            {busy ? <Spinner size="sm" /> : confirm === "send" ? "Enviar para o PingWin" : "Anular no PingWin"}
                        </Button>
                    </ModalFooter>
                </Modal>
                {factorLine && (
                    <ConfirmFactorModal open line={factorLine} companyId={companyId} hidden={hidden} onClose={() => setFactorLine(null)} onDone={() => { setFactorLine(null); load(); }} />
                )}
            </Container>
        </div>
    );
}
