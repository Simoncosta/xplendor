import { useCallback, useEffect, useMemo, useState } from "react";
import {
    Card, Container, Row, Col, Spinner, Label,
    Modal, ModalHeader, ModalBody, Nav, NavItem, NavLink, TabContent, TabPane, Badge,
} from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import RestFilterBar from "Components/Common/RestFilterBar";
import {
    getPingwinDocuments, syncPingwinDocuments,
    syncPingwinDocumentsRich, getPingwinDocumentConfigDetail,
} from "helpers/laravel_helper";
import { PingwinDocumentConfig, PingwinDocPaycondLink, LaravelPaginator } from "common/models/pingwin.model";

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

type Rowish = Record<string, any>;
const isLinked = (r: Rowish) => !(r.deleted === true || r.deleted === 1 || r.deleted === "1");

/** Campo chave→valor do maindataset (usa o _descr para legibilidade quando existe). */
const Field = ({ label, value }: { label: string; value: any }) => (
    <Col md={4} className="mb-2">
        <div className="text-muted fs-11 text-uppercase" style={{ letterSpacing: "0.04em" }}>{label}</div>
        <div className="text-body">{value === null || value === undefined || value === "" ? "—" : String(value)}</div>
    </Col>
);

/** Bloco read-only de uma tabela filha: título + nº vinculados + lista dos vinculados. */
const ChildBlock = ({ title, rows, showAccount = false }: { title: string; rows?: Rowish[] | null; showAccount?: boolean }) => {
    const list = rows ?? [];
    const linked = list.filter(isLinked);
    return (
        <div className="mb-3">
            <div className="d-flex align-items-center justify-content-between mb-1">
                <span className="fw-semibold fs-13">{title}</span>
                <span className="text-muted fs-12">{linked.length}/{list.length} vinculados</span>
            </div>
            {linked.length === 0 ? (
                <div className="text-muted fs-12">— nenhum vinculado —</div>
            ) : (
                <div className="d-flex flex-wrap gap-1">
                    {linked.map((r, i) => (
                        <Badge key={i} color={showAccount ? "light" : "primary"} className={showAccount ? "text-dark border" : "bg-primary-subtle text-primary"}>
                            {r.description || r.docaccount_id || r.paycond_id || r.id || "—"}
                            {showAccount && (
                                <span className="ms-1 text-muted">
                                    {r.credit === 1 || r.credit === "1" ? "C" : ""}{r.debit === 1 || r.debit === "1" ? "D" : ""}
                                </span>
                            )}
                        </Badge>
                    ))}
                </div>
            )}
        </div>
    );
};

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
    const [syncingRich, setSyncingRich] = useState(false);
    // Detalhe rico (D0): modal com tabs. detailDoc = documento completo; links = paycond resolvido.
    const [detailOpen, setDetailOpen] = useState(false);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailDoc, setDetailDoc] = useState<PingwinDocumentConfig | null>(null);
    const [detailLinks, setDetailLinks] = useState<PingwinDocPaycondLink[]>([]);
    const [detailTab, setDetailTab] = useState("geral");

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

    // Sincroniza a config RICA (detalhe completo) — pesado, assíncrono (notifica no sino).
    const runSyncRich = async () => {
        if (!companyId) return;
        setSyncingRich(true);
        try {
            await syncPingwinDocumentsRich(companyId);
            toast.info("A obter a config completa dos documentos… vais ser notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar o detalhe dos documentos.");
        } finally {
            setSyncingRich(false);
        }
    };

    // Abre o detalhe RICO de um documento (read-only) a partir do espelho.
    const openDetail = async (externalId: string) => {
        if (!companyId) return;
        setDetailOpen(true);
        setDetailTab("geral");
        setDetailLoading(true);
        setDetailDoc(null);
        setDetailLinks([]);
        try {
            const res: any = await getPingwinDocumentConfigDetail(companyId, externalId);
            setDetailDoc(res?.data?.document ?? null);
            setDetailLinks(res?.data?.paycond_links ?? []);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar o detalhe.");
            setDetailOpen(false);
        } finally {
            setDetailLoading(false);
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
                            <div className="d-flex gap-2">
                                <button className="btn btn-soft-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar lista</>}
                                </button>
                                <button className="btn btn-soft-secondary" onClick={runSyncRich} disabled={syncingRich} title="Obter a config completa (maindataset, filhas, options) de cada documento">
                                    {syncingRich ? <><Spinner size="sm" className="me-1" /> A obter detalhe…</> : <><i className="ri-stack-line me-1" /> Sincronizar detalhe</>}
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
                                            <button className="btn btn-sm btn-soft-secondary mt-2" onClick={() => openDetail(d.external_id)}>
                                                <i className="ri-eye-line me-1" /> Ver detalhe
                                            </button>
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
                                                <th className="text-center">Detalhe</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {!loading && rows.length === 0 ? (
                                                <tr><td colSpan={5}>{emptyRow}</td></tr>
                                            ) : rows.map((d) => (
                                                <tr key={d.id}>
                                                    <td className="fw-medium">{d.code || "—"}</td>
                                                    <td>{d.description || "—"}</td>
                                                    <td>{d.entitytype ? <span className="badge bg-info-subtle text-info">{d.entitytype}</span> : <span className="text-muted">—</span>}</td>
                                                    <td>{d.fiscaltype_description || d.fiscaltype || "—"}</td>
                                                    <td className="text-center">
                                                        <button className="btn btn-sm btn-soft-secondary" onClick={() => openDetail(d.external_id)} title="Ver config completa">
                                                            <i className="ri-eye-line" />
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

            {/* Fase D0 — Detalhe RICO do documento (read-only), em tabs (como no PingWin). */}
            <Modal isOpen={detailOpen} toggle={() => setDetailOpen(false)} size="xl" scrollable>
                <ModalHeader toggle={() => setDetailOpen(false)}>
                    {detailDoc ? <>Documento {detailDoc.code} — {detailDoc.description}</> : "Detalhe do documento"}
                    {detailLoading && <Spinner size="sm" className="ms-2" />}
                </ModalHeader>
                <ModalBody>
                    {!detailDoc ? (
                        <div className="text-muted py-3">{detailLoading ? "A carregar…" : "Sem dados. Corre “Sincronizar detalhe” primeiro."}</div>
                    ) : (
                        <>
                            <Nav tabs className="mb-3">
                                {[["geral", "Geral"], ["definicoes", "Definições"], ["paycond", "Cond. Pagamento"], ["lojas", "Lojas"], ["adicionais", "Campos adicionais"]].map(([k, lbl]) => (
                                    <NavItem key={k}>
                                        <NavLink role="button" active={detailTab === k} onClick={() => setDetailTab(k)}>{lbl}</NavLink>
                                    </NavItem>
                                ))}
                            </Nav>
                            <TabContent activeTab={detailTab}>
                                <TabPane tabId="geral">
                                    {(() => { const r = detailDoc.raw || {}; return (
                                        <Row>
                                            <Field label="Código" value={detailDoc.code} />
                                            <Field label="Descrição" value={detailDoc.description} />
                                            <Field label="Nome curto" value={r.shortname} />
                                            <Field label="Tipo de entidade" value={detailDoc.entitytype} />
                                            <Field label="Cenário fiscal" value={r.taxscenario_id_descr || detailDoc.taxscenario_id || "—"} />
                                            <Field label="Tipo de documento" value={r.doctype_id_descr || detailDoc.doctype_id} />
                                            <Field label="Tipo fiscal" value={r.docfiscaltype_id_descr || detailDoc.docfiscaltype_id} />
                                            <Field label="Sinal de stock" value={r.stock_signal_descr || detailDoc.stock_signal} />
                                            <Field label="Série" value={r.docseries_id_descr || detailDoc.docseries_id} />
                                            <Field label="Cond. pagamento (default)" value={r.default_paycond_id_descr || detailDoc.default_paycond_id || "—"} />
                                            <Field label="Nº de cópias" value={r.number_copies} />
                                            <Field label="Estado" value={detailDoc.deleted ? "Inativo (anulado)" : "Ativo"} />
                                        </Row>
                                    ); })()}
                                    <div className="text-muted fs-12 mt-2">maindataset: {Object.keys(detailDoc.raw || {}).length} campos · snapshot de options: {Object.keys(detailDoc.options || {}).length} selects.</div>
                                </TabPane>

                                <TabPane tabId="definicoes">
                                    <ChildBlock title="Tipos de entidade" rows={detailDoc.entitytype_docconfig} />
                                    <ChildBlock title="Estados de documento" rows={detailDoc.docconfig_docstatus} />
                                    <ChildBlock title="Estados de detalhe" rows={detailDoc.docconfig_detailstatus} />
                                    <ChildBlock title="Motivos de movimento" rows={detailDoc.docconfig_docmovreason} />
                                    <ChildBlock title="Contas (crédito/débito)" rows={detailDoc.docconfig_docaccount} showAccount />
                                    <ChildBlock title="Métodos de pagamento" rows={detailDoc.docconfig_paymethod} />
                                    <ChildBlock title="Importação" rows={detailDoc.docconfig_import} />
                                    <ChildBlock title="Referências de documento" rows={detailDoc.docconfig_docreference} />
                                    <ChildBlock title="Perfis de utilizador" rows={detailDoc.userrole_docconfig} />
                                </TabPane>

                                <TabPane tabId="paycond">
                                    {detailLinks.length === 0 ? (
                                        <div className="text-muted">Sem condições de pagamento vinculadas.</div>
                                    ) : (
                                        <table className="table table-sm table-bordered align-middle mb-0">
                                            <thead className="table-light text-muted"><tr>
                                                <th>Condição</th><th>paycond_id</th><th className="text-center">Vinculada</th><th className="text-center">No espelho</th><th className="text-center">Ativa</th>
                                            </tr></thead>
                                            <tbody>
                                                {detailLinks.map((l) => (
                                                    <tr key={l.paycond_id}>
                                                        <td>{l.description || "—"}</td>
                                                        <td className="text-muted fs-12">{l.paycond_id}</td>
                                                        <td className="text-center">{l.linked ? <Badge className="bg-success-subtle text-success">Sim</Badge> : <Badge className="bg-secondary-subtle text-secondary">Não</Badge>}</td>
                                                        <td className="text-center">{l.in_mirror ? "✓" : "—"}</td>
                                                        <td className="text-center">{l.is_active === null ? "—" : (l.is_active ? "✓" : "✗")}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </TabPane>

                                <TabPane tabId="lojas">
                                    <ChildBlock title="Lojas (store_docconfig)" rows={detailDoc.store_docconfig} />
                                    <ChildBlock title="Locais" rows={detailDoc.docconfig_local} />
                                    <div className="text-muted fs-12 mt-1">Config adicional por loja (storedataset): {(detailDoc.additionalfields_storedataset || []).length} linha(s).</div>
                                </TabPane>

                                <TabPane tabId="adicionais">
                                    {(detailDoc.additionalfields_maindataset || []).length === 0 ? (
                                        <div className="text-muted">Sem campos adicionais.</div>
                                    ) : (
                                        <pre className="small bg-light p-2 rounded" style={{ maxHeight: 300, overflow: "auto" }}>
                                            {JSON.stringify(detailDoc.additionalfields_maindataset, null, 2)}
                                        </pre>
                                    )}
                                </TabPane>
                            </TabContent>
                        </>
                    )}
                </ModalBody>
            </Modal>
        </div>
    );
}
