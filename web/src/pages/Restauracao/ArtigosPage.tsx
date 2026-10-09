import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Container, Row, Col, Spinner, Label } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getPingwinCatalog, syncPingwinCatalog } from "helpers/laravel_helper";
import { PingwinCatalogItem, LaravelPaginator } from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Artigos (Fase 1, só leitura). Catálogo de artigos
 * (produtos) do PingWin. São MUITOS → exibição PAGINADA (paginação Laravel).
 * Filtros com react-select (padrão do sistema), off-canvas em mobile, e a tabela
 * colapsa em cards no telemóvel (sem overflow horizontal). Só módulo pingwin.
 *
 * UI-2a: PageCard + DataTable em modo SERVIDOR (o catálogo tem mais de mil artigos e a API já
 * pagina e pesquisa). A API ainda não ordena: as colunas não são ordenáveis até lá.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

/** Cêntimos inteiros → "12,34 €" (ou "—" se null). */
const fmtCents = (c?: number | null) =>
    c === null || c === undefined ? "—" : (c / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });

const PER_PAGE = 20;

type SaleFilter = "" | "sale" | "purchase";
type Opt = { value: string; label: string };

const saleOptions: { value: SaleFilter; label: string }[] = [
    { value: "", label: "Todos" },
    { value: "sale", label: "À venda" },
    { value: "purchase", label: "Para compra" },
];

const YesNo = ({ v, color }: { v: boolean; color: string }) =>
    v ? <span className={`badge bg-${color}-subtle text-${color}`}>Sim</span> : <span className="text-muted">—</span>;

export default function ArtigosPage() {
    document.title = "Artigos | Restauração | Xplendor";
    const navigate = useNavigate();

    // ⚠️ Abrir um artigo: navega com o pingwin_id no URL (para LER) + o id local no
    // state (catalogItemId, para EDITAR/ANULAR). São ids diferentes (ver ArtigoFormPage).
    const openArticle = (a: PingwinCatalogItem) =>
        navigate(`/restauracao/artigos/${a.pingwin_id}`, { state: { catalogItemId: a.id } });

    const companyId = useWorkingCompanyId();

    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<Omit<LaravelPaginator<PingwinCatalogItem>, "data"> | null>(null);
    const [rows, setRows] = useState<PingwinCatalogItem[]>([]);
    const [families, setFamilies] = useState<string[]>([]);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState("");
    const [familyFilter, setFamilyFilter] = useState("");
    const [saleFilter, setSaleFilter] = useState<SaleFilter>("");

    const familyOptions: Opt[] = useMemo(
        () => [{ value: "", label: "Todas as famílias" }, ...families.map((f) => ({ value: f, label: f }))],
        [families]
    );

    const activeFilterCount = [!!familyFilter, saleFilter !== ""].filter(Boolean).length;

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinCatalog(companyId, {
                page,
                perPage: PER_PAGE,
                search: search.trim() || undefined,
                family: familyFilter || undefined,
                forsale: saleFilter === "sale" ? 1 : undefined,
                forpurchase: saleFilter === "purchase" ? 1 : undefined,
            });
            const paginator = res?.data?.articles;
            setRows(paginator?.data ?? []);
            const { data: _omit, ...m } = paginator ?? {};
            setMeta(paginator ? (m as any) : null);
            setFamilies(res?.data?.families ?? []);
            setLastSynced(res?.data?.last_synced_at ?? null);
        } catch {
            setRows([]);
            setMeta(null);
        } finally {
            setLoading(false);
        }
    }, [companyId, page, search, familyFilter, saleFilter]);

    useEffect(() => {
        const t = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(t);
    }, [fetchRows]);

    useEffect(() => { setPage(1); }, [search, familyFilter, saleFilter]);

    const clearFilters = () => { setSearch(""); setFamilyFilter(""); setSaleFilter(""); };

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinCatalog(companyId);
            toast.info("A sincronizar artigos… será notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar os artigos.");
        } finally {
            setSyncing(false);
        }
    };

    const noFilters = !search && !familyFilter && !saleFilter;
    const emptyMessage = noFilters ? "Ainda não há artigos. Sincronize para os obter do PingWin." : "Nenhum resultado para o filtro.";

    // Campos de filtro (react-select) — partilhados entre desktop e off-canvas mobile.
    const filterFields = (
        <>
            <div style={{ flex: "1 1 200px", minWidth: 0 }}>
                <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Família</Label>
                <XSelect
                    ariaLabel="Família"
                    small
                    options={familyOptions}
                    value={familyFilter}
                    onChange={(v) => setFamilyFilter(v)}
                    searchable
                    placeholder="Todas as famílias"
                />
            </div>
            <div style={{ flex: "1 1 160px", minWidth: 0 }}>
                <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Tipo</Label>
                <XSelect<SaleFilter>
                    ariaLabel="Tipo"
                    small
                    options={saleOptions}
                    value={saleFilter}
                    onChange={(v) => setSaleFilter(v)}
                    searchable={false}
                    placeholder="Todos"
                />
            </div>
        </>
    );

    const columns: DTColumn<PingwinCatalogItem>[] = [
        { id: "code", header: "Código", value: (a) => a.code, cell: (a) => <span className="fw-medium">{a.code || "—"}</span>, mobile: "subtitle" },
        {
            id: "description", header: "Descrição", value: (a) => a.description, mobile: "title",
            cell: (a) => <>{a.description || "—"}{!a.is_active && <span className="badge bg-secondary-subtle text-secondary ms-2">Inativo</span>}</>,
        },
        { id: "family", header: "Família", value: (a) => a.family, cell: (a) => (a.family ? <span className="badge bg-info-subtle text-info">{a.family}</span> : <span className="text-muted">—</span>) },
        { id: "sale_price", header: "Preço venda", value: (a) => a.saleprice_cents, cell: (a) => fmtCents(a.saleprice_cents), align: "end", nowrap: true },
        { id: "purchase_price", header: "Preço compra", value: (a) => a.purchaseprice_cents, cell: (a) => fmtCents(a.purchaseprice_cents), align: "end", nowrap: true },
        { id: "forsale", header: "Venda", value: (a) => (a.forsale ? 1 : 0), cell: (a) => <YesNo v={a.forsale} color="success" />, align: "center" },
        { id: "forpurchase", header: "Compra", value: (a) => (a.forpurchase ? 1 : 0), cell: (a) => <YesNo v={a.forpurchase} color="primary" />, align: "center" },
        { id: "bom", header: "Ficha", value: (a) => (a.has_bom ? 1 : 0), cell: (a) => <YesNo v={a.has_bom} color="warning" />, align: "center" },
    ];
    const cols = useDataColumns("restauracao.artigos", columns);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Artigos"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="O catálogo de artigos do PingWin."
                />

                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Catálogo"
                            loading={loading && rows.length > 0}
                            status={<>Última sincronização: {fmtDateTime(lastSynced)}</>}
                            actions={<>
                                {cols.selector}
                                <Button color="outline-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                                </Button>
                                <Button color="primary" onClick={() => navigate("/restauracao/artigos/novo")}>
                                    <i className="ri-add-line me-1" /> Novo artigo
                                </Button>
                            </>}
                            filters={
                                <RestFilterBar
                                    search={search}
                                    onSearchChange={setSearch}
                                    searchPlaceholder="Pesquisar (código ou descrição)…"
                                    activeCount={activeFilterCount}
                                    onClear={clearFilters}
                                >
                                    {filterFields}
                                </RestFilterBar>
                            }
                        >
                            <DataTable
                                columns={cols}
                                data={rows}
                                rowKey={(a) => a.id}
                                mode="server"
                                loading={loading}
                                rowClassName={(a) => (a.is_active ? undefined : "text-muted")}
                                onRowClick={openArticle}
                                rowActions={(a) => (
                                    <Button size="sm" color="outline-primary" onClick={() => openArticle(a)}>
                                        <i className="ri-pencil-line me-1" /> Abrir
                                    </Button>
                                )}
                                caption="Catálogo de artigos"
                                empty={{
                                    message: emptyMessage,
                                    action: noFilters ? <Button color="outline-primary" size="sm" onClick={runSync} disabled={syncing}><i className="ri-refresh-line me-1" />Sincronizar</Button> : undefined,
                                }}
                                server={meta ? {
                                    page: meta.current_page, lastPage: meta.last_page, total: meta.total, perPage: meta.per_page,
                                    from: meta.from ?? 0, to: meta.to ?? 0, onPageChange: setPage,
                                } : undefined}
                            />
                        </PageCard>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
