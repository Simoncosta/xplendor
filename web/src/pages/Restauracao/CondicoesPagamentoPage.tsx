import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    Card, Container, Row, Col, Spinner, Label, Input, Button, Form, FormGroup,
    Modal, ModalHeader, ModalBody, ModalFooter,
} from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { useIsMobile } from "../../hooks/useIsMobile";
import Pagination from "Components/Common/Pagination";
import RestFilterBar from "Components/Common/RestFilterBar";
import {
    getPingwinPaymentConditions,
    syncPingwinPaymentConditions,
    getPingwinPaymentConditionDocsTemplate,
    createPingwinPaymentCondition,
    updatePingwinPaymentCondition,
    voidPingwinPaymentCondition,
    getPingwinPaymentConditionCreation,
} from "helpers/laravel_helper";
import {
    PingwinPaymentCondition,
    PingwinPaymentConditionDoc,
    LaravelPaginator,
} from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Condições de Pagamento (Fatia 1, só leitura). Condições
 * do PingWin (paycond) numa tabela limpa e filtrável: código, descrição, desconto
 * financeiro (%), dias de vencimento, estado e os documentos vinculados (tbdocs).
 * Exibição PAGINADA (paginação Laravel), off-canvas em mobile, tabela colapsa em
 * cards no telemóvel. Só módulo pingwin.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const PER_PAGE = 20;

/** Desconto em percentagem (vem como string decimal, ex.: "2.50"). */
const fmtDiscount = (d?: string | null): string => {
    if (d === null || d === undefined || d === "") return "—";
    const n = Number(d);
    if (!isFinite(n)) return "—";
    return `${n.toLocaleString("pt-PT", { minimumFractionDigits: 0, maximumFractionDigits: 2 })}%`;
};

const fmtDays = (d?: number | null): string =>
    d === null || d === undefined ? "—" : `${d} ${d === 1 ? "dia" : "dias"}`;

/** Nº de documentos vinculados (tbdocs com deleted:0). O detalhe vê-se em "Editar". */
const countLinkedDocs = (docs: PingwinPaymentConditionDoc[] | null): number =>
    (docs ?? []).filter((d) => !(d.deleted === true || d.deleted === 1)).length;

/** Normaliza para comparação: minúsculas + sem acentos (para a pesquisa do modal). */
const normalizeText = (s: string): string =>
    s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");

type ActiveFilter = "" | "active" | "inactive";
const activeOptions: { value: ActiveFilter; label: string }[] = [
    { value: "", label: "Todos" },
    { value: "active", label: "Ativos" },
    { value: "inactive", label: "Inativos" },
];

export default function CondicoesPagamentoPage() {
    document.title = "Condições de Pagamento | Restauração | Xplendor";
    const isMobile = useIsMobile();

    const companyId = useWorkingCompanyId();

    const [page, setPage] = useState(1);
    const [meta, setMeta] = useState<Omit<LaravelPaginator<PingwinPaymentCondition>, "data"> | null>(null);
    const [rows, setRows] = useState<PingwinPaymentCondition[]>([]);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState("");
    // UX: por omissão mostra só ATIVOS (o utilizador pode mudar p/ Anulados/Todos). Isto
    // também faz a condição sair da vista default assim que é anulada.
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>("active");

    const activeFilterCount = [activeFilter !== ""].filter(Boolean).length;

    // ── Fatia 2a: estado do modal "Nova condição" (ESCRITA, assíncrona por polling) ──
    type DocTpl = { docconfig_id: string; description?: string | null; entitytype?: string | null };
    const [showNew, setShowNew] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);   // null = criar; senão pingwin_id (editar)
    const [docs, setDocs] = useState<DocTpl[]>([]);
    const [docsLoading, setDocsLoading] = useState(false);
    const [checked, setChecked] = useState<Record<string, boolean>>({});
    const [origChecked, setOrigChecked] = useState<Record<string, boolean>>({});   // estado inicial p/ diff (editar)
    const [docSearch, setDocSearch] = useState("");   // pesquisa VISUAL dos documentos (não mexe no checked)
    const [fCode, setFCode] = useState("");
    const [fDesc, setFDesc] = useState("");
    const [fDiscount, setFDiscount] = useState("");
    const [fDays, setFDays] = useState("");
    const [creating, setCreating] = useState(false);
    const [voidTarget, setVoidTarget] = useState<PingwinPaymentCondition | null>(null);   // condição a anular (confirmação)
    const pollRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => () => { if (pollRef.current) clearTimeout(pollRef.current); }, []);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinPaymentConditions(companyId, {
                page,
                perPage: PER_PAGE,
                search: search.trim() || undefined,
                active: activeFilter === "active" ? 1 : activeFilter === "inactive" ? 0 : undefined,
            });
            const paginator = res?.data?.payment_conditions;
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

    // Pesquisa VISUAL: bate por descrição OU por docconfig_id (sem acentos, case-insensitive).
    // NÃO altera o `checked` — só o que se mostra. Limpar a pesquisa volta a mostrar tudo.
    const matchesSearch = useCallback((d: DocTpl): boolean => {
        const q = normalizeText(docSearch.trim());
        if (!q) return true;
        return normalizeText(d.description || "").includes(q) || normalizeText(d.docconfig_id).includes(q);
    }, [docSearch]);

    // Agrupa por entitytype APENAS os que batem com a pesquisa (grupos vazios somem).
    const filteredDocsByEntity = useMemo(() => {
        const groups: Record<string, DocTpl[]> = {};
        docs.forEach((d) => {
            if (!matchesSearch(d)) return;
            const k = d.entitytype || "Outros";
            (groups[k] = groups[k] || []).push(d);
        });
        return groups;
    }, [docs, matchesSearch]);

    // ids atualmente VISÍVEIS (respeitando a pesquisa) — alvo do "marcar/desmarcar visíveis".
    const visibleDocIds = useMemo(() => docs.filter(matchesSearch).map((d) => d.docconfig_id), [docs, matchesSearch]);

    // Marca/desmarca SÓ os visíveis; os escondidos mantêm o estado (o diff conta o estado final).
    const setVisibleChecked = (value: boolean) => {
        setChecked((prev) => {
            const next = { ...prev };
            visibleDocIds.forEach((id) => { next[id] = value; });
            return next;
        });
    };

    const openNew = async () => {
        setEditingId(null);
        setFCode(""); setFDesc(""); setFDiscount(""); setFDays(""); setDocSearch("");
        setShowNew(true);
        setDocsLoading(true);
        try {
            const res: any = await getPingwinPaymentConditionDocsTemplate(companyId);
            const list: DocTpl[] = (res?.data?.documents ?? []).map((d: any) => ({
                docconfig_id: String(d.docconfig_id),
                description: d.description ?? null,
                entitytype: d.entitytype ?? null,
            }));
            setDocs(list);
            // Todos vinculados por omissão (o form-novo traz a matriz toda a deleted:0).
            const init: Record<string, boolean> = {};
            list.forEach((d) => { init[d.docconfig_id] = true; });
            setChecked(init);
            setOrigChecked(init);
        } catch {
            setDocs([]);
            toast.error("Não foi possível carregar os documentos.");
        } finally {
            setDocsLoading(false);
        }
    };

    // EDITAR (2b): pré-preenche a partir da linha (mirror); o Python relê o VIVO e aplica
    // SÓ as mudanças (preservação). code em read-only. checkboxes do estado atual (deleted:0).
    const openEdit = (row: PingwinPaymentCondition) => {
        if (!row.is_active) return;   // 2b só edita ATIVAS
        setEditingId(row.pingwin_id);
        setDocSearch("");
        setFCode(row.code ?? "");
        setFDesc(row.description ?? "");
        setFDiscount(row.discount != null ? String(row.discount) : "");
        setFDays(row.days != null ? String(row.days) : "");
        const list: DocTpl[] = (row.tbdocs ?? []).map((d) => ({
            docconfig_id: String(d.docconfig_id),
            description: d.description ?? null,
            entitytype: d.entitytype ?? null,
        }));
        setDocs(list);
        const init: Record<string, boolean> = {};
        (row.tbdocs ?? []).forEach((d) => { init[String(d.docconfig_id)] = !(d.deleted === true || d.deleted === 1); });
        setChecked(init);
        setOrigChecked(init);
        setDocsLoading(false);
        setShowNew(true);
    };

    const pollCreation = useCallback((creationId: number) => {
        getPingwinPaymentConditionCreation(companyId, creationId)
            .then((res: any) => {
                const status = res?.data?.status;
                if (status === "a_criar") {
                    pollRef.current = setTimeout(() => pollCreation(creationId), 1500);
                    return;
                }
                setCreating(false);
                if (status === "ok") {
                    toast.success("Operação concluída no PingWin.");
                    setShowNew(false);
                    setVoidTarget(null);
                    fetchRows();
                } else {
                    toast.error(res?.data?.error_message || "Não foi possível concluir a operação.");
                }
            })
            .catch(() => {
                setCreating(false);
                toast.error("Falha a consultar o estado da criação.");
            });
    }, [companyId, fetchRows]);

    const submitForm = async () => {
        if (!companyId) return;
        if (!fDesc.trim()) { toast.error("A descrição é obrigatória."); return; }
        const discount = fDiscount.trim() === "" ? undefined : Number(fDiscount);
        const days = fDays.trim() === "" ? undefined : Number(fDays);
        setCreating(true);
        try {
            let res: any;
            if (editingId) {
                // EDITAR: envia SÓ as mudanças (checkbox diferente do estado inicial).
                const changes = docs
                    .filter((d) => !!checked[d.docconfig_id] !== !!origChecked[d.docconfig_id])
                    .map((d) => ({ docconfig_id: d.docconfig_id, deleted: checked[d.docconfig_id] ? 0 : 1 }));
                res = await updatePingwinPaymentCondition(companyId, editingId, {
                    description: fDesc.trim(), discount, days, tbdocs_changes: changes,
                });
                toast.info("A atualizar no PingWin… a confirmar.");
            } else {
                // CRIAR: envia os desmarcados (template é tudo vinculado).
                const unlinked = docs.map((d) => d.docconfig_id).filter((id) => !checked[id]);
                res = await createPingwinPaymentCondition(companyId, {
                    code: fCode.trim() || undefined, description: fDesc.trim(), discount, days, tbdocs_unlinked: unlinked,
                });
                toast.info("A criar no PingWin… a confirmar.");
            }
            const creationId = res?.data?.creation_id;
            if (!creationId) { setCreating(false); toast.error("Resposta inesperada do servidor."); return; }
            pollCreation(creationId);
        } catch (e: any) {
            setCreating(false);
            toast.error(e?.message ?? "Não foi possível gravar a condição.");
        }
    };

    // ANULAR (2c): só após confirmação explícita no diálogo (nomeia a condição).
    const confirmVoid = async () => {
        if (!companyId || !voidTarget) return;
        setCreating(true);
        try {
            const res: any = await voidPingwinPaymentCondition(companyId, voidTarget.pingwin_id);
            const creationId = res?.data?.creation_id;
            if (!creationId) { setCreating(false); toast.error("Resposta inesperada ao anular."); return; }
            toast.info("A anular no PingWin… a confirmar.");
            pollCreation(creationId);
        } catch (e: any) {
            setCreating(false);
            toast.error(e?.message ?? "Não foi possível anular a condição.");
        }
    };

    const checkedCount = docs.filter((d) => checked[d.docconfig_id]).length;

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinPaymentConditions(companyId);
            toast.info("A sincronizar condições de pagamento… vais ser notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar as condições de pagamento.");
        } finally {
            setSyncing(false);
        }
    };

    const emptyRow = (
        <div className="text-center text-muted py-4">
            {!search && !activeFilter
                ? <>Sem condições de pagamento. Usa <strong>“Sincronizar”</strong> para as obter do PingWin.</>
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
                                <h4 className="mb-sm-0">Condições de Pagamento</h4>
                                <small className="text-muted">Condições de pagamento do PingWin (só leitura). Última sincronização: {fmtDateTime(lastSynced)}</small>
                            </div>
                            <div className="d-flex gap-2">
                                <button className="btn btn-soft-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                                </button>
                                <button className="btn btn-primary" onClick={openNew} disabled={creating}>
                                    <i className="ri-add-line me-1" /> Nova condição
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
                                    <h5 className="card-title mb-0">Condições de Pagamento {loading && <Spinner size="sm" className="ms-1" />}</h5>
                                    <RestFilterBar
                                        search={search}
                                        onSearchChange={setSearch}
                                        searchPlaceholder="Pesquisar (descrição ou código)…"
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
                                    {!loading && rows.length === 0 ? emptyRow : rows.map((c) => (
                                        <div key={c.id} style={{ border: "1px solid var(--vz-border-color)", borderRadius: 12, padding: "12px 14px", background: "var(--vz-card-bg)" }} className={c.is_active ? "" : "opacity-75"}>
                                            <div className="d-flex align-items-start justify-content-between gap-2 mb-1">
                                                <div style={{ minWidth: 0 }}>
                                                    <div className="fw-semibold text-body text-truncate">{c.description || "—"}</div>
                                                    <div className="text-muted fs-12">Código {c.code || "—"}</div>
                                                </div>
                                                {c.is_active
                                                    ? (
                                                        <div className="d-flex gap-1 flex-shrink-0">
                                                            <button className="btn btn-sm btn-soft-secondary" onClick={() => openEdit(c)} title="Editar"><i className="ri-pencil-line" /></button>
                                                            <button className="btn btn-sm btn-soft-danger" onClick={() => setVoidTarget(c)} title="Anular"><i className="ri-delete-bin-line" /></button>
                                                        </div>
                                                    )
                                                    : <span className="badge bg-secondary-subtle text-secondary flex-shrink-0">Inativo</span>}
                                            </div>
                                            <div className="text-muted fs-12 mt-1">
                                                Desconto: {fmtDiscount(c.discount)} · Vencimento: {fmtDays(c.days)}
                                            </div>
                                            <div className="text-muted fs-12 mt-1">
                                                <i className="ri-file-list-3-line me-1" />{countLinkedDocs(c.tbdocs)} documento(s) vinculado(s)
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
                                                <th className="text-end">Desconto</th>
                                                <th className="text-end">Vencimento</th>
                                                <th className="text-center">Documentos</th>
                                                <th className="text-center">Estado</th>
                                                <th className="text-center">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {!loading && rows.length === 0 ? (
                                                <tr><td colSpan={7}>{emptyRow}</td></tr>
                                            ) : rows.map((c) => (
                                                <tr key={c.id} className={c.is_active ? "" : "text-muted"}>
                                                    <td className="fw-medium">{c.code || "—"}</td>
                                                    <td>{c.description || "—"}</td>
                                                    <td className="text-end">{fmtDiscount(c.discount)}</td>
                                                    <td className="text-end">{fmtDays(c.days)}</td>
                                                    <td className="text-center" title="Documentos vinculados (ver detalhe em Editar)">{countLinkedDocs(c.tbdocs)}</td>
                                                    <td className="text-center">
                                                        {c.is_active
                                                            ? <span className="badge bg-success-subtle text-success">Ativo</span>
                                                            : <span className="badge bg-secondary-subtle text-secondary">Inativo</span>}
                                                    </td>
                                                    <td className="text-center">
                                                        {c.is_active && (
                                                            <div className="d-flex gap-1 justify-content-center">
                                                                <button className="btn btn-sm btn-soft-secondary" onClick={() => openEdit(c)} title="Editar">
                                                                    <i className="ri-pencil-line" />
                                                                </button>
                                                                <button className="btn btn-sm btn-soft-danger" onClick={() => setVoidTarget(c)} title="Anular">
                                                                    <i className="ri-delete-bin-line" />
                                                                </button>
                                                            </div>
                                                        )}
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

            {/* Fatia 2a — Modal NOVA CONDIÇÃO (escrita assíncrona). */}
            <Modal isOpen={showNew} toggle={() => !creating && setShowNew(false)} size="lg" scrollable>
                <ModalHeader toggle={() => !creating && setShowNew(false)}>
                    {editingId ? "Editar condição de pagamento" : "Nova condição de pagamento"}
                </ModalHeader>
                <ModalBody>
                    <Form onSubmit={(e) => { e.preventDefault(); submitForm(); }}>
                        <Row>
                            <Col md={3}>
                                <FormGroup>
                                    <Label className="fw-semibold">Código</Label>
                                    <Input value={fCode} maxLength={10} placeholder="(auto se vazio)"
                                        readOnly={!!editingId} disabled={!!editingId}
                                        onChange={(e) => setFCode(e.target.value)} />
                                </FormGroup>
                            </Col>
                            <Col md={5}>
                                <FormGroup>
                                    <Label className="fw-semibold">Descrição *</Label>
                                    <Input value={fDesc} maxLength={50} onChange={(e) => setFDesc(e.target.value)} />
                                </FormGroup>
                            </Col>
                            <Col md={2}>
                                <FormGroup>
                                    <Label className="fw-semibold">Desconto %</Label>
                                    <Input type="number" min={0} max={100} step="0.01" value={fDiscount}
                                        onChange={(e) => setFDiscount(e.target.value)} />
                                </FormGroup>
                            </Col>
                            <Col md={2}>
                                <FormGroup>
                                    <Label className="fw-semibold">Dias</Label>
                                    <Input type="number" min={0} step="1" value={fDays}
                                        onChange={(e) => setFDays(e.target.value)} />
                                </FormGroup>
                            </Col>
                        </Row>

                        <div className="d-flex align-items-center justify-content-between mt-2 mb-1">
                            <Label className="fw-semibold mb-0">Documentos vinculados {docsLoading && <Spinner size="sm" className="ms-1" />}</Label>
                            <small className="text-muted">{checkedCount}/{docs.length} vinculados</small>
                        </div>
                        <div className="d-flex align-items-center gap-2 mb-2">
                            <Input bsSize="sm" value={docSearch} placeholder="Pesquisar documento (descrição ou código)…"
                                onChange={(e) => setDocSearch(e.target.value)} style={{ maxWidth: 360 }} />
                            {docSearch && (
                                <button type="button" className="btn btn-sm btn-link text-muted p-0" onClick={() => setDocSearch("")}>limpar</button>
                            )}
                            <div className="ms-auto d-flex gap-1">
                                <button type="button" className="btn btn-sm btn-soft-secondary" onClick={() => setVisibleChecked(true)}
                                    disabled={visibleDocIds.length === 0} title="Marcar os documentos visíveis">
                                    Marcar visíveis
                                </button>
                                <button type="button" className="btn btn-sm btn-soft-secondary" onClick={() => setVisibleChecked(false)}
                                    disabled={visibleDocIds.length === 0} title="Desmarcar os documentos visíveis">
                                    Desmarcar visíveis
                                </button>
                            </div>
                        </div>
                        <div style={{ maxHeight: 320, overflowY: "auto", border: "1px solid var(--vz-border-color)", borderRadius: 8, padding: "8px 12px" }}>
                            {!docsLoading && docs.length === 0 ? (
                                <div className="text-muted py-2">Sem documentos para apresentar.</div>
                            ) : visibleDocIds.length === 0 ? (
                                <div className="text-muted py-2">Nenhum documento corresponde à pesquisa.</div>
                            ) : Object.keys(filteredDocsByEntity).sort().map((entity) => (
                                <div key={entity} className="mb-2">
                                    <div className="text-muted fw-semibold fs-11 text-uppercase mb-1">{entity}</div>
                                    <Row>
                                        {filteredDocsByEntity[entity].map((d) => (
                                            <Col md={6} key={d.docconfig_id}>
                                                <FormGroup check className="mb-1">
                                                    <Input type="checkbox" id={`doc-${d.docconfig_id}`}
                                                        checked={!!checked[d.docconfig_id]}
                                                        onChange={(e) => setChecked((p) => ({ ...p, [d.docconfig_id]: e.target.checked }))} />
                                                    <Label check for={`doc-${d.docconfig_id}`} className="fs-13">
                                                        {d.description || d.docconfig_id}
                                                        <span className="text-muted ms-1">#{d.docconfig_id}</span>
                                                    </Label>
                                                </FormGroup>
                                            </Col>
                                        ))}
                                    </Row>
                                </div>
                            ))}
                        </div>
                    </Form>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setShowNew(false)} disabled={creating}>Cancelar</Button>
                    <Button color="primary" onClick={submitForm} disabled={creating || docsLoading || !fDesc.trim()}>
                        {creating
                            ? <><Spinner size="sm" className="me-1" /> A gravar…</>
                            : (editingId ? "Guardar alterações" : "Criar condição")}
                    </Button>
                </ModalFooter>
            </Modal>

            {/* Fatia 2c — Confirmação EXPLÍCITA de anular (nomeia a condição). */}
            <Modal isOpen={!!voidTarget} toggle={() => !creating && setVoidTarget(null)} centered>
                <ModalHeader toggle={() => !creating && setVoidTarget(null)}>Anular condição de pagamento</ModalHeader>
                <ModalBody>
                    {voidTarget && (
                        <>
                            <p className="mb-2">
                                Anular a condição <strong>«{voidTarget.description || "—"}»</strong> (código <strong>{voidTarget.code || "—"}</strong>)?
                            </p>
                            <p className="text-danger mb-0 fs-13">
                                <i className="ri-error-warning-line me-1" />
                                Isto torna a condição <strong>inativa</strong> no PingWin. A ação <strong>não é reversível pela aplicação</strong>.
                            </p>
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setVoidTarget(null)} disabled={creating}>Cancelar</Button>
                    <Button color="danger" onClick={confirmVoid} disabled={creating}>
                        {creating ? <><Spinner size="sm" className="me-1" /> A anular…</> : "Anular condição"}
                    </Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
