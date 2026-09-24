import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Card, Container, Row, Col, Spinner, Label } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getPingwinCatalog, syncPingwinCatalog } from "helpers/laravel_helper";
import { PingwinCatalogItem, LaravelPaginator } from "common/models/pingwin.model";

/**
 * XPLENDOR — Restauração › Artigos (Fase 1, só leitura). Catálogo de artigos
 * (produtos) do PingWin. São MUITOS → exibição PAGINADA (paginação Laravel).
 * Filtros com react-select (padrão do sistema), off-canvas em mobile, e a tabela
 * colapsa em cards no telemóvel (sem overflow horizontal). Só módulo pingwin.
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
    const isMobile = useIsMobile();
    const navigate = useNavigate();

    // ⚠️ Abrir um artigo: navega com o pingwin_id no URL (para LER) + o id local no
    // state (catalogItemId, para EDITAR/ANULAR). São ids diferentes (ver ArtigoFormPage).
    const openArticle = (a: PingwinCatalogItem) =>
        navigate(`/restauracao/artigos/${a.pingwin_id}`, { state: { catalogItemId: a.id } });

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

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
            toast.info("A sincronizar artigos… vais ser notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar os artigos.");
        } finally {
            setSyncing(false);
        }
    };

    const emptyRow = (
        <div className="text-center text-muted py-4">
            {!search && !familyFilter && !saleFilter
                ? <>Sem artigos. Usa <strong>“Sincronizar”</strong> para os obter do PingWin.</>
                : "Nenhum resultado para o filtro."}
        </div>
    );

    // Campos de filtro (react-select) — partilhados entre desktop e off-canvas mobile.
    const filterFields = (
        <>
            <div style={{ flex: "1 1 200px", minWidth: 0 }}>
                <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Família</Label>
                <Select
                    styles={reactSelectTheme}
                    menuPortalTarget={document.body}
                    options={familyOptions}
                    value={familyOptions.find((o) => o.value === familyFilter) ?? familyOptions[0]}
                    onChange={(o: any) => setFamilyFilter(o?.value ?? "")}
                    isSearchable
                    placeholder="Todas as famílias"
                />
            </div>
            <div style={{ flex: "1 1 160px", minWidth: 0 }}>
                <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Tipo</Label>
                <Select
                    styles={reactSelectTheme}
                    menuPortalTarget={document.body}
                    options={saleOptions}
                    value={saleOptions.find((o) => o.value === saleFilter) ?? saleOptions[0]}
                    onChange={(o: any) => setSaleFilter(o?.value ?? "")}
                    isSearchable={false}
                    placeholder="Todos"
                />
            </div>
        </>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                            <div>
                                <h4 className="mb-sm-0">Artigos</h4>
                                <small className="text-muted">Catálogo de artigos do PingWin (só leitura). Última sincronização: {fmtDateTime(lastSynced)}</small>
                            </div>
                            <div className="d-flex gap-2">
                                <button className="btn btn-soft-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                                </button>
                                <button className="btn btn-primary" onClick={() => navigate("/restauracao/artigos/novo")}>
                                    <i className="ri-add-line me-1" /> Novo artigo
                                </button>
                            </div>
                        </div>
                    </Col>
                </Row>

                <Row>
                    <Col xs={12}>
                        <Card className="mb-3">
                            <div className="card-header">
                                <div className="d-flex flex-column gap-3">
                                    <h5 className="card-title mb-0">Catálogo {loading && <Spinner size="sm" className="ms-1" />}</h5>
                                    <RestFilterBar
                                        search={search}
                                        onSearchChange={setSearch}
                                        searchPlaceholder="Pesquisar (código ou descrição)…"
                                        activeCount={activeFilterCount}
                                        onClear={clearFilters}
                                    >
                                        {filterFields}
                                    </RestFilterBar>
                                </div>
                            </div>

                            {/* MOBILE: cards empilhados (sem overflow horizontal). */}
                            {isMobile ? (
                                <div className="p-3 d-flex flex-column gap-2">
                                    {!loading && rows.length === 0 ? emptyRow : rows.map((a) => (
                                        <div key={a.id} onClick={() => openArticle(a)} role="button" style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)", cursor: "pointer" }} className={a.is_active ? "" : "opacity-75"}>
                                            <div className="d-flex align-items-start justify-content-between gap-2 mb-1">
                                                <div style={{ minWidth: 0 }}>
                                                    <div className="fw-semibold text-body text-truncate">{a.description || "—"}</div>
                                                    <div className="text-muted fs-12">{a.code || "—"}</div>
                                                </div>
                                                {a.family && <span className="badge bg-info-subtle text-info flex-shrink-0">{a.family}</span>}
                                            </div>
                                            <div className="d-flex align-items-center justify-content-between mt-2">
                                                <div className="d-flex gap-3">
                                                    <div><div className="text-muted fs-11 text-uppercase">Venda</div><div className="fw-semibold fs-13">{fmtCents(a.saleprice_cents)}</div></div>
                                                    <div><div className="text-muted fs-11 text-uppercase">Compra</div><div className="fw-semibold fs-13">{fmtCents(a.purchaseprice_cents)}</div></div>
                                                </div>
                                                <div className="d-flex gap-1 flex-wrap justify-content-end">
                                                    {a.forsale && <span className="badge bg-success-subtle text-success">Venda</span>}
                                                    {a.forpurchase && <span className="badge bg-primary-subtle text-primary">Compra</span>}
                                                    {a.has_bom && <span className="badge bg-warning-subtle text-warning">Ficha</span>}
                                                    {!a.is_active && <span className="badge bg-secondary-subtle text-secondary">Inativo</span>}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-bordered table-hover align-middle mb-0">
                                        <thead className="text-muted table-light">
                                            <tr>
                                                <th>Código</th>
                                                <th>Descrição</th>
                                                <th>Família</th>
                                                <th className="text-end">Preço venda</th>
                                                <th className="text-end">Preço compra</th>
                                                <th className="text-center">Venda</th>
                                                <th className="text-center">Compra</th>
                                                <th className="text-center">Ficha</th>
                                                <th className="text-end">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {!loading && rows.length === 0 ? (
                                                <tr><td colSpan={9}>{emptyRow}</td></tr>
                                            ) : rows.map((a) => (
                                                <tr key={a.id} className={a.is_active ? "" : "text-muted"}>
                                                    <td className="fw-medium">{a.code || "—"}</td>
                                                    <td>
                                                        {a.description || "—"}
                                                        {!a.is_active && <span className="badge bg-secondary-subtle text-secondary ms-2">Inativo</span>}
                                                    </td>
                                                    <td>{a.family ? <span className="badge bg-info-subtle text-info">{a.family}</span> : <span className="text-muted">—</span>}</td>
                                                    <td className="text-end">{fmtCents(a.saleprice_cents)}</td>
                                                    <td className="text-end">{fmtCents(a.purchaseprice_cents)}</td>
                                                    <td className="text-center"><YesNo v={a.forsale} color="success" /></td>
                                                    <td className="text-center"><YesNo v={a.forpurchase} color="primary" /></td>
                                                    <td className="text-center"><YesNo v={a.has_bom} color="warning" /></td>
                                                    <td className="text-end">
                                                        <button className="btn btn-sm btn-soft-primary" onClick={() => openArticle(a)}>
                                                            <i className="ri-pencil-line me-1" /> Abrir
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Card>

                        {meta && meta.total > 0 && (
                            <Pagination
                                currentPage={meta.current_page}
                                lastPage={meta.last_page}
                                total={meta.total}
                                perPage={meta.per_page}
                                from={meta.from ?? 0}
                                to={meta.to ?? 0}
                                onPageChange={(p) => setPage(p)}
                            />
                        )}
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
