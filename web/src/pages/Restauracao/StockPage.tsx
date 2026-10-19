import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Container, Row, Col, Label } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import Skeleton from "Components/Common/Skeleton";
import InfoTip from "Components/Common/InfoTip";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { getStock } from "helpers/laravel_helper";
import { StockListResponse, StockRow } from "common/models/stock.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import AuditButton from "pages/Auditoria/AuditButton";
import AuditCountCell, { useAuditCounts } from "pages/Auditoria/AuditCountCell";
import { fmtDate, fmtWhen } from "pages/Auditoria/auditFormat";
import { CONSUMPTION_TIP, COVERAGE_TIP, PCM_TIP, PCU_TIP, StockMoney, StockStates, fmtDays, fmtStockQty } from "./stock/stockFormat";

/**
 * XPLENDOR — Restauração › Stock (Fatia 7a, só leitura). O stock de cada artigo, tal como o PingWin o
 * mostra (lido de noite), num armazém ou em todos: quantidade, custo médio (PCM), último custo (PCU),
 * valor, mínimo e máximo, consumo teórico por dia, dias de cobertura e avisos. Por ordem de Pareto do
 * valor. A ordenação, os filtros e a página pedem-se ao servidor. Os euros só aparecem a quem vê as
 * Finanças (o servidor não os envia aos outros).
 */

const PER_PAGE = 25;
type Opt = { value: string; label: string };
type Sort = { id: string; key: string; desc: boolean } | null;

export default function StockPage() {
    document.title = "Stock | Restauração | Xplendor";
    const navigate = useNavigate();
    const companyId = useWorkingCompanyId();

    const [data, setData] = useState<StockListResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [warehouse, setWarehouse] = useState("");
    const [family, setFamily] = useState("");
    const [state, setState] = useState("");
    const [sort, setSort] = useState<Sort>(null);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        setError(null);
        try {
            const res: any = await getStock(companyId, {
                page, perPage: PER_PAGE,
                search: search.trim() || undefined,
                warehouse_id: warehouse || undefined,
                family: family || undefined,
                state: state || undefined,
                sort: sort?.key,
                dir: sort ? (sort.desc ? "desc" : "asc") : undefined,
            });
            setData(res?.data ?? null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível carregar o stock.");
        } finally {
            setLoading(false);
        }
    }, [companyId, page, search, warehouse, family, state, sort]);

    useEffect(() => {
        const t = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(t);
    }, [fetchRows]);
    useEffect(() => { setPage(1); }, [search, warehouse, family, state, sort]);

    const hidden = data?.financial?.visible === false;
    const rows = data?.stock?.data ?? [];
    const meta = data?.stock;
    const summary = data?.summary;
    const single = !!warehouse;

    const warehouseOptions: Opt[] = useMemo(() => [{ value: "", label: "Todos os armazéns" }, ...(data?.warehouses ?? []).map((w) => ({ value: w.id, label: w.name ?? w.id }))], [data?.warehouses]);
    const familyOptions: Opt[] = useMemo(() => [{ value: "", label: "Todas as famílias" }, ...(data?.families ?? []).map((f) => ({ value: f, label: f }))], [data?.families]);
    const stateOptions: Opt[] = useMemo(() => [{ value: "", label: "Todos os estados" }, ...(data?.states ?? []).map((s) => ({ value: s.id, label: `${s.label} (${summary?.counts?.[s.id] ?? 0})` }))], [data?.states, summary?.counts]);

    const open = (r: StockRow) => navigate(`/cadastros/artigos/${r.product_id}?tab=stock`);
    const [auditKey, setAuditKey] = useState(0);
    const auditCounts = useAuditCounts(companyId, "artigo", rows.map((r) => r.product_id), auditKey);

    const euroSort = (key: string) => (hidden ? undefined : key);
    const columns: DTColumn<StockRow>[] = [
        {
            id: "description", header: "Artigo", sortKey: "description", value: (r) => r.description, mobile: "title", hideable: false,
            cell: (r) => (
                <div style={{ minWidth: 170 }}>
                    <span className="fw-medium">{r.description}</span>
                    {r.code && <div className="text-muted fs-12">{r.code}</div>}
                </div>
            ),
        },
        { id: "family", header: "Família", sortKey: "family", value: (r) => r.family, defaultVisible: false, cell: (r) => (r.family ? <span className="fs-13">{r.family}</span> : <span className="text-muted">—</span>) },
        { id: "unit", header: "Unidade", sortable: false, value: (r) => r.unit, cell: (r) => r.unit ?? <span className="text-muted">—</span>, nowrap: true },
        {
            id: "qnt", header: "Quantidade", sortKey: "qnt", value: (r) => r.qnt, align: "end", nowrap: true,
            cell: (r) => <span className={r.qnt < 0 ? "text-danger fw-medium" : undefined}>{fmtStockQty(r.qnt)}</span>,
        },
        { id: "pcm", header: "PCM", sortKey: euroSort("pcm"), value: (r) => r.pcm_eur, align: "end", nowrap: true, cell: (r) => <StockMoney value={r.pcm_eur} hidden={hidden} unit seed={r.pareto} /> },
        { id: "pcu", header: "PCU", sortKey: euroSort("pcu"), value: (r) => r.pcu_eur, align: "end", nowrap: true, defaultVisible: false, cell: (r) => <StockMoney value={r.pcu_eur} hidden={hidden} unit seed={r.pareto + 1} /> },
        { id: "value", header: "Valor", sortKey: euroSort("value"), value: (r) => r.value_eur, align: "end", nowrap: true, cell: (r) => <StockMoney value={r.value_eur} hidden={hidden} seed={r.pareto + 2} /> },
        { id: "cum", header: "% acumulada", sortable: false, value: (r) => r.cum_pct, defaultVisible: false, align: "end", nowrap: true, cell: (r) => (r.cum_pct === null ? "—" : `${r.cum_pct.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`) },
        { id: "min", header: "Mínimo", sortKey: "min", value: (r) => r.min_stock, align: "end", nowrap: true, cell: (r) => (single ? fmtStockQty(r.min_stock) : <span className="text-muted" title="Escolha um armazém para ver o mínimo">—</span>) },
        { id: "max", header: "Máximo", sortKey: "max", value: (r) => r.max_stock, align: "end", nowrap: true, cell: (r) => (single ? fmtStockQty(r.max_stock) : <span className="text-muted" title="Escolha um armazém para ver o máximo">—</span>) },
        {
            id: "per_day", header: "Consumo por dia", sortKey: "per_day", value: (r) => r.per_day, align: "end", nowrap: true,
            cell: (r) => (r.per_day === null ? <span className="text-muted">—</span> : (
                <>{fmtStockQty(r.per_day, 2)}{r.low_confidence && <div className="text-warning fs-11" title="Passa por preparados da cozinha sem rendimento confirmado">estimativa</div>}</>
            )),
        },
        { id: "coverage", header: "Dias de cobertura", sortKey: "coverage", value: (r) => r.coverage_days, align: "end", nowrap: true, cell: (r) => <span className={(r.coverage_days ?? 0) > 60 ? "text-warning fw-medium" : undefined}>{fmtDays(r.coverage_days)}</span> },
        { id: "valorization", header: "Última valorização", sortKey: "valorization", value: (r) => r.valorization_date, nowrap: true, cell: (r) => fmtDate(r.valorization_date) },
        { id: "states", header: "Avisos", sortable: false, value: (r) => r.states.join(","), mobile: "subtitle", cell: (r) => <div style={{ minWidth: 130 }}><StockStates states={r.states} /></div> },
        {
            id: "audit", header: "Auditoria", sortable: false, value: (r) => auditCounts[r.product_id]?.open ?? 0, align: "center",
            cell: (r) => <AuditCountCell counts={auditCounts} entityType="artigo" entityId={r.product_id} label={r.description} />,
        },
    ];
    const cols = useDataColumns("restauracao.stock", columns);

    const activeFilterCount = [!!warehouse, !!family, !!state].filter(Boolean).length;
    const clearFilters = () => { setSearch(""); setWarehouse(""); setFamily(""); setState(""); };
    const noFilters = !search && !warehouse && !family && !state;
    const unassigned = (summary?.stores ?? []).filter((s) => !s.warehouse_id);

    const filterField = (label: string, node: React.ReactNode, basis = "200px") => (
        <div style={{ flex: `1 1 ${basis}`, minWidth: 0 }}>
            <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>{label}</Label>
            {node}
        </div>
    );

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Stock"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="O stock de cada artigo em cada armazém, tal como o PingWin o mostra (lido de noite), com o consumo teórico calculado pela XPLENDOR a partir das vendas e das fichas técnicas."
                />
                <Row>
                    <Col xs={12}>
                        {hidden && <FinancialNote className="mb-3" />}
                        <PageCard
                            title="Stock por artigo"
                            info="Por ordem de valor (quantidade × custo médio): os primeiros artigos são os que mais pesam no stock."
                            loading={loading && !!data}
                            status={<>Stock lido do PingWin: {summary?.last_read_at ? fmtWhen(summary.last_read_at) : "ainda não lido"}</>}
                            actions={<>
                                {cols.selector}
                                <AuditButton companyId={companyId} area="restauracao" origin="stock" onFinished={() => { setAuditKey((k) => k + 1); fetchRows(); }} />
                            </>}
                            filters={
                                <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar (código ou descrição)…" activeCount={activeFilterCount} onClear={clearFilters}>
                                    {filterField("Armazém", <XSelect ariaLabel="Armazém" small options={warehouseOptions} value={warehouse} onChange={setWarehouse} searchable={false} placeholder="Todos os armazéns" />, "200px")}
                                    {filterField("Família", <XSelect ariaLabel="Família" small options={familyOptions} value={family} onChange={setFamily} searchable placeholder="Todas as famílias" />)}
                                    {filterField("Estado", <XSelect ariaLabel="Estado" small options={stateOptions} value={state} onChange={setState} searchable={false} placeholder="Todos os estados" />, "220px")}
                                </RestFilterBar>
                            }
                        >
                            <div className="d-flex flex-wrap gap-4 px-3 py-3 border-bottom" data-testid="stock-summary">
                                {summary ? (
                                    <>
                                        <div>
                                            <div className="text-muted fs-12">Valor do stock{warehouse ? " neste armazém" : ""}</div>
                                            <div className="fs-15 fw-semibold"><StockMoney value={summary.value_eur} hidden={hidden} seed={7} /></div>
                                        </div>
                                        <div>
                                            <div className="text-muted fs-12">Pareto</div>
                                            <div className="fs-15 fw-semibold">Os primeiros {summary.top80} {summary.top80 === 1 ? "artigo representa" : "artigos representam"} 80% do valor</div>
                                        </div>
                                        <div>
                                            <div className="text-muted fs-12">A ver</div>
                                            <div className="fs-15 fw-semibold">
                                                <span className={summary.counts.negativo ? "text-danger" : undefined}>{summary.counts.negativo} com stock negativo</span>
                                                {" · "}{summary.counts.sem_consumo} com stock e sem consumo
                                            </div>
                                        </div>
                                        <div className="text-muted fs-12 align-self-end ms-md-auto text-md-end">
                                            <div>
                                                PCM <InfoTip label="O que é o PCM" text={PCM_TIP} /> · PCU <InfoTip label="O que é o PCU" text={PCU_TIP} /> ·
                                                Consumo teórico <InfoTip label="Como se calcula o consumo teórico" text={CONSUMPTION_TIP} /> ·
                                                Cobertura <InfoTip label="O que são os dias de cobertura" text={COVERAGE_TIP} />
                                            </div>
                                            {summary.window.from && <div>Consumo dos últimos {summary.window.days} dias com vendas lidas: {fmtDate(summary.window.from)} a {fmtDate(summary.window.to)}</div>}
                                            {unassigned.length > 0 && (
                                                <div className="text-warning">
                                                    Sem armazém por omissão: {unassigned.map((s) => s.store_name).join(", ")}. Defina-o em Restauração › Armazéns.
                                                </div>
                                            )}
                                        </div>
                                    </>
                                ) : error ? null : (
                                    <div style={{ width: 420 }}><Skeleton variant="lines" lines={2} label="" /></div>
                                )}
                            </div>
                            {error && !data ? (
                                <div className="p-4 text-center">
                                    <p className="text-danger mb-2">{error}</p>
                                    <Button size="sm" color="outline-primary" onClick={fetchRows}><i className="ri-refresh-line me-1" />Tentar de novo</Button>
                                </div>
                            ) : (
                                <DataTable
                                    columns={cols}
                                    data={rows}
                                    rowKey={(r) => r.product_id}
                                    mode="server"
                                    loading={loading}
                                    onRowClick={open}
                                    rowActions={(r) => (
                                        <Button size="sm" color="outline-primary" onClick={() => open(r)}>
                                            <i className="ri-archive-line me-1" />Ver o stock
                                        </Button>
                                    )}
                                    caption="Stock por ordem de valor"
                                    empty={{ message: noFilters ? "Ainda não há stock lido do PingWin. A leitura corre de noite." : "Nenhum artigo para estes filtros." }}
                                    server={meta ? {
                                        page: meta.current_page, lastPage: meta.last_page, total: meta.total, perPage: meta.per_page,
                                        from: meta.from ?? 0, to: meta.to ?? 0, onPageChange: setPage,
                                        sort: sort ? { id: sort.id, desc: sort.desc } : null,
                                        onSortChange: (next) => setSort(next),
                                    } : undefined}
                                />
                            )}
                        </PageCard>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
