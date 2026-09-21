import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, Container, Row, Col, Spinner, Label } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getPingwinSuppliers, syncPingwinSuppliers } from "helpers/laravel_helper";
import { PingwinSupplier, LaravelPaginator } from "common/models/pingwin.model";

/**
 * XPLENDOR — Restauração › Fornecedores (Fase 1, só leitura). Fornecedores do
 * PingWin numa tabela limpa e filtrável. Exibição PAGINADA (paginação Laravel).
 * Filtro com react-select (padrão do sistema), off-canvas em mobile, e a tabela
 * colapsa em cards no telemóvel (sem overflow horizontal). Só módulo pingwin.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const PER_PAGE = 20;

/** Morada legível: "Rua … · 4200-232 Porto" (junta morada + código postal + localidade). */
const fmtAddress = (s: PingwinSupplier): string => {
    const line2 = [s.postal_code, s.city].filter(Boolean).join(" ");
    return [s.address, line2].filter(Boolean).join(" · ") || "—";
};

type ActiveFilter = "" | "active" | "inactive";
const activeOptions: { value: ActiveFilter; label: string }[] = [
    { value: "", label: "Todos" },
    { value: "active", label: "Ativos" },
    { value: "inactive", label: "Inativos" },
];

export default function FornecedoresPage() {
    document.title = "Fornecedores | Restauração | Xplendor";
    const isMobile = useIsMobile();

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<Omit<LaravelPaginator<PingwinSupplier>, "data"> | null>(null);
    const [rows, setRows] = useState<PingwinSupplier[]>([]);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState("");
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>("");

    const activeFilterCount = [activeFilter !== ""].filter(Boolean).length;

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinSuppliers(companyId, {
                page,
                perPage: PER_PAGE,
                search: search.trim() || undefined,
                active: activeFilter === "active" ? 1 : activeFilter === "inactive" ? 0 : undefined,
            });
            const paginator = res?.data?.suppliers;
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

    const clearFilters = () => { setSearch(""); setActiveFilter(""); };

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinSuppliers(companyId);
            toast.info("A sincronizar fornecedores… vais ser notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar os fornecedores.");
        } finally {
            setSyncing(false);
        }
    };

    const emptyRow = (
        <div className="text-center text-muted py-4">
            {!search && !activeFilter
                ? <>Sem fornecedores. Usa <strong>“Sincronizar”</strong> para os obter do PingWin.</>
                : "Nenhum resultado para o filtro."}
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
                onChange={(o: any) => setActiveFilter(o?.value ?? "")}
                isSearchable={false}
                placeholder="Todos"
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
                                <h4 className="mb-sm-0">Fornecedores</h4>
                                <small className="text-muted">Fornecedores do PingWin (só leitura). Última sincronização: {fmtDateTime(lastSynced)}</small>
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
                                    <h5 className="card-title mb-0">Fornecedores {loading && <Spinner size="sm" className="ms-1" />}</h5>
                                    <RestFilterBar
                                        search={search}
                                        onSearchChange={setSearch}
                                        searchPlaceholder="Pesquisar (nome, código ou NIF)…"
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
                                    {!loading && rows.length === 0 ? emptyRow : rows.map((s) => (
                                        <div key={s.id} style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)" }} className={s.is_active ? "" : "opacity-75"}>
                                            <div className="d-flex align-items-start justify-content-between gap-2 mb-1">
                                                <div style={{ minWidth: 0 }}>
                                                    <div className="fw-semibold text-body text-truncate">{s.name || "—"}</div>
                                                    {s.fiscal_name && s.fiscal_name !== s.name && (
                                                        <div className="text-muted fs-12 text-truncate">Fiscal: {s.fiscal_name}</div>
                                                    )}
                                                    <div className="text-muted fs-12">{s.code || "—"}{s.tax_number ? ` · NIF ${s.tax_number}` : ""}</div>
                                                </div>
                                                {!s.is_active && <span className="badge bg-secondary-subtle text-secondary flex-shrink-0">Inativo</span>}
                                            </div>
                                            {(s.address || s.city || s.postal_code) && (
                                                <div className="text-muted fs-12 mt-1">
                                                    <i className="ri-map-pin-line me-1" />{fmtAddress(s)}
                                                </div>
                                            )}
                                            {(s.phone || s.email) && (
                                                <div className="text-muted fs-12 mt-1">
                                                    {[s.phone, s.email].filter(Boolean).join(" · ")}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-bordered table-hover align-middle mb-0">
                                        <thead className="text-muted table-light">
                                            <tr>
                                                <th>Código</th>
                                                <th>Nome</th>
                                                <th>NIF</th>
                                                <th>Morada</th>
                                                <th>Contacto</th>
                                                <th className="text-center">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {!loading && rows.length === 0 ? (
                                                <tr><td colSpan={6}>{emptyRow}</td></tr>
                                            ) : rows.map((s) => (
                                                <tr key={s.id} className={s.is_active ? "" : "text-muted"}>
                                                    <td className="fw-medium">{s.code || "—"}</td>
                                                    <td>
                                                        <div>{s.name || "—"}</div>
                                                        {s.fiscal_name && s.fiscal_name !== s.name && (
                                                            <div className="text-muted fs-12">Fiscal: {s.fiscal_name}</div>
                                                        )}
                                                    </td>
                                                    <td>{s.tax_number || "—"}</td>
                                                    <td style={{ maxWidth: 260 }}>{fmtAddress(s)}</td>
                                                    <td>{s.phone || s.email || "—"}</td>
                                                    <td className="text-center">
                                                        {s.is_active
                                                            ? <span className="badge bg-success-subtle text-success">Ativo</span>
                                                            : <span className="badge bg-secondary-subtle text-secondary">Inativo</span>}
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
