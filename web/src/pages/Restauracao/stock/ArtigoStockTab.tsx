import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Spinner, Table } from "reactstrap";
import ArtigoMovements from "./ArtigoMovements";
import { toast } from "react-toastify";
import InfoTip from "Components/Common/InfoTip";
import Skeleton from "Components/Common/Skeleton";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { getStockArticle, getStockRun, refreshStockArticle, writeStockLimits } from "helpers/laravel_helper";
import { StockArticleResponse, StockArticleWarehouse } from "common/models/stock.model";
import { fmtDate, fmtWhen } from "pages/Auditoria/auditFormat";
import {
    CONSUMPTION_TIP, COVERAGE_TIP, PCM_TIP, PCU_TIP, StockMoney, StockStates, WRITE_STATUS, fmtDays, fmtStockQty, unitLabel, useStockWrite, writeMessage,
} from "./stockFormat";

/**
 * XPLENDOR — Fatia 7a: o separador "Stock" do artigo. O stock por armazém (do espelho, lido de noite),
 * o consumo teórico e quem o consome, a evolução e a edição do mínimo, do máximo e de "Não usa
 * genéricos" (escrita no PingWin, uma de cada vez, confirmada por releitura).
 */
export default function ArtigoStockTab({ companyId, productId }: { companyId: number | null; productId: string }) {
    const [data, setData] = useState<StockArticleResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [edit, setEdit] = useState<StockArticleWarehouse | null>(null);
    const [refreshing, setRefreshing] = useState<number | null>(null);
    const [tick, setTick] = useState(0);
    const [view, setView] = useState<"stock" | "movimentos">("stock");

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        setError(null);
        try {
            const res: any = await getStockArticle(companyId, productId);
            setData(res?.data ?? null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível carregar o stock do artigo.");
        } finally {
            setLoading(false);
        }
    }, [companyId, productId]);
    useEffect(() => { load(); }, [load]);

    // Releitura de um artigo: pergunta o estado até acabar e recarrega.
    useEffect(() => {
        if (!companyId || refreshing === null) return;
        const t = setTimeout(async () => {
            try {
                const res: any = await getStockRun(companyId, refreshing);
                const run = res?.data?.run;
                if (run && run.status !== "running") {
                    setRefreshing(null);
                    if (run.status === "done" && run.products_failed === 0) toast.success("Stock relido do PingWin.");
                    else toast.error("Não foi possível reler o stock no PingWin. Tente de novo dentro de momentos.");
                    load();
                    return;
                }
            } catch { /* tenta outra vez */ }
            setTick((k) => k + 1);
        }, 2000);
        return () => clearTimeout(t);
    }, [companyId, refreshing, tick, load]);

    const reread = async () => {
        if (!companyId) return;
        try {
            const res: any = await refreshStockArticle(companyId, productId);
            setRefreshing(res?.data?.run?.id ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível pedir a releitura.");
        }
    };

    if (loading && !data) return <Skeleton variant="lines" lines={4} label="A carregar o stock" />;
    if (error && !data) {
        return (
            <div className="text-center p-3">
                <p className="text-danger mb-2">{error}</p>
                <Button size="sm" color="outline-primary" onClick={load}><i className="ri-refresh-line me-1" />Tentar de novo</Button>
            </div>
        );
    }
    if (!data) return null;

    const hidden = data.financial?.visible === false;
    const unit = data.stock_item?.stock_unit ?? data.warehouses[0]?.unit ?? null;
    const c = data.consumption;
    const noConsumption = data.total.qnt > 0 && !data.total.per_day;

    const viewNav = (
        <Nav pills className="mb-3 fs-13">
            <NavItem><NavLink className={view === "stock" ? "active" : ""} onClick={() => setView("stock")} style={{ cursor: "pointer" }}>Stock por armazém</NavLink></NavItem>
            <NavItem><NavLink className={view === "movimentos" ? "active" : ""} onClick={() => setView("movimentos")} style={{ cursor: "pointer" }} data-testid="tab-movimentos">Movimentos</NavLink></NavItem>
        </Nav>
    );
    if (view === "movimentos") {
        return <div>{viewNav}<ArtigoMovements companyId={companyId} productId={productId} warehouses={data.warehouses.map((w) => ({ id: w.warehouse_id, name: w.warehouse }))} /></div>;
    }

    return (
        <div data-testid="artigo-stock">
            {viewNav}
            {hidden && <FinancialNote className="mb-2" />}
            <div className="d-flex flex-wrap align-items-center gap-2 mb-3 fs-13">
                <span className="text-muted">
                    Unidade de stock: <strong className="text-body">{unit ?? "—"}</strong>
                    {data.stock_item?.stockconfig && <> · Configuração: <strong className="text-body">{data.stock_item.stockconfig}</strong></>}
                    {" · "}Lido do PingWin: {data.stock_item?.last_read_at ? fmtWhen(data.stock_item.last_read_at) : "ainda não lido"}
                </span>
                {data.stock_item?.last_error && <span className="text-danger">Última leitura falhou: {data.stock_item.last_error}</span>}
                {data.can_edit && (
                    <Button size="sm" color="outline-primary" className="ms-auto" onClick={reread} disabled={refreshing !== null} data-testid="stock-reread">
                        {refreshing !== null ? <><Spinner size="sm" className="me-1" />A ler do PingWin…</> : <><i className="ri-refresh-line me-1" />Reler do PingWin</>}
                    </Button>
                )}
            </div>

            {data.warehouses.length === 0 ? (
                <p className="text-muted">O PingWin ainda não tem stock deste artigo em nenhum armazém (ou ainda não foi lido).</p>
            ) : (
                <div className="table-responsive mb-3">
                    <Table size="sm" className="align-middle mb-0" data-testid="stock-by-warehouse">
                        <thead className="table-light">
                            <tr>
                                <th>Armazém</th>
                                <th className="text-end">Quantidade</th>
                                <th className="text-end">PCM <InfoTip label="O que é o PCM" text={PCM_TIP} /></th>
                                <th className="text-end">PCU <InfoTip label="O que é o PCU" text={PCU_TIP} /></th>
                                <th className="text-end">Valor</th>
                                <th className="text-end">Mínimo</th>
                                <th className="text-end">Máximo</th>
                                <th>Não usa genéricos</th>
                                <th className="text-end">Consumo por dia <InfoTip label="Como se calcula o consumo teórico" text={CONSUMPTION_TIP} /></th>
                                <th className="text-end">Cobertura <InfoTip label="O que são os dias de cobertura" text={COVERAGE_TIP} /></th>
                                <th>Última valorização</th>
                                <th>Avisos</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {data.warehouses.map((w, i) => (
                                <tr key={w.warehouse_id} data-warehouse={w.warehouse_id}>
                                    <td>
                                        <div className="fw-medium">{w.warehouse}</div>
                                        {w.stores.length > 0 && <div className="text-muted fs-12">Serve: {w.stores.join(", ")}</div>}
                                    </td>
                                    <td className={`text-end text-nowrap ${w.qnt < 0 ? "text-danger fw-medium" : ""}`}>{fmtStockQty(w.qnt)}</td>
                                    <td className="text-end text-nowrap"><StockMoney value={w.pcm_eur} hidden={hidden} unit seed={i} /></td>
                                    <td className="text-end text-nowrap"><StockMoney value={w.pcu_eur} hidden={hidden} unit seed={i + 1} /></td>
                                    <td className="text-end text-nowrap"><StockMoney value={w.value_eur} hidden={hidden} seed={i + 2} /></td>
                                    <td className="text-end">{fmtStockQty(w.min_stock)}</td>
                                    <td className="text-end">{fmtStockQty(w.max_stock)}</td>
                                    <td>{w.disable_generic ? "Sim" : "Não"}</td>
                                    <td className="text-end text-nowrap">{w.per_day ? fmtStockQty(w.per_day, 2) : <span className="text-muted">—</span>}</td>
                                    <td className="text-end">{fmtDays(w.coverage_days)}</td>
                                    <td className="text-nowrap">{fmtDate(w.valorization_date)}</td>
                                    <td><StockStates states={w.states} /></td>
                                    <td className="text-end">
                                        {data.can_edit && (
                                            <Button size="sm" color="outline-primary" className="text-nowrap" onClick={() => setEdit(w)} data-testid="stock-edit"
                                                title={`Mínimo, máximo e «Não usa genéricos» no ${w.warehouse}`}>
                                                <i className="ri-pencil-line me-1" />Editar
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="fw-semibold">
                                <td>Todos os armazéns</td>
                                <td className={`text-end text-nowrap ${data.total.qnt < 0 ? "text-danger" : ""}`}>{fmtStockQty(data.total.qnt)}</td>
                                <td colSpan={2} />
                                <td className="text-end text-nowrap"><StockMoney value={data.total.value_eur} hidden={hidden} seed={9} /></td>
                                <td colSpan={3} />
                                <td className="text-end text-nowrap">{data.total.per_day ? fmtStockQty(data.total.per_day, 2) : "—"}</td>
                                <td className="text-end">{fmtDays(data.total.coverage_days)}</td>
                                <td colSpan={3} />
                            </tr>
                        </tfoot>
                    </Table>
                </div>
            )}

            <div className="row g-3">
                <div className="col-lg-6">
                    <h6 className="fs-13 text-uppercase text-muted fw-semibold mb-2">Quem consome</h6>
                    {c.window.from && <p className="text-muted fs-12 mb-2">Consumo teórico de {fmtDate(c.window.from)} a {fmtDate(c.window.to)} ({c.window.days} dias com vendas lidas).</p>}
                    {c.sources.length > 0 ? (
                        <ul className="list-unstyled mb-2 fs-13">
                            {c.sources.map((s) => (
                                <li key={s.product_id} className="d-flex justify-content-between gap-2 py-1 border-bottom">
                                    <span>{s.via === "venda" ? "Vendido diretamente" : <Link to={`/restauracao/fichas-tecnicas/${s.product_id}`}>{s.description}</Link>}</span>
                                    <span className="text-nowrap">{fmtStockQty(s.qty, 2)} {unitLabel(unit, s.qty)}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="fs-13 mb-2">{noConsumption
                            ? "Nenhuma venda consumiu este artigo: nenhuma ficha em vigor o usa e ele não se vende diretamente. O stock só sobe."
                            : "Sem consumo teórico no período."}</p>
                    )}
                    {c.low_confidence && <p className="text-warning fs-12 mb-2">Parte do consumo passa por preparados da cozinha sem rendimento confirmado: é uma estimativa.</p>}
                    {data.total.unassigned_per_day ? <p className="text-warning fs-12 mb-2">Há consumo de lojas sem armazém por omissão ({fmtStockQty(data.total.unassigned_per_day, 2)} por dia): defina-o em <Link to="/restauracao/armazens">Restauração › Armazéns</Link>.</p> : null}
                    {c.used_by.length > 0 && (
                        <p className="fs-12 text-muted mb-0">Fichas em vigor que o usam: {c.used_by.map((u, i) => <span key={u.product_id}>{i > 0 && ", "}<Link to={`/restauracao/fichas-tecnicas/${u.product_id}`}>{u.description}</Link></span>)}</p>
                    )}
                </div>
                <div className="col-lg-6">
                    <h6 className="fs-13 text-uppercase text-muted fw-semibold mb-2">Evolução (todos os armazéns)</h6>
                    {data.history.length > 1 ? (
                        <Table size="sm" className="mb-3 fs-13">
                            <tbody>
                                {data.history.slice(-8).reverse().map((h) => (
                                    <tr key={h.date}><td>{fmtDate(h.date)}</td><td className="text-end">{fmtStockQty(h.qnt)} {unitLabel(unit, h.qnt)}</td></tr>
                                ))}
                            </tbody>
                        </Table>
                    ) : (
                        <p className="text-muted fs-13">A evolução aparece a partir da segunda leitura (uma fotografia por dia).</p>
                    )}
                    {data.writes.length > 0 && (
                        <>
                            <h6 className="fs-13 text-uppercase text-muted fw-semibold mb-2">Últimas gravações no PingWin</h6>
                            <ul className="list-unstyled fs-13 mb-0">
                                {data.writes.map((w) => (
                                    <li key={w.id} className="py-1 border-bottom">
                                        <span className={`badge bg-${WRITE_STATUS[w.status].color}-subtle text-${WRITE_STATUS[w.status].color} me-2`}>{WRITE_STATUS[w.status].label}</span>
                                        {fmtWhen(w.created_at)}{w.user ? ` · ${w.user}` : ""}
                                        {w.status !== "ok" && w.error && <div className="text-muted fs-12">{w.error}</div>}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            </div>

            {edit && companyId && (
                <LimitsModal
                    companyId={companyId}
                    productId={productId}
                    row={edit}
                    guard={data.write_guard}
                    onClose={() => setEdit(null)}
                    onDone={() => { setEdit(null); load(); }}
                />
            )}
        </div>
    );
}

function LimitsModal({ companyId, productId, row, guard, onClose, onDone }: {
    companyId: number; productId: string; row: StockArticleWarehouse; guard: { mode: string; reason: string } | null; onClose: () => void; onDone: () => void;
}) {
    const toInput = (v: number | null) => (v === null || v === undefined ? "" : String(v).replace(".", ","));
    const [min, setMin] = useState(toInput(row.min_stock));
    const [max, setMax] = useState(toInput(row.max_stock));
    const [generic, setGeneric] = useState(row.disable_generic);
    const [err, setErr] = useState<string | null>(null);
    const { write, setWrite, busy } = useStockWrite(companyId, (w) => {
        if (w.status === "ok") { toast.success(writeMessage(w)); onDone(); } else { setErr(writeMessage(w)); }
    });

    const parse = (s: string): number | null | undefined => {
        const t = s.trim().replace(/\s/g, "").replace(",", ".");
        if (t === "") return null;
        const n = Number(t);
        return Number.isFinite(n) && n >= 0 ? n : undefined;
    };
    const submit = async () => {
        setErr(null);
        const mn = parse(min);
        const mx = parse(max);
        if (mn === undefined || mx === undefined) { setErr("Escreva números maiores ou iguais a 0 (ou deixe vazio)."); return; }
        if (mn !== null && mx !== null && mx > 0 && mn > mx) { setErr("O mínimo não pode ser maior do que o máximo."); return; }
        const body: any = { warehouse_id: row.warehouse_id };
        if (mn !== row.min_stock) body.min_stock = mn;
        if (mx !== row.max_stock) body.max_stock = mx;
        if (generic !== row.disable_generic) body.disable_generic = generic;
        if (Object.keys(body).length === 1) { setErr("Não há nada a mudar."); return; }
        try {
            const res: any = await writeStockLimits(companyId, productId, body);
            setWrite(res?.data?.write ?? null);
        } catch (e: any) {
            setErr(e?.response?.data?.message ?? e?.message ?? "Não foi possível pedir a gravação.");
        }
    };

    return (
        <Modal isOpen centered toggle={() => !busy && onClose()} data-testid="stock-limits-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Mínimo e máximo · {row.warehouse}</ModalHeader>
            <ModalBody>
                {guard && <Alert color="warning" className="fs-13" data-testid="stock-guard"><i className="ri-lock-line me-1" />{guard.reason}</Alert>}
                <p className="text-muted fs-13">Grava no PingWin, só neste armazém. Depois a XPLENDOR relê o artigo para confirmar. Deixe vazio para não ter limite.</p>
                <div className="row g-3">
                    <div className="col-6">
                        <Label className="form-label" for="stock-min">Mínimo ({unitLabel(row.unit)})</Label>
                        <Input id="stock-min" inputMode="decimal" value={min} onChange={(e) => setMin(e.target.value)} disabled={busy || !!guard} />
                    </div>
                    <div className="col-6">
                        <Label className="form-label" for="stock-max">Máximo ({unitLabel(row.unit)})</Label>
                        <Input id="stock-max" inputMode="decimal" value={max} onChange={(e) => setMax(e.target.value)} disabled={busy || !!guard} />
                    </div>
                    <div className="col-12">
                        <div className="form-check">
                            <Input type="checkbox" className="form-check-input" id="stock-generic" checked={generic} onChange={(e) => setGeneric(e.target.checked)} disabled={busy || !!guard} />
                            <Label className="form-check-label" for="stock-generic">Não usa genéricos</Label>
                            <InfoTip className="ms-1" label="O que é «Não usa genéricos»" text="A mesma opção do separador Stock do artigo no PingWin, por armazém. A XPLENDOR só a grava; o efeito é o que o PingWin lhe dá." />
                        </div>
                    </div>
                </div>
                {write && (
                    <div className="mt-3 fs-13" role="status">
                        <span className={`badge bg-${WRITE_STATUS[write.status].color}-subtle text-${WRITE_STATUS[write.status].color}`}>{WRITE_STATUS[write.status].label}</span>
                        {busy && <Spinner size="sm" className="ms-2" />}
                    </div>
                )}
                {err && <Alert color="danger" className="mt-3 mb-0 fs-13">{err}</Alert>}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                <Button color="primary" onClick={submit} disabled={busy || !!guard} data-testid="stock-limits-save">
                    {busy ? <><Spinner size="sm" className="me-1" />A gravar no PingWin…</> : <><i className="ri-save-line me-1" />Gravar no PingWin</>}
                </Button>
            </ModalFooter>
        </Modal>
    );
}
