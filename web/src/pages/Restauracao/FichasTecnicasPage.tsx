import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Container, Row, Col, Label } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import Skeleton from "Components/Common/Skeleton";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { getRecipes } from "helpers/laravel_helper";
import { RecipeListResponse, RecipeRow } from "common/models/recipe.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { COST_SOURCES_TEXT, Money, StateBadge, fmtDate, fmtPct, fmtQty } from "./fichas/recipeFormat";
import AuditButton from "pages/Auditoria/AuditButton";
import AuditCountCell, { useAuditCounts } from "pages/Auditoria/AuditCountCell";

/**
 * XPLENDOR — Restauração › Fichas técnicas (Fatia 1, só leitura). Os artigos vendidos nos últimos
 * 90 dias e os outros com ficha, por Pareto das vendas: estado da ficha, custo XPLENDOR, preço sem
 * IVA, food cost e margem. A ordenação, os filtros e a página pedem-se ao servidor. Os euros só
 * aparecem a quem vê as Finanças (o servidor não os envia aos outros).
 */

const PER_PAGE = 25;
type Opt = { value: string; label: string };
type Sort = { id: string; key: string; desc: boolean } | null;

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d.replace(" ", "T") + (d.includes("T") ? "" : "Z")).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "ainda não sincronizadas";

export default function FichasTecnicasPage() {
    document.title = "Fichas técnicas | Restauração | Xplendor";
    const navigate = useNavigate();
    const companyId = useWorkingCompanyId();

    const [data, setData] = useState<RecipeListResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [family, setFamily] = useState("");
    const [location, setLocation] = useState("");
    const [state, setState] = useState("");
    const [sort, setSort] = useState<Sort>(null);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        setError(null);
        try {
            const res: any = await getRecipes(companyId, {
                page, perPage: PER_PAGE,
                search: search.trim() || undefined,
                family: family || undefined,
                location_id: location ? Number(location) : undefined,
                state: state || undefined,
                sort: sort?.key,
                dir: sort ? (sort.desc ? "desc" : "asc") : undefined,
            });
            setData(res?.data ?? null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível carregar as fichas técnicas.");
        } finally {
            setLoading(false);
        }
    }, [companyId, page, search, family, location, state, sort]);

    useEffect(() => {
        const t = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(t);
    }, [fetchRows]);
    useEffect(() => { setPage(1); }, [search, family, location, state, sort]);

    const hidden = data?.financial?.visible === false;
    const rows = data?.recipes?.data ?? [];
    const meta = data?.recipes;
    const summary = data?.summary;

    const familyOptions: Opt[] = useMemo(() => [{ value: "", label: "Todas as famílias" }, ...(data?.families ?? []).map((f) => ({ value: f, label: f }))], [data?.families]);
    const locationOptions: Opt[] = useMemo(() => [{ value: "", label: "Todas as lojas" }, ...(data?.locations ?? []).map((l) => ({ value: String(l.id), label: l.name }))], [data?.locations]);
    const stateOptions: Opt[] = useMemo(() => [{ value: "", label: "Todos os estados" }, ...(data?.states ?? []).map((s) => ({ value: s.id, label: s.label }))], [data?.states]);

    const open = (r: RecipeRow) => navigate(`/restauracao/fichas-tecnicas/${r.product_id}`);
    // Auditoria: a coluna com os achados abertos de cada artigo (da página que se vê).
    const [auditKey, setAuditKey] = useState(0);
    const auditCounts = useAuditCounts(companyId, "artigo", rows.map((r) => r.product_id), auditKey);

    const euroSort = (key: string) => (hidden ? undefined : key);
    const columns: DTColumn<RecipeRow>[] = [
        {
            id: "description", header: "Artigo", sortKey: "description", value: (r) => r.description, mobile: "title", hideable: false,
            cell: (r) => (
                <div style={{ minWidth: 160 }}>
                    <span className="fw-medium">{r.description}</span>
                    {r.code && <div className="text-muted fs-12">{r.code}</div>}
                </div>
            ),
        },
        { id: "family", header: "Família", sortKey: "family", value: (r) => r.family, defaultVisible: false, cell: (r) => (r.family ? <span className="fs-13">{r.family}</span> : <span className="text-muted">—</span>) },
        { id: "qty", header: "Quantidade", sortKey: "qty", value: (r) => r.qty, cell: (r) => fmtQty(r.qty, 0), align: "end", nowrap: true },
        { id: "sales", header: "Vendas", sortKey: "pareto", value: (r) => r.sales_eur, cell: (r) => <Money value={r.sales_eur} hidden={hidden && r.qty > 0} decimals={0} seed={r.pareto} />, align: "end", nowrap: true },
        { id: "share", header: "% vendas", sortKey: "share", value: (r) => r.share_pct, cell: (r) => fmtPct(r.share_pct, 2), align: "end", nowrap: true },
        { id: "cum", header: "% acumulada", value: (r) => r.cum_pct, sortable: false, defaultVisible: false, cell: (r) => fmtPct(r.cum_pct, 1), align: "end", nowrap: true },
        {
            id: "state", header: "Estado", sortKey: "state", value: (r) => r.state_label, mobile: "subtitle",
            cell: (r) => (
                <div className="d-flex flex-wrap align-items-center gap-1" style={{ minWidth: 150 }}>
                    <StateBadge state={r.state} label={r.state_label} title={r.missing_stores.length ? `Sem versão em vigor: ${r.missing_stores.join(", ")}` : undefined} />
                    {r.flag_labels.map((f) => <span key={f} className="badge bg-warning-subtle text-warning text-wrap text-start" data-testid="recipe-flag">{f}</span>)}
                    {r.warnings > 0 && <span className="badge bg-light text-body fs-11" title="Avisos no detalhe"><i className="ri-alert-line me-1" />{r.warnings}</span>}
                </div>
            ),
        },
        { id: "components", header: "Componentes", sortKey: "components", value: (r) => r.components, defaultVisible: false, cell: (r) => r.components ?? <span className="text-muted">—</span>, align: "end" },
        {
            id: "cost", header: "Custo", sortKey: euroSort("cost"), value: (r) => r.cost_eur, align: "end", nowrap: true,
            cell: (r) => (
                <>
                    <Money value={r.cost_eur} hidden={hidden && !!r.components} decimals={2} seed={r.pareto + 1} />
                    {r.cost_complete === false && (r.cost_eur !== null || (hidden && !!r.components)) ? <div className="text-warning fs-11">parcial</div> : null}
                    {r.low_confidence && <div className="text-warning fs-11">baixa confiança</div>}
                    {r.yield?.confirmed && <div className="text-muted fs-11">lote de {fmtQty(r.yield.quantity, 3)} {r.yield.unit_label}, <Money value={r.cost_per_unit_eur} hidden={hidden} decimals={4} seed={r.pareto + 4} /> cada</div>}
                </>
            ),
        },
        { id: "price", header: "Preço sem IVA", sortKey: euroSort("price"), value: (r) => r.price_eur, cell: (r) => <Money value={r.price_eur} hidden={hidden && r.qty > 0} seed={r.pareto + 2} />, align: "end", nowrap: true },
        {
            id: "food_cost", header: "Food cost", sortKey: "food_cost", value: (r) => r.food_cost_pct, align: "end", nowrap: true,
            cell: (r) => (r.food_cost_pct === null ? <span className="text-muted">—</span> : <span className={r.cost_complete === false ? "text-warning" : undefined}>{fmtPct(r.food_cost_pct)}</span>),
        },
        { id: "margin", header: "Margem", sortKey: euroSort("margin"), value: (r) => r.margin_eur, cell: (r) => <Money value={r.margin_eur} hidden={hidden && !!r.components && r.qty > 0} seed={r.pareto + 3} />, align: "end", nowrap: true },
        { id: "valid_from", header: "Em vigor desde", sortKey: "valid_from", value: (r) => r.valid_from, cell: (r) => (r.valid_from ? fmtDate(r.valid_from) : <span className="text-muted">—</span>), nowrap: true },
        {
            id: "stores", header: "Lojas", sortable: false, value: (r) => r.stores.join(", "), defaultVisible: false,
            cell: (r) => (r.stores.length ? <div className="d-flex flex-wrap gap-1">{r.stores.map((s) => <span key={s} className="badge bg-info-subtle text-info">{s}</span>)}</div> : <span className="text-muted">—</span>),
        },
        {
            id: "audit", header: "Auditoria", sortable: false, value: (r) => auditCounts[r.product_id]?.open ?? 0, align: "center",
            cell: (r) => <AuditCountCell counts={auditCounts} entityType="artigo" entityId={r.product_id} label={r.description} />,
        },
    ];
    const cols = useDataColumns("restauracao.fichas-tecnicas", columns);

    const activeFilterCount = [!!family, !!location, !!state].filter(Boolean).length;
    const clearFilters = () => { setSearch(""); setFamily(""); setLocation(""); setState(""); };
    const noFilters = !search && !family && !location && !state;

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
                    title="Fichas técnicas"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="As fichas técnicas do PingWin (só leitura), com o custo calculado pela XPLENDOR a partir das compras, por ordem de vendas."
                />
                <Row>
                    <Col xs={12}>
                        {hidden && <FinancialNote className="mb-3" />}
                        <PageCard
                            title="Artigos e fichas"
                            info={COST_SOURCES_TEXT}
                            loading={loading && !!data}
                            status={<>Fichas lidas do PingWin: {fmtDateTime(summary?.last_synced_at)}</>}
                            actions={<>
                                {cols.selector}
                                <AuditButton companyId={companyId} area="restauracao" origin="fichas" onFinished={() => setAuditKey((k) => k + 1)} />
                            </>}
                            filters={
                                <RestFilterBar
                                    search={search}
                                    onSearchChange={setSearch}
                                    searchPlaceholder="Pesquisar (código ou descrição)…"
                                    activeCount={activeFilterCount}
                                    onClear={clearFilters}
                                >
                                    {filterField("Família", <XSelect ariaLabel="Família" small options={familyOptions} value={family} onChange={setFamily} searchable placeholder="Todas as famílias" />)}
                                    {filterField("Loja", <XSelect ariaLabel="Loja" small options={locationOptions} value={location} onChange={setLocation} searchable={false} placeholder="Todas as lojas" />, "160px")}
                                    {filterField("Estado", <XSelect ariaLabel="Estado" small options={stateOptions} value={state} onChange={setState} searchable={false} placeholder="Todos os estados" />, "180px")}
                                </RestFilterBar>
                            }
                        >
                            <div className="d-flex flex-wrap gap-4 px-3 py-3 border-bottom" data-testid="recipes-summary">
                                {summary ? (
                                    <>
                                        <div>
                                            <div className="text-muted fs-12">Cobertura das vendas</div>
                                            <div className="fs-15 fw-semibold">As fichas cobrem {fmtPct(summary.coverage_pct)} das vendas</div>
                                        </div>
                                        <div>
                                            <div className="text-muted fs-12">Top {summary.top_share}% das vendas</div>
                                            <div className={`fs-15 fw-semibold ${summary.top_without_bom > 0 ? "text-warning" : ""}`}>
                                                {summary.top_without_bom} {summary.top_without_bom === 1 ? "artigo" : "artigos"} do top {summary.top_share}% sem ficha
                                            </div>
                                        </div>
                                        <div className="text-muted fs-12 align-self-end ms-md-auto">
                                            Quantidade e vendas dos últimos {summary.window_days} dias: {fmtDate(summary.since)} a {fmtDate(summary.until)}
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
                                            <i className="ri-file-list-3-line me-1" />Ver ficha
                                        </Button>
                                    )}
                                    caption="Fichas técnicas por ordem de vendas"
                                    empty={{ message: noFilters ? "Ainda não há fichas técnicas nem vendas nos últimos 90 dias. As fichas são lidas do PingWin de noite." : "Nenhum artigo para estes filtros." }}
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
