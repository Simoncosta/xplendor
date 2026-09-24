import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, Container, Row, Col, Spinner, Label } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getPingwinDocuments, syncPingwinDocuments } from "helpers/laravel_helper";
import { PingwinDocumentConfig, LaravelPaginator } from "common/models/pingwin.model";

/**
 * XPLENDOR — Restauração › Documentos (Fase 1, só leitura). Tipos de documento
 * do PingWin numa tabela limpa e filtrável. Exibição PAGINADA (paginação
 * Laravel). Filtro com react-select (padrão do sistema), off-canvas em mobile, e
 * a tabela colapsa em cards no telemóvel (sem overflow horizontal).
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const PER_PAGE = 20;
type Opt = { value: string; label: string };

export default function DocumentosPage() {
    document.title = "Documentos | Restauração | Xplendor";
    const isMobile = useIsMobile();

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<Omit<LaravelPaginator<PingwinDocumentConfig>, "data"> | null>(null);
    const [rows, setRows] = useState<PingwinDocumentConfig[]>([]);
    const [entityTypes, setEntityTypes] = useState<string[]>([]);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState("");
    const [entityFilter, setEntityFilter] = useState("");

    const entityOptions: Opt[] = useMemo(
        () => [{ value: "", label: "Todas as entidades" }, ...entityTypes.map((t) => ({ value: t, label: t }))],
        [entityTypes]
    );

    const activeFilterCount = [!!entityFilter].filter(Boolean).length;

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinDocuments(companyId, {
                page,
                perPage: PER_PAGE,
                search: search.trim() || undefined,
                entitytype: entityFilter || undefined,
            });
            const paginator = res?.data?.documents;
            setRows(paginator?.data ?? []);
            const { data: _omit, ...m } = paginator ?? {};
            setMeta(paginator ? (m as any) : null);
            setEntityTypes(res?.data?.entitytypes ?? []);
            setLastSynced(res?.data?.last_synced_at ?? null);
        } catch {
            setRows([]);
            setMeta(null);
        } finally {
            setLoading(false);
        }
    }, [companyId, page, search, entityFilter]);

    useEffect(() => {
        const t = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(t);
    }, [fetchRows]);

    useEffect(() => { setPage(1); }, [search, entityFilter]);

    const clearFilters = () => { setSearch(""); setEntityFilter(""); };

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinDocuments(companyId);
            toast.info("A sincronizar documentos… vais ser notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar os documentos.");
        } finally {
            setSyncing(false);
        }
    };

    const emptyRow = (
        <div className="text-center text-muted py-4">
            {!search && !entityFilter
                ? <>Sem documentos. Usa <strong>“Sincronizar”</strong> para os obter do PingWin.</>
                : "Nenhum resultado para o filtro."}
        </div>
    );

    const filterFields = (
        <div style={{ flex: "1 1 220px", minWidth: 0 }}>
            <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Tipo de entidade</Label>
            <Select
                styles={reactSelectTheme}
                menuPortalTarget={document.body}
                options={entityOptions}
                value={entityOptions.find((o) => o.value === entityFilter) ?? entityOptions[0]}
                onChange={(o: any) => setEntityFilter(o?.value ?? "")}
                isSearchable
                placeholder="Todas as entidades"
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
                                <h4 className="mb-sm-0">Documentos</h4>
                                <small className="text-muted">Tipos de documento do PingWin (só leitura). Última sincronização: {fmtDateTime(lastSynced)}</small>
                            </div>
                            <button className="btn btn-soft-primary" onClick={runSync} disabled={syncing}>
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
                                    <h5 className="card-title mb-0">Tipos de documento {loading && <Spinner size="sm" className="ms-1" />}</h5>
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
                                    {!loading && rows.length === 0 ? emptyRow : rows.map((d) => (
                                        <div key={d.id} style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)" }}>
                                            <div className="d-flex align-items-start justify-content-between gap-2 mb-1">
                                                <div style={{ minWidth: 0 }}>
                                                    <div className="fw-semibold text-body text-truncate">{d.description || "—"}</div>
                                                    <div className="text-muted fs-12">{d.code || "—"}</div>
                                                </div>
                                                {d.entitytype && <span className="badge bg-info-subtle text-info flex-shrink-0">{d.entitytype}</span>}
                                            </div>
                                            <div className="text-muted fs-12 mt-1">
                                                Tipo fiscal: <span className="text-body">{d.fiscaltype_description || d.fiscaltype || "—"}</span>
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
                                                <th>Tipo de entidade</th>
                                                <th>Tipo fiscal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {!loading && rows.length === 0 ? (
                                                <tr><td colSpan={4}>{emptyRow}</td></tr>
                                            ) : rows.map((d) => (
                                                <tr key={d.id}>
                                                    <td className="fw-medium">{d.code || "—"}</td>
                                                    <td>{d.description || "—"}</td>
                                                    <td>{d.entitytype ? <span className="badge bg-info-subtle text-info">{d.entitytype}</span> : <span className="text-muted">—</span>}</td>
                                                    <td>{d.fiscaltype_description || d.fiscaltype || "—"}</td>
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
