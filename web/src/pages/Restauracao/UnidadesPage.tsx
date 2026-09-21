import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, Container, Row, Col, Spinner, Label } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getPingwinUnits, syncPingwinUnits } from "helpers/laravel_helper";
import { PingwinUnitRow, LaravelPaginator } from "common/models/pingwin.model";

/**
 * XPLENDOR — Restauração › Unidades (Fase 1, só leitura). As unidades do PingWin
 * são a base de conversão (Barril 50lt = 50 Litros) e são um caos lá — aqui a
 * conversão é mostrada LEGÍVEL ("1 Barril 50lt = 50 Litros"). Exibição paginada
 * (Laravel), pesquisa + filtro (ativas/anuladas) em react-select, mobile em cards.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const PER_PAGE = 20;

type ActiveFilter = "active" | "inactive" | "all";
const activeOptions: { value: ActiveFilter; label: string }[] = [
    { value: "active", label: "Ativas" },
    { value: "inactive", label: "Anuladas" },
    { value: "all", label: "Todas" },
];

export default function UnidadesPage() {
    document.title = "Unidades | Restauração | Xplendor";
    const isMobile = useIsMobile();

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<Omit<LaravelPaginator<PingwinUnitRow>, "data"> | null>(null);
    const [rows, setRows] = useState<PingwinUnitRow[]>([]);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState("");
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>("active"); // por defeito só ativas

    const activeFilterCount = [activeFilter !== "active"].filter(Boolean).length;

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinUnits(companyId, {
                page,
                perPage: PER_PAGE,
                search: search.trim() || undefined,
                active: activeFilter === "active" ? 1 : activeFilter === "inactive" ? 0 : undefined,
            });
            const paginator = res?.data?.units;
            setRows(paginator?.data ?? []);
            const { data: _omit, ...m } = paginator ?? {};
            setMeta(paginator ? (m as any) : null);
            setLastSynced(res?.data?.last_synced_at ?? null);
        } catch {
            setRows([]);
            setMeta(null);
        } finally {
            setLoading(false);
        }
    }, [companyId, page, search, activeFilter]);

    useEffect(() => {
        const t = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(t);
    }, [fetchRows]);

    useEffect(() => { setPage(1); }, [search, activeFilter]);

    const clearFilters = () => { setSearch(""); setActiveFilter("active"); };

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinUnits(companyId);
            toast.info("A sincronizar unidades… vais ser notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar as unidades.");
        } finally {
            setSyncing(false);
        }
    };

    const emptyRow = (
        <div className="text-center text-muted py-4">
            {!search && activeFilter === "active"
                ? <>Sem unidades. Usa <strong>“Sincronizar”</strong> para as obter do PingWin.</>
                : "Nenhum resultado para o filtro."}
        </div>
    );

    const Conversion = ({ u }: { u: PingwinUnitRow }) =>
        u.conversion_label
            ? <span className="badge bg-primary-subtle text-primary">{u.conversion_label}</span>
            : <span className="text-muted">Unidade base</span>;

    const Uses = ({ u }: { u: PingwinUnitRow }) => (
        <div className="d-flex gap-1 flex-wrap">
            {u.purchase && <span className="badge bg-info-subtle text-info">Compra</span>}
            {u.sale && <span className="badge bg-success-subtle text-success">Venda</span>}
            {u.stock && <span className="badge bg-secondary-subtle text-secondary">Stock</span>}
            {!u.purchase && !u.sale && !u.stock && <span className="text-muted">—</span>}
        </div>
    );

    const filterFields = (
        <div style={{ flex: "1 1 180px", minWidth: 0 }}>
            <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Estado</Label>
            <Select
                styles={reactSelectTheme}
                menuPortalTarget={document.body}
                options={activeOptions}
                value={activeOptions.find((o) => o.value === activeFilter) ?? activeOptions[0]}
                onChange={(o: any) => setActiveFilter(o?.value ?? "active")}
                isSearchable={false}
            />
        </div>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                            <div>
                                <h4 className="mb-sm-0">Unidades</h4>
                                <small className="text-muted">Unidades e conversões do PingWin (só leitura). Última sincronização: {fmtDateTime(lastSynced)}</small>
                            </div>
                            <button className="btn btn-primary" onClick={runSync} disabled={syncing}>
                                {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                            </button>
                        </div>
                    </Col>
                </Row>

                <Row>
                    <Col xs={12}>
                        <Card className="mb-3">
                            <div className="card-header">
                                <div className="d-flex flex-column gap-3">
                                    <h5 className="card-title mb-0">Unidades {loading && <Spinner size="sm" className="ms-1" />}</h5>
                                    <RestFilterBar
                                        search={search}
                                        onSearchChange={setSearch}
                                        searchPlaceholder="Pesquisar (nome ou abreviatura)…"
                                        activeCount={activeFilterCount}
                                        onClear={clearFilters}
                                    >
                                        {filterFields}
                                    </RestFilterBar>
                                </div>
                            </div>

                            {isMobile ? (
                                <div className="p-3 d-flex flex-column gap-2">
                                    {!loading && rows.length === 0 ? emptyRow : rows.map((u) => (
                                        <div key={u.id} style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)" }} className={u.is_active ? "" : "opacity-75"}>
                                            <div className="d-flex align-items-start justify-content-between gap-2 mb-1">
                                                <div style={{ minWidth: 0 }}>
                                                    <div className="fw-semibold text-body text-truncate">
                                                        {u.description || "—"}
                                                        {u.shortname && <span className="badge bg-light text-muted ms-2">{u.shortname}</span>}
                                                    </div>
                                                </div>
                                                {!u.is_active && <span className="badge bg-secondary-subtle text-secondary flex-shrink-0">Anulada</span>}
                                            </div>
                                            <div className="mt-1"><Conversion u={u} /></div>
                                            <div className="mt-2"><Uses u={u} /></div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-bordered table-hover align-middle mb-0">
                                        <thead className="text-muted table-light">
                                            <tr>
                                                <th>Unidade</th>
                                                <th>Abrev.</th>
                                                <th>Conversão</th>
                                                <th>Usos</th>
                                                <th className="text-center">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {!loading && rows.length === 0 ? (
                                                <tr><td colSpan={5}>{emptyRow}</td></tr>
                                            ) : rows.map((u) => (
                                                <tr key={u.id} className={u.is_active ? "" : "text-muted"}>
                                                    <td className="fw-medium">
                                                        {u.description || "—"}
                                                        {!u.is_global && <span className="badge bg-warning-subtle text-warning ms-2" title="Específica de um artigo">Artigo</span>}
                                                    </td>
                                                    <td>{u.shortname || "—"}</td>
                                                    <td><Conversion u={u} /></td>
                                                    <td><Uses u={u} /></td>
                                                    <td className="text-center">
                                                        {u.is_active
                                                            ? <span className="badge bg-success-subtle text-success">Ativa</span>
                                                            : <span className="badge bg-secondary-subtle text-secondary">Anulada</span>}
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
                                currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total}
                                perPage={meta.per_page} from={meta.from ?? 0} to={meta.to ?? 0}
                                onPageChange={(p) => setPage(p)}
                            />
                        )}
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
