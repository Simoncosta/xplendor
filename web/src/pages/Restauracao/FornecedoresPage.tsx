import { useCallback, useEffect, useRef, useState } from "react";
import { Button, Container, Row, Col, Spinner, Label, Input, Modal, ModalHeader, ModalBody, ModalFooter, Alert } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import { useSearchParams } from "react-router-dom";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import ActionsMenu from "Components/Common/ActionsMenu";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import ReasonButton from "Components/Common/ReasonButton";
import {
    getPingwinSuppliers, syncPingwinSuppliers, getPingwinPaymentConditions,
    createPingwinSupplier, updatePingwinSupplier, voidPingwinSupplier, getPingwinSupplierWrite,
} from "helpers/laravel_helper";
import { PingwinSupplier, PingwinSupplierForm, PingwinSupplierWriteState } from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Fornecedores. Lista paginada do PingWin (filtro, cards no
 * telemóvel) e, desde a FN, ⚠️ ESCRITA no PingWin: criar, editar e anular. As escritas são
 * assíncronas (o worker escreve e confirma por releitura; a UI faz polling). Guarda de NIF:
 * NIF português inválido → aviso com opção de forçar (estrangeiro); NIF já existente →
 * mostra o fornecedor existente e só grava se a pessoa confirmar. Contactos ficam de fora.
 *
 * UI-1: PageCard (Sincronizar e Novo fornecedor no cabeçalho, estado da sincronização e da
 * gravação por baixo do título) + DataTable. A lista lê todos os fornecedores do filtro de
 * estado (são poucas centenas) e a tabela ordena, pesquisa e pagina no browser.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const API_PER_PAGE = 200;
const POLL_MS = 2000;
const PORTUGAL = "30000";

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

const EMPTY_FORM: PingwinSupplierForm = {
    description: "", fiscalname: "", tax_number: "", paycond_id: "", address: "",
    postalcode: "", postalcode_description: "", country_id: PORTUGAL, obs: "",
};

const formFrom = (s: PingwinSupplier): PingwinSupplierForm => ({
    description: s.name ?? "", fiscalname: s.fiscal_name ?? "", tax_number: s.tax_number ?? "",
    paycond_id: s.paycond_pingwin_id ?? "", address: s.address ?? "", postalcode: s.postal_code ?? "",
    postalcode_description: s.city ?? "", country_id: s.country_pingwin_id || PORTUGAL, obs: s.obs ?? "",
});

/** NIF português: 9 dígitos e dígito de controlo (igual ao backend). */
const isValidPtNif = (nif: string) => {
    if (!/^\d{9}$/.test(nif)) return false;
    let sum = 0;
    for (let i = 0; i < 8; i++) sum += Number(nif[i]) * (9 - i);
    const check = 11 - (sum % 11);
    return (check >= 10 ? 0 : check) === Number(nif[8]);
};

type Existing = { code?: string; name?: string; tax_number?: string } | null;

export default function FornecedoresPage() {
    document.title = "Fornecedores | Restauração | Xplendor";

    const companyId = useWorkingCompanyId();

    const [rows, setRows] = useState<PingwinSupplier[]>([]);
    const [lastSynced, setLastSynced] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [search, setSearch] = useState("");
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>("active");

    // Formulário (criar/editar)
    const [modal, setModal] = useState<{ mode: "create" | "edit"; supplier?: PingwinSupplier } | null>(null);
    const [form, setForm] = useState<PingwinSupplierForm>(EMPTY_FORM);
    const [initial, setInitial] = useState<PingwinSupplierForm>(EMPTY_FORM);
    const [allowInvalidNif, setAllowInvalidNif] = useState(false);
    const [allowDuplicateNif, setAllowDuplicateNif] = useState(false);
    const [nifInvalidAsked, setNifInvalidAsked] = useState(false);
    const [existing, setExisting] = useState<Existing>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [paycondOptions, setPaycondOptions] = useState<{ value: string; label: string }[]>([]);

    // Anular
    const [voidTarget, setVoidTarget] = useState<PingwinSupplier | null>(null);
    const [voiding, setVoiding] = useState(false);

    // Escrita em curso (polling)
    const [pending, setPending] = useState<{ writeId: number; action: string; label: string } | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const activeFilterCount = [activeFilter !== "active"].filter(Boolean).length;

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        // Lê todos os fornecedores do filtro de estado; a pesquisa e a ordenação são no browser.
        const params = { active: activeFilter === "active" ? 1 : activeFilter === "inactive" ? 0 : undefined };
        try {
            const res: any = await getPingwinSuppliers(companyId, { ...params, page: 1, perPage: API_PER_PAGE });
            const paginator = res?.data?.suppliers;
            let all: PingwinSupplier[] = paginator?.data ?? [];
            for (let p = 2; p <= (paginator?.last_page ?? 1); p++) {
                const next: any = await getPingwinSuppliers(companyId, { ...params, page: p, perPage: API_PER_PAGE });
                all = all.concat(next?.data?.suppliers?.data ?? []);
            }
            setRows(all);
            setLastSynced(res?.data?.last_synced_at ?? null);
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId, activeFilter]);

    useEffect(() => { fetchRows(); }, [fetchRows]);

    // Condições de pagamento (select do formulário): só as ativas do nosso espelho.
    useEffect(() => {
        if (!companyId) return;
        getPingwinPaymentConditions(companyId, { perPage: 100, active: 1 })
            .then((res: any) => setPaycondOptions([{ value: "", label: "— Sem condição —" },
                ...((res?.data?.payment_conditions?.data ?? res?.data?.conditions?.data ?? []) as any[])
                    .map((c) => ({ value: String(c.pingwin_id), label: `${c.code ?? ""} · ${c.description ?? ""}`.trim() }))]))
            .catch(() => setPaycondOptions([{ value: "", label: "— Sem condição —" }]));
    }, [companyId]);

    useEffect(() => () => { if (pollRef.current) clearInterval(pollRef.current); }, []);

    const clearFilters = () => { setSearch(""); setActiveFilter("active"); };

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinSuppliers(companyId);
            toast.info("A sincronizar fornecedores… será notificado no sino quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível sincronizar os fornecedores.");
        } finally {
            setSyncing(false);
        }
    };

    // ── Polling de uma escrita até terminar ────────────────────────────────
    const follow = (writeId: number, action: string, label: string, fromModal: boolean) => {
        setPending({ writeId, action, label });
        if (pollRef.current) clearInterval(pollRef.current);
        pollRef.current = setInterval(async () => {
            if (!companyId) return;
            try {
                const res: any = await getPingwinSupplierWrite(companyId, writeId);
                const w: PingwinSupplierWriteState | undefined = res?.data;
                if (!w || w.status === "pendente") return;
                if (pollRef.current) clearInterval(pollRef.current);
                setPending(null);
                if (w.status === "ok") {
                    const verb = action === "criar" ? "criado" : action === "editar" ? "atualizado" : "anulado";
                    toast.success(`Fornecedor ${verb} no PingWin: ${label}.`);
                    if (fromModal) setModal(null);
                    fetchRows();
                } else if (w.status === "duplicado") {
                    // O NIF existe no PingWin vivo (ou o servidor contou-o): mostra e pede confirmação.
                    setExisting(w.existing?.[0] ?? { name: "outro fornecedor" });
                    setFormError(w.error_message);
                    if (!fromModal) toast.warning(w.error_message ?? "NIF já existente.");
                } else {
                    setFormError(w.error_message ?? "A escrita falhou.");
                    if (!fromModal) toast.error(w.error_message ?? "A escrita falhou.");
                }
            } catch { /* tenta no próximo intervalo */ }
        }, POLL_MS);
    };

    // ── Formulário ─────────────────────────────────────────────────────────
    const openCreate = () => {
        setForm(EMPTY_FORM); setInitial(EMPTY_FORM);
        resetGuards();
        setModal({ mode: "create" });
    };
    // F3: "Criar fornecedor" a partir de uma fatura carregada → modal de criação pré-preenchido
    // (?novo=1&nif=…&nome=…&morada=…). Os parâmetros saem do URL depois de usados.
    const [searchParams, setSearchParams] = useSearchParams();
    useEffect(() => {
        if (searchParams.get("novo") !== "1") return;
        const f: PingwinSupplierForm = {
            ...EMPTY_FORM,
            description: (searchParams.get("nome") ?? "").slice(0, 50),
            fiscalname: (searchParams.get("nome") ?? "").slice(0, 100),
            tax_number: searchParams.get("nif") ?? "",
            address: (searchParams.get("morada") ?? "").slice(0, 150),
        };
        setForm(f); setInitial(EMPTY_FORM);
        resetGuards();
        setModal({ mode: "create" });
        setSearchParams({}, { replace: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchParams]);

    const openEdit = (s: PingwinSupplier) => {
        const f = formFrom(s);
        setForm(f); setInitial(f);
        resetGuards();
        setModal({ mode: "edit", supplier: s });
    };
    const resetGuards = () => {
        setAllowInvalidNif(false); setAllowDuplicateNif(false); setNifInvalidAsked(false);
        setExisting(null); setFormError(null);
    };
    const setField = (k: keyof PingwinSupplierForm, v: string) => {
        setForm((f) => ({ ...f, [k]: v }));
        if (k === "tax_number") { setExisting(null); setAllowDuplicateNif(false); setNifInvalidAsked(false); setAllowInvalidNif(false); }
    };

    const nif = form.tax_number.trim();
    const nifLooksInvalid = nif !== "" && !isValidPtNif(nif);
    const changedKeys = (Object.keys(form) as (keyof PingwinSupplierForm)[]).filter((k) => form[k] !== initial[k]);

    const save = async () => {
        if (!companyId || !modal) return;
        setFormError(null);
        if (nifLooksInvalid && !allowInvalidNif) { setNifInvalidAsked(true); return; }
        // Editar: só os campos ALTERADOS (o worker relê o vivo e mantém o resto).
        const body: Record<string, any> = modal.mode === "create"
            ? { ...form }
            : Object.fromEntries(changedKeys.map((k) => [k, form[k]]));
        if (allowInvalidNif) body.allow_invalid_nif = true;
        if (allowDuplicateNif) body.allow_duplicate_nif = true;
        setSaving(true);
        try {
            const res: any = modal.mode === "create"
                ? await createPingwinSupplier(companyId, body)
                : await updatePingwinSupplier(companyId, modal.supplier!.id, body);
            follow(res?.data?.write_id, modal.mode === "create" ? "criar" : "editar", form.description, true);
        } catch (err: any) {
            if (err?.__status === 409 && err?.errors?.code === "nif_duplicado") {
                setExisting(err.errors.existing ?? null);
                setFormError(err.message);
            } else if (err?.__status === 422 && err?.errors?.code === "nif_invalido") {
                setNifInvalidAsked(true);
            } else {
                setFormError(err?.message ?? "Não foi possível gravar.");
            }
        } finally {
            setSaving(false);
        }
    };

    const confirmVoid = async () => {
        if (!companyId || !voidTarget) return;
        setVoiding(true);
        try {
            const res: any = await voidPingwinSupplier(companyId, voidTarget.id);
            follow(res?.data?.write_id, "anular", voidTarget.name ?? "", false);
            setVoidTarget(null);
        } catch (err: any) {
            toast.error(err?.message ?? "Não foi possível anular.");
        } finally {
            setVoiding(false);
        }
    };

    const busy = saving || !!pending;
    const saveReason = !form.description.trim() ? "O nome é obrigatório."
        : modal?.mode === "edit" && changedKeys.length === 0 ? "Nada foi alterado."
        : existing && !allowDuplicateNif ? "Confirme que quer gravar com este NIF repetido."
        : nifInvalidAsked && !allowInvalidNif ? "Confirme o NIF estrangeiro."
        : null;

    const emptyMessage = activeFilter === ""
        ? "Ainda não há fornecedores. Sincronize para os obter do PingWin."
        : activeFilter === "active" ? "Sem fornecedores ativos." : "Sem fornecedores inativos.";

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
                placeholder="Ativos"
            />
        </div>
    );

    // Ações por linha (design-system §4): "Editar" visível; "Anular" (destrutiva) só no menu "...".
    const busyReason = busy ? "Aguarde: está a gravar outra alteração no PingWin." : null;
    const rowActions = (s: PingwinSupplier) => s.is_active ? (
        <>
            <ReasonButton size="sm" color="outline-primary" onClick={() => openEdit(s)} reason={busyReason} aria-label={`Editar ${s.name ?? "fornecedor"} no PingWin`}>
                <i className="ri-pencil-line" />
            </ReasonButton>
            <ActionsMenu size="sm" label={`Mais ações: ${s.name ?? "fornecedor"}`} items={[
                { label: "Anular no PingWin", icon: "ri-close-circle-line", danger: true, onClick: () => setVoidTarget(s), disabledReason: busyReason },
            ]} />
        </>
    ) : null;

    const columns: DTColumn<PingwinSupplier>[] = [
        { id: "code", header: "Código", value: (s) => s.code, cell: (s) => <span className="fw-medium">{s.code || "—"}</span>, nowrap: true, mobile: "subtitle" },
        {
            id: "name", header: "Nome", value: (s) => s.name, mobile: "title",
            cell: (s) => <>
                <div>{s.name || "—"}</div>
                {s.fiscal_name && s.fiscal_name !== s.name && <div className="text-muted fs-12">Fiscal: {s.fiscal_name}</div>}
            </>,
        },
        { id: "nif", header: "NIF", value: (s) => s.tax_number, nowrap: true },
        { id: "address", header: "Morada", value: (s) => fmtAddress(s), className: "xp-col-wrap" },
        { id: "contact", header: "Contacto", value: (s) => s.phone || s.email || null },
        {
            id: "status", header: "Estado", value: (s) => (s.is_active ? 1 : 0), align: "center",
            cell: (s) => s.is_active
                ? <span className="badge bg-success-subtle text-success">Ativo</span>
                : <span className="badge bg-secondary-subtle text-secondary">Inativo</span>,
        },
        { id: "fiscal_name", header: "Nome fiscal", value: (s) => s.fiscal_name, defaultVisible: false },
        { id: "phone", header: "Telefone", value: (s) => s.phone, defaultVisible: false, nowrap: true },
        { id: "email", header: "Email", value: (s) => s.email, defaultVisible: false },
        { id: "city", header: "Localidade", value: (s) => s.city, defaultVisible: false },
    ];
    const cols = useDataColumns("restauracao.fornecedores", columns);

    const field = (k: keyof PingwinSupplierForm, label: string, max: number, opts: { md?: number; required?: boolean; placeholder?: string } = {}) => (
        <Col md={opts.md ?? 6} className="mb-2">
            <Label className="form-label fs-12 mb-1">{label}{opts.required && <span className="text-danger"> *</span>}</Label>
            <Input bsSize="sm" value={form[k]} maxLength={max} placeholder={opts.placeholder} onChange={(e) => setField(k, e.target.value)} disabled={busy} />
        </Col>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Fornecedores"
                    breadcrumbs={[{ label: "Cadastros" }]}
                    info="Os fornecedores do PingWin. Pode criar, editar e anular: cada gravação é confirmada no PingWin."
                />

                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Fornecedores"
                            loading={loading && rows.length > 0}
                            status={<>
                                Última sincronização: {fmtDateTime(lastSynced)}
                                {pending && <span className="text-info ms-2"><Spinner size="sm" style={{ width: 10, height: 10 }} className="me-1" />A gravar no PingWin: {pending.label}…</span>}
                            </>}
                            actions={<>
                                {cols.selector}
                                <Button color="outline-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar</>}
                                </Button>
                                <ReasonButton color="primary" onClick={openCreate} reason={busyReason}>
                                    <i className="ri-add-line me-1" /> Novo fornecedor
                                </ReasonButton>
                            </>}
                            filters={
                                <RestFilterBar
                                    search={search}
                                    onSearchChange={setSearch}
                                    searchPlaceholder="Pesquisar (nome, código ou NIF)…"
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
                                rowKey={(s) => s.id}
                                loading={loading}
                                search={search}
                                initialSort={{ id: "name" }}
                                rowClassName={(s) => (s.is_active ? undefined : "text-muted")}
                                rowActions={rowActions}
                                caption="Fornecedores"
                                empty={{
                                    message: emptyMessage,
                                    action: activeFilter === "" ? <Button color="outline-primary" size="sm" onClick={runSync} disabled={syncing}><i className="ri-refresh-line me-1" />Sincronizar</Button> : undefined,
                                }}
                            />
                        </PageCard>
                    </Col>
                </Row>

                {/* Criar / Editar */}
                <Modal isOpen={!!modal} toggle={() => !busy && setModal(null)} size="lg" centered>
                    <ModalHeader toggle={() => !busy && setModal(null)}>
                        {modal?.mode === "create" ? "Novo fornecedor no PingWin" : `Editar fornecedor ${modal?.supplier?.code ?? ""}`}
                    </ModalHeader>
                    <ModalBody>
                        <Row>
                            {modal?.mode === "edit" && (
                                <Col md={3} className="mb-2">
                                    <Label className="form-label fs-12 mb-1">Código</Label>
                                    <Input bsSize="sm" value={modal.supplier?.code ?? ""} readOnly disabled />
                                </Col>
                            )}
                            {field("description", "Nome", 50, { md: modal?.mode === "edit" ? 9 : 12, required: true })}
                            {field("fiscalname", "Nome fiscal", 100)}
                            {field("tax_number", "NIF", 21, { md: 3 })}
                            <Col md={3} className="mb-2">
                                <Label className="form-label fs-12 mb-1">País</Label>
                                <Input bsSize="sm" value="Portugal" readOnly disabled />
                            </Col>
                            {field("address", "Morada", 150, { md: 12 })}
                            {field("postalcode", "Código postal", 20, { md: 3, placeholder: "4200-232" })}
                            {field("postalcode_description", "Localidade", 75, { md: 9, placeholder: "Porto" })}
                            <Col md={6} className="mb-2">
                                <Label className="form-label fs-12 mb-1">Condição de pagamento</Label>
                                <XSelect ariaLabel="Condição de pagamento" options={paycondOptions} value={form.paycond_id}
                                    onChange={(v) => setField("paycond_id", v)} disabled={busy} placeholder="— Sem condição —" />
                            </Col>
                            {field("obs", "Observações", 250)}
                        </Row>

                        {modal?.mode === "edit" && !modal.supplier?.paycond_pingwin_id && !modal.supplier?.obs && (
                            <div className="text-muted fs-12 mb-2">
                                <i className="ri-information-line me-1" />
                                A condição de pagamento e as observações deste fornecedor ainda não foram lidas do PingWin
                                (a lista não as traz). Só são alteradas se as mudares aqui; o resto mantém o valor que tem no PingWin.
                            </div>
                        )}
                        {nifLooksInvalid && (
                            <Alert color="warning" className="py-2 fs-13 mb-2">
                                O NIF <strong>{nif}</strong> não é um NIF português válido.
                                {nifInvalidAsked && (
                                    <div className="form-check mt-1">
                                        <input className="form-check-input" type="checkbox" id="sup-allow-invalid" checked={allowInvalidNif} onChange={(e) => setAllowInvalidNif(e.target.checked)} />
                                        <label className="form-check-label" htmlFor="sup-allow-invalid">É um NIF estrangeiro — gravar na mesma</label>
                                    </div>
                                )}
                            </Alert>
                        )}
                        {existing && (
                            <Alert color="warning" className="py-2 fs-13 mb-2">
                                Já existe um fornecedor com este NIF: <strong>{existing.code ? `${existing.code} — ` : ""}{existing.name}</strong>.
                                <div className="form-check mt-1">
                                    <input className="form-check-input" type="checkbox" id="sup-allow-dup" checked={allowDuplicateNif} onChange={(e) => setAllowDuplicateNif(e.target.checked)} />
                                    <label className="form-check-label" htmlFor="sup-allow-dup">Gravar mesmo assim (NIF repetido)</label>
                                </div>
                            </Alert>
                        )}
                        {formError && !existing && <Alert color="danger" className="py-2 fs-13 mb-0">{formError}</Alert>}
                        {pending && <div className="text-info fs-13 mt-2"><Spinner size="sm" className="me-1" />A gravar no PingWin e a confirmar…</div>}
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setModal(null)} disabled={busy}>Cancelar</Button>
                        <ReasonButton color="primary" onClick={save} disabled={busy} reason={saveReason}>
                            {busy ? <><Spinner size="sm" className="me-1" /> A gravar…</> : <><i className="ri-save-line me-1" /> Gravar no PingWin</>}
                        </ReasonButton>
                    </ModalFooter>
                </Modal>

                {/* Anular */}
                <Modal isOpen={!!voidTarget} toggle={() => !voiding && setVoidTarget(null)} centered>
                    <ModalHeader toggle={() => !voiding && setVoidTarget(null)}>Anular fornecedor</ModalHeader>
                    <ModalBody>
                        Anular <strong>{voidTarget?.code} — {voidTarget?.name}</strong> no PingWin? O fornecedor passa para os anulados
                        e deixa de aparecer nas listas.
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setVoidTarget(null)} disabled={voiding}>Cancelar</Button>
                        <Button color="danger" onClick={confirmVoid} disabled={voiding}>
                            {voiding ? <Spinner size="sm" /> : "Anular"}
                        </Button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}
