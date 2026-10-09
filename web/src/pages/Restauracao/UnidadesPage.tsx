import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button, Container, Row, Col, Spinner, Label, Modal, ModalHeader, ModalBody, ModalFooter, Alert } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "Components/Common/Select";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import { fetchAllPages } from "helpers/fetchAllPages";
import { getPingwinUnits, syncPingwinUnits, createPingwinUnit, getPingwinUnitCreation, editPingwinUnit, anularPingwinUnit, getPingwinUnitUsage } from "helpers/laravel_helper";
import { PingwinUnitRow } from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Unidades (Fase 1, só leitura). As unidades do PingWin
 * são a base de conversão (Barril 50lt = 50 Litros) e são um caos lá — aqui a
 * conversão é mostrada LEGÍVEL ("1 Barril 50lt = 50 Litros"). Exibição paginada
 * (Laravel), pesquisa + filtro (ativas/anuladas) em react-select, mobile em cards.
 *
 * UI-2a: PageCard + DataTable. A pesquisa e o estado vão à API (como antes); a lista lê todas
 * as páginas do resultado e a tabela ordena e pagina no browser.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const API_PER_PAGE = 200;

type ActiveFilter = "active" | "inactive" | "all";
const activeOptions: { value: ActiveFilter; label: string }[] = [
    { value: "active", label: "Ativas" },
    { value: "inactive", label: "Anuladas" },
    { value: "all", label: "Todas" },
];

export default function UnidadesPage() {
    document.title = "Unidades | Restauração | Xplendor";

    const companyId = useWorkingCompanyId();

    const [rows, setRows] = useState<PingwinUnitRow[]>([]);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState("");
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>("active"); // por defeito só ativas

    // ── Criar/Editar unidade (⚠️ ESCRITA no PingWin) ───────────────────────────
    const [createOpen, setCreateOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null); // null = criar; nº = editar
    const [confirming, setConfirming] = useState(false);   // 2.º passo (confirmação obrigatória)
    const [creating, setCreating] = useState(false);       // a escrever + a fazer polling
    const [parents, setParents] = useState<PingwinUnitRow[]>([]);
    const [form, setForm] = useState<{ description: string; shortname: string; parent_id: string; parent_qnt: string; net_weight: string; warn_maxsale_qnt: string; frac_unit: boolean; external_measure: boolean }>(
        { description: "", shortname: "", parent_id: "", parent_qnt: "1", net_weight: "", warn_maxsale_qnt: "", frac_unit: true, external_measure: false }
    );
    // ── Anular unidade (⚠️ mais destrutivo) ────────────────────────────────────
    const [anularTarget, setAnularTarget] = useState<PingwinUnitRow | null>(null);
    const [anularUsage, setAnularUsage] = useState<number | null>(null);
    const [anulando, setAnulando] = useState(false);
    const pollRef = useRef<any>(null);

    const activeFilterCount = [activeFilter !== "active"].filter(Boolean).length;

    const parentOptions = useMemo(
        () => parents.map((p) => ({ value: p.pingwin_id, label: `${p.description ?? "—"}${p.shortname ? ` (${p.shortname})` : ""}` })),
        [parents]
    );

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        const params = {
            search: search.trim() || undefined,
            active: activeFilter === "active" ? 1 : activeFilter === "inactive" ? 0 : undefined,
        };
        try {
            const { rows: all, first } = await fetchAllPages<PingwinUnitRow>(
                (page) => getPingwinUnits(companyId, { ...params, page, perPage: API_PER_PAGE }), (r) => r?.data?.units);
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

    const clearFilters = () => { setSearch(""); setActiveFilter("active"); };

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinUnits(companyId);
            toast.info("A sincronizar unidades… será notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar as unidades.");
        } finally {
            setSyncing(false);
        }
    };

    const loadParents = async () => {
        try {
            const res: any = await getPingwinUnits(companyId, { active: 1, perPage: 200 });
            setParents(res?.data?.units?.data ?? []);
        } catch { setParents([]); }
    };

    const openCreate = async () => {
        setEditingId(null);
        setForm({ description: "", shortname: "", parent_id: "", parent_qnt: "1", net_weight: "", warn_maxsale_qnt: "", frac_unit: true, external_measure: false });
        setConfirming(false);
        setCreateOpen(true);
        await loadParents(); // unidades-base disponíveis para a conversão
    };

    const openEdit = async (u: PingwinUnitRow) => {
        setEditingId(u.id);
        setForm({
            description: u.description ?? "",
            shortname: u.shortname ?? "",
            parent_id: u.parent_pingwin_id ?? "",
            parent_qnt: u.unit_value != null ? String(u.unit_value) : "1",
            net_weight: u.net_weight != null ? String(u.net_weight) : "",
            warn_maxsale_qnt: u.warn_maxsale_qnt != null ? String(u.warn_maxsale_qnt) : "",
            frac_unit: !!u.frac_unit,
            external_measure: !!u.external_measure,
        });
        setConfirming(false);
        setCreateOpen(true);
        await loadParents();
    };

    const setF = (k: string, v: any) => setForm((p) => ({ ...p, [k]: v }));

    const formValid = form.description.trim() !== "" && form.shortname.trim() !== "" && form.parent_id !== "";

    // Poll genérico de uma escrita (create/edit/anular) até criada|erro.
    const pollWrite = (creationId: number, okMsg: string) =>
        new Promise<void>((resolve) => {
            pollRef.current = setInterval(async () => {
                try {
                    const st: any = await getPingwinUnitCreation(companyId, creationId);
                    const s = st?.data?.status;
                    if (s === "criada") {
                        clearInterval(pollRef.current);
                        toast.success(okMsg.replace("{id}", st?.data?.pingwin_id ?? "?"));
                        resolve();
                    } else if (s === "erro") {
                        clearInterval(pollRef.current);
                        toast.error(st?.data?.error_message || "A operação falhou no PingWin."); // ⚠️ motivo REAL
                        resolve();
                    }
                } catch { /* continua a tentar */ }
            }, 2000);
        });

    // Passo final CRIAR/EDITAR: envia ao PingWin (confirm:true) e faz polling.
    const doSave = async () => {
        if (!formValid) return;
        setCreating(true);
        try {
            const payload = {
                confirm: true,
                description: form.description.trim(),
                shortname: form.shortname.trim(),
                parent_id: form.parent_id,
                parent_qnt: form.parent_qnt ? Number(form.parent_qnt) : 1,
                net_weight: form.net_weight ? Number(form.net_weight) : undefined,
                warn_maxsale_qnt: form.warn_maxsale_qnt ? Number(form.warn_maxsale_qnt) : undefined,
                frac_unit: form.frac_unit ? 1 : 0,          // checkbox → 0/1
                external_measure: form.external_measure ? 1 : 0, // checkbox → 0/1
            };
            const res: any = editingId
                ? await editPingwinUnit(companyId, editingId, payload)
                : await createPingwinUnit(companyId, payload);
            const creationId = res?.data?.creation_id;
            if (!creationId) throw new Error("Resposta inválida do servidor.");

            await pollWrite(creationId, editingId ? "Unidade alterada no PingWin." : "Unidade criada no PingWin (id {id}).");

            setCreateOpen(false);
            if (!editingId) { setActiveFilter("active"); setSearch(""); }
            await fetchRows(); // reflete na lista
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível gravar a unidade.");
        } finally {
            setCreating(false);
            setConfirming(false);
        }
    };

    // ── Anular (⚠️ mais destrutivo) ────────────────────────────────────────────
    const openAnular = async (u: PingwinUnitRow) => {
        setAnularTarget(u);
        setAnularUsage(null);
        try {
            const res: any = await getPingwinUnitUsage(companyId, u.id);
            setAnularUsage(res?.data?.usage_count ?? 0);
        } catch { setAnularUsage(null); }
    };

    const doAnular = async () => {
        if (!anularTarget) return;
        setAnulando(true);
        try {
            const res: any = await anularPingwinUnit(companyId, anularTarget.id);
            const creationId = res?.data?.creation_id;
            if (!creationId) throw new Error("Resposta inválida do servidor.");
            await pollWrite(creationId, "Unidade anulada no PingWin.");
            setAnularTarget(null);
            await fetchRows(); // passa a anulada
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível anular a unidade.");
        } finally {
            setAnulando(false);
        }
    };

    useEffect(() => () => { if (pollRef.current) clearInterval(pollRef.current); }, []);

    const emptyMessage = !search && activeFilter === "active"
        ? "Ainda não há unidades. Sincronize para as obter do PingWin."
        : "Nenhum resultado para o filtro.";

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
            <XSelect
                ariaLabel="Estado"
                small
                options={activeOptions}
                value={activeFilter}
                onChange={(v) => setActiveFilter(v)}
                searchable={false}
            />
        </div>
    );

    const rowActions = (u: PingwinUnitRow) => (
        <>
            <Button size="sm" color="outline-primary" onClick={() => openEdit(u)} title="Editar" aria-label={`Editar ${u.description || u.shortname || "unidade"}`}><i className="ri-pencil-line" /></Button>
            <ActionsMenu size="sm" label={`Mais ações: ${u.description || u.shortname || "unidade"}`} items={[
                { label: "Anular", icon: "ri-forbid-line", danger: true, hidden: !u.is_active, onClick: () => openAnular(u) },
            ]} />
        </>
    );

    const columns: DTColumn<PingwinUnitRow>[] = [
        {
            id: "unit", header: "Unidade", value: (u) => u.description, mobile: "title",
            cell: (u) => <span className="fw-medium">{u.description || "—"}{!u.is_global && <span className="badge bg-warning-subtle text-warning ms-2" title="Específica de um artigo">Artigo</span>}</span>,
        },
        { id: "short", header: "Abrev.", value: (u) => u.shortname, mobile: "subtitle" },
        { id: "conversion", header: "Conversão", value: (u) => u.conversion_label, cell: (u) => <Conversion u={u} /> },
        { id: "uses", header: "Usos", value: (u) => [u.purchase && "Compra", u.sale && "Venda", u.stock && "Stock"].filter(Boolean).join(" ") || null, cell: (u) => <Uses u={u} /> },
        {
            id: "active", header: "Estado", value: (u) => (u.is_active ? 1 : 0), align: "center",
            cell: (u) => u.is_active ? <span className="badge bg-success-subtle text-success">Ativa</span> : <span className="badge bg-secondary-subtle text-secondary">Anulada</span>,
        },
    ];
    const cols = useDataColumns("restauracao.unidades", columns);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Unidades"
                    breadcrumbs={[{ label: "Cadastros" }]}
                    info="As unidades e as conversões do PingWin, escritas de forma legível (por exemplo, 1 Barril 50lt = 50 Litros)."
                />

                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Unidades"
                            loading={loading && rows.length > 0}
                            status={<>Última sincronização: {fmtDateTime(lastSynced)}</>}
                            actions={<>
                                {cols.selector}
                                <Button color="outline-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                                </Button>
                                <Button color="primary" onClick={openCreate}>
                                    <i className="ri-add-line me-1" /> Criar unidade
                                </Button>
                            </>}
                            filters={
                                <RestFilterBar
                                    search={search}
                                    onSearchChange={setSearch}
                                    searchPlaceholder="Pesquisar (nome ou abreviatura)…"
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
                                rowKey={(u) => u.id}
                                loading={loading}
                                rowClassName={(u) => (u.is_active ? undefined : "text-muted")}
                                rowActions={rowActions}
                                caption="Unidades"
                                empty={{
                                    message: emptyMessage,
                                    action: !search && activeFilter === "active" ? <Button color="outline-primary" size="sm" onClick={runSync} disabled={syncing}><i className="ri-refresh-line me-1" />Sincronizar</Button> : undefined,
                                }}
                            />
                        </PageCard>
                    </Col>
                </Row>

                {/* ⚠️ Criar unidade — ESCRITA no PingWin (confirmação obrigatória). */}
                <Modal isOpen={createOpen} toggle={() => !creating && setCreateOpen(false)} centered size="lg">
                    <ModalHeader toggle={() => !creating && setCreateOpen(false)}>{editingId ? "Editar unidade no PingWin" : "Criar unidade no PingWin"}</ModalHeader>
                    <ModalBody>
                        {!confirming ? (
                            <>
                                {/* Obrigatórios — em destaque */}
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.05em" }}>Identificação</p>
                                <Row className="g-3 mb-3">
                                    <Col md={8}>
                                        <Label className="fs-12 text-muted mb-1">Descrição *</Label>
                                        <input className="form-control" value={form.description} onChange={(e) => setF("description", e.target.value)} placeholder="ex.: Caixa 6 Unidades" disabled={creating} />
                                    </Col>
                                    <Col md={4}>
                                        <Label className="fs-12 text-muted mb-1">Nome curto *</Label>
                                        <input className="form-control" value={form.shortname} onChange={(e) => setF("shortname", e.target.value)} placeholder="ex.: CX 6" disabled={creating} />
                                    </Col>
                                </Row>

                                {/* Conversão */}
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.05em" }}>Conversão</p>
                                <Row className="g-3 mb-3">
                                    <Col md={6}>
                                        <Label className="fs-12 text-muted mb-1">Base *</Label>
                                        <XSelect ariaLabel="Base" options={parentOptions} value={form.parent_id}
                                            onChange={(v) => setF("parent_id", v)}
                                            placeholder="Escolher unidade-base…" disabled={creating} />
                                    </Col>
                                    <Col md={3}>
                                        <Label className="fs-12 text-muted mb-1">Conv. factor</Label>
                                        <input type="number" step="0.00001" min="0" className="form-control" value={form.parent_qnt} onChange={(e) => setF("parent_qnt", e.target.value)} disabled={creating} />
                                    </Col>
                                    <Col md={3}>
                                        <Label className="fs-12 text-muted mb-1">Peso líquido</Label>
                                        <input type="number" step="0.001" min="0" className="form-control" value={form.net_weight} onChange={(e) => setF("net_weight", e.target.value)} disabled={creating} />
                                    </Col>
                                    {form.parent_id && Number(form.parent_qnt) > 0 && (
                                        <Col xs={12}>
                                            <div className="text-muted fs-12">
                                                <i className="ri-arrow-right-line me-1" />1 {form.description.trim() || "unidade"} = {form.parent_qnt} {parentOptions.find((o) => o.value === form.parent_id)?.label ?? "base"}
                                            </div>
                                        </Col>
                                    )}
                                </Row>

                                {/* Opções */}
                                <p className="text-muted text-uppercase fw-semibold fs-11 mb-2" style={{ letterSpacing: "0.05em" }}>Opções</p>
                                <Row className="g-3 align-items-center">
                                    <Col md={4}>
                                        <Label className="fs-12 text-muted mb-1">Qnt. máx. venda</Label>
                                        <input type="number" step="0.001" min="0" className="form-control" value={form.warn_maxsale_qnt} onChange={(e) => setF("warn_maxsale_qnt", e.target.value)} placeholder="opcional" disabled={creating} />
                                    </Col>
                                    <Col md={4}>
                                        <div className="form-check form-switch fs-16 mt-3">
                                            <input className="form-check-input" type="checkbox" role="switch" id="u-frac" checked={form.frac_unit} onChange={(e) => setF("frac_unit", e.target.checked)} disabled={creating} />
                                            <label className="form-check-label fs-13" htmlFor="u-frac">Unidade fracionária</label>
                                        </div>
                                    </Col>
                                    <Col md={4}>
                                        <div className="form-check form-switch fs-16 mt-3">
                                            <input className="form-check-input" type="checkbox" role="switch" id="u-extm" checked={form.external_measure} onChange={(e) => setF("external_measure", e.target.checked)} disabled={creating} />
                                            <label className="form-check-label fs-13" htmlFor="u-extm">Medição externa</label>
                                        </div>
                                    </Col>
                                </Row>
                            </>
                        ) : (
                            <Alert color="warning" className="mb-0">
                                <h6 className="alert-heading"><i className="ri-error-warning-line me-1" />Confirmar escrita no PingWin</h6>
                                <p className="mb-0">
                                    Vai {editingId ? "alterar" : "criar"} a unidade <strong>«{form.description.trim()}»</strong> ({form.shortname.trim()}) no PingWin.
                                    Isto <strong>escreve no sistema real do restaurante</strong>. Confirma?
                                </p>
                            </Alert>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        {!confirming ? (
                            <>
                                <Button color="light" onClick={() => setCreateOpen(false)}>Cancelar</Button>
                                <ReasonButton color="primary" onClick={() => setConfirming(true)}
                                    reason={!formValid ? "Indique a descrição, o nome curto e a unidade-base." : null}>Continuar</ReasonButton>
                            </>
                        ) : (
                            <>
                                <Button color="light" onClick={() => setConfirming(false)} disabled={creating}>Voltar</Button>
                                <Button color="primary" onClick={doSave} disabled={creating}>
                                    {creating ? <><Spinner size="sm" className="me-1" /> A gravar no PingWin…</> : <><i className="ri-check-double-line me-1" /> Confirmar e {editingId ? "gravar" : "criar"}</>}
                                </Button>
                            </>
                        )}
                    </ModalFooter>
                </Modal>

                {/* ⚠️ Anular unidade — escrita mais destrutiva (deleted=1). Confirmação forte. */}
                <Modal isOpen={!!anularTarget} toggle={() => !anulando && setAnularTarget(null)} centered>
                    <ModalHeader toggle={() => !anulando && setAnularTarget(null)}>Anular unidade no PingWin</ModalHeader>
                    <ModalBody>
                        <Alert color="danger" className="mb-0">
                            <h6 className="alert-heading"><i className="ri-forbid-line me-1" />Vai ANULAR uma unidade no sistema real</h6>
                            <p className="mb-2">
                                A unidade <strong>«{anularTarget?.description}»</strong> ({anularTarget?.shortname}) deixará de estar
                                disponível no PingWin. Isto <strong>escreve no sistema real do restaurante</strong>.
                            </p>
                            {anularUsage === null ? (
                                <p className="mb-0 text-muted fs-13"><Spinner size="sm" className="me-1" /> A verificar se está em uso…</p>
                            ) : anularUsage > 0 ? (
                                <p className="mb-0 fw-semibold">
                                    <i className="ri-alert-line me-1" />⚠️ Cerca de {anularUsage} artigo(s) parecem usar esta unidade: anulá-la pode
                                    partir conversões/cálculos. Tem a certeza?
                                </p>
                            ) : (
                                <p className="mb-0 text-muted fs-13">Não detetámos artigos a usar esta unidade (verificação aproximada).</p>
                            )}
                        </Alert>
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setAnularTarget(null)} disabled={anulando}>Cancelar</Button>
                        <Button color="danger" onClick={doAnular} disabled={anulando}>
                            {anulando ? <><Spinner size="sm" className="me-1" /> A anular…</> : <><i className="ri-forbid-line me-1" /> Confirmar e anular</>}
                        </Button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}
