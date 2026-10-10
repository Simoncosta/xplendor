import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    Container, Row, Col, Spinner, Label, Input, Button, Form, FormGroup,
    Modal, ModalHeader, ModalBody, ModalFooter,
} from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "Components/Common/Select";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import { fetchAllPages } from "helpers/fetchAllPages";
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
} from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Condições de Pagamento (Fatia 1, só leitura). Condições
 * do PingWin (paycond) numa tabela limpa e filtrável: código, descrição, desconto
 * financeiro (%), dias de vencimento, estado e os documentos vinculados (tbdocs).
 * Exibição PAGINADA (paginação Laravel), off-canvas em mobile, tabela colapsa em
 * cards no telemóvel. Só módulo pingwin.
 *
 * UI-2a: PageCard + DataTable. A pesquisa e o estado vão à API (como antes); a lista lê todas
 * as páginas do resultado e a tabela ordena e pagina no browser.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const API_PER_PAGE = 200;

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

    const companyId = useWorkingCompanyId();

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
        const params = {
            search: search.trim() || undefined,
            active: activeFilter === "active" ? 1 : activeFilter === "inactive" ? 0 : undefined,
        };
        try {
            const { rows: all, first } = await fetchAllPages<PingwinPaymentCondition>(
                (page) => getPingwinPaymentConditions(companyId, { ...params, page, perPage: API_PER_PAGE }), (r) => r?.data?.payment_conditions);
            setRows(all);
            setLastSynced(first?.data?.last_synced_at ?? null);
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId, search, activeFilter]);

    useEffect(() => {
        const t = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(t);
    }, [fetchRows]);

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
            toast.info("A sincronizar condições de pagamento… será notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar as condições de pagamento.");
        } finally {
            setSyncing(false);
        }
    };

    const emptyMessage = !search && !activeFilter
        ? "Ainda não há condições de pagamento. Sincronize para as obter do PingWin."
        : "Nenhum resultado para o filtro.";

    const filterFields = (
        <div style={{ flex: "1 1 180px", minWidth: 0 }}>
            <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Estado</Label>
            <XSelect
                ariaLabel="Estado"
                small
                options={activeOptions}
                value={activeFilter}
                onChange={(v) => setActiveFilter(v)}
                searchable={false}
                placeholder="Todos"
            />
        </div>
    );

    const rowActions = (c: PingwinPaymentCondition) => (
        <>
            <Button size="sm" color="outline-primary" onClick={() => openEdit(c)} title="Editar" aria-label={`Editar ${c.description || c.code}`}><i className="ri-pencil-line" /></Button>
            <ActionsMenu size="sm" label={`Mais ações: ${c.description || c.code}`} items={[
                { label: "Anular", icon: "ri-forbid-2-line", danger: true, onClick: () => setVoidTarget(c) },
            ]} />
        </>
    );

    const columns: DTColumn<PingwinPaymentCondition>[] = [
        { id: "code", header: "Código", value: (c) => c.code, cell: (c) => <span className="fw-medium">{c.code || "—"}</span>, mobile: "subtitle" },
        { id: "description", header: "Descrição", value: (c) => c.description, mobile: "title" },
        { id: "discount", header: "Desconto", value: (c) => (c.discount === null || c.discount === undefined || c.discount === "" ? null : Number(c.discount)), cell: (c) => fmtDiscount(c.discount), align: "end" },
        { id: "days", header: "Vencimento", value: (c) => c.days, cell: (c) => fmtDays(c.days), align: "end" },
        { id: "docs", header: "Documentos", value: (c) => countLinkedDocs(c.tbdocs), align: "center" },
        {
            id: "active", header: "Estado", value: (c) => (c.is_active ? 1 : 0), align: "center",
            cell: (c) => c.is_active ? <span className="badge bg-success-subtle text-success">Ativo</span> : <span className="badge bg-secondary-subtle text-secondary">Inativo</span>,
        },
    ];
    const cols = useDataColumns("restauracao.condicoes-pagamento", columns);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Condições de Pagamento"
                    breadcrumbs={[{ label: "Cadastros" }]}
                    info="As condições de pagamento do PingWin: desconto financeiro, dias de vencimento e documentos vinculados."
                />

                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Condições de Pagamento"
                            loading={loading && rows.length > 0}
                            status={<>Última sincronização: {fmtDateTime(lastSynced)}</>}
                            actions={<>
                                {cols.selector}
                                <Button size="sm" color="outline-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                                </Button>
                                <Button size="sm" color="primary" onClick={openNew} disabled={creating}>
                                    <i className="ri-add-line me-1" /> Nova condição
                                </Button>
                            </>}
                            filters={
                                <RestFilterBar
                                    search={search}
                                    onSearchChange={setSearch}
                                    searchPlaceholder="Pesquisar (descrição ou código)…"
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
                                rowKey={(c) => c.id}
                                loading={loading}
                                rowClassName={(c) => (c.is_active ? undefined : "text-muted")}
                                rowActions={(c) => (c.is_active ? rowActions(c) : null)}
                                caption="Condições de pagamento"
                                empty={{
                                    message: emptyMessage,
                                    action: !search && !activeFilter ? <Button color="outline-primary" size="sm" onClick={runSync} disabled={syncing}><i className="ri-refresh-line me-1" />Sincronizar</Button> : undefined,
                                }}
                            />
                        </PageCard>
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
                                <ReasonButton type="button" size="sm" color="outline-primary" onClick={() => setVisibleChecked(true)}
                                    reason={visibleDocIds.length === 0 ? "Não há documentos visíveis." : null} title="Marcar os documentos visíveis">
                                    Marcar visíveis
                                </ReasonButton>
                                <ReasonButton type="button" size="sm" color="outline-primary" onClick={() => setVisibleChecked(false)}
                                    reason={visibleDocIds.length === 0 ? "Não há documentos visíveis." : null} title="Desmarcar os documentos visíveis">
                                    Desmarcar visíveis
                                </ReasonButton>
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
                    <ReasonButton color="primary" onClick={submitForm} disabled={creating}
                        reason={docsLoading ? "A carregar os documentos." : !fDesc.trim() ? "Indique a descrição." : null}>
                        {creating
                            ? <><Spinner size="sm" className="me-1" /> A gravar…</>
                            : (editingId ? "Guardar alterações" : "Criar condição")}
                    </ReasonButton>
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
