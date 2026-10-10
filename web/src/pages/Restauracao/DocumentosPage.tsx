import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    Container, Row, Col, Spinner, Label, Input, Button, FormGroup,
    Modal, ModalHeader, ModalBody, ModalFooter, Nav, NavItem, NavLink, TabContent, TabPane, Badge, UncontrolledTooltip,
} from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { fetchAllPages } from "helpers/fetchAllPages";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import {
    getPingwinDocuments, syncPingwinDocuments,
    syncPingwinDocumentsRich, getPingwinDocumentConfigDetail,
    updatePingwinDocumentConfig, getPingwinDocumentConfigWrite, createPingwinDocumentConfig,
    voidPingwinDocumentConfig,
} from "helpers/laravel_helper";
import { PingwinDocumentConfig, PingwinDocPaycondLink } from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

// D1 — selects editáveis do maindataset: {opção no snapshot, campo _id a gravar, label}.
const DOC_SELECTS: { opt: string; field: string; label: string }[] = [
    { opt: "taxscenario", field: "taxscenario_id", label: "Cenário fiscal" },
    { opt: "doctype", field: "doctype_id", label: "Tipo de documento" },
    { opt: "docfiscaltype", field: "docfiscaltype_id", label: "Tipo fiscal" },
    { opt: "docseries", field: "docseries_id", label: "Série" },
    { opt: "printzone", field: "printzone_id", label: "Zona de impressão" },
    { opt: "tax_round_mode", field: "tax_round_mode", label: "Arredondamento" },
    { opt: "contacttype", field: "contacttype_id", label: "Tipo de contacto" },
    { opt: "stock_signal", field: "stock_signal", label: "Sinal de stock" },
    { opt: "productgroup", field: "productgroup_id", label: "Grupo de produto" },
    { opt: "report", field: "report_id", label: "Template de impressão" },
];
// D1 — flags booleanos editáveis (0/1). ⚠️ "settled" ("Pago" no PingWin) SAIU daqui na S2:
// é ele que a Conta Corrente usa para separar os documentos auto-pagos (Saldo real), por
// isso fica SÓ DE LEITURA (PagoReadOnly) até haver uma fatia própria para o editar.
const DOC_FLAGS: { field: string; label: string }[] = [
    { field: "pending_qnt", label: "Qtd. pendente" },
    { field: "allowfifo", label: "Permite FIFO" },
    { field: "islocal", label: "É local" },
    { field: "notvalued", label: "Não valorizado" },
    { field: "set_price", label: "Define preço" },
    { field: "set_qnt", label: "Define qtd." },
    { field: "move_product", label: "Move produto" },
    { field: "move_document", label: "Move documento" },
    { field: "required_docreference", label: "Ref. documento obrigatória" },
    { field: "required_docmovreason", label: "Motivo obrigatório" },
    { field: "required_docsource", label: "Origem obrigatória" },
    { field: "account_use_totalpaid", label: "Usa total pago" },
];
/** "Pago" (maindataset.settled) — só leitura nesta fase; nunca vai na escrita. */
function PagoReadOnly({ value, idSuffix }: { value: any; idSuffix: string }) {
    const on = value === 1 || value === "1" || value === true;
    const id = `pago-ro-${idSuffix}`;
    return (
        <div id={id} className="form-check mb-1" style={{ cursor: "not-allowed" }}>
            <Input type="checkbox" className="form-check-input" id={`${id}-input`} checked={on} disabled readOnly />
            <Label check for={`${id}-input`} className="fs-13 text-muted">Pago</Label>
            <UncontrolledTooltip target={id}>Editável numa fase futura</UncontrolledTooltip>
        </div>
    );
}

const DOC_EDITABLE_FIELDS = ["description", "shortname", "number_copies",
    ...DOC_SELECTS.map((s) => s.field), ...DOC_FLAGS.map((f) => f.field)];

// D2c — selects de DEFAULT do maindataset. As options NÃO vêm do snapshot de valor único:
// vêm de blocos que a D0 já guarda (default_docsatatus/default_detailstatus) ou das condições
// de pagamento VINCULADAS (docconfig_paycond com deleted:0). linkedOnly → só vinculadas.
const DOC_DEFAULT_SELECTS: { field: string; src: string; idKey: string; label: string; linkedOnly?: boolean }[] = [
    { field: "default_docstatus_id", src: "default_docsatatus", idKey: "id", label: "Estado por omissão" },
    { field: "default_detailstatus_id", src: "default_detailstatus", idKey: "id", label: "Estado de detalhe por omissão" },
    { field: "default_paycond_id", src: "docconfig_paycond", idKey: "paycond_id", label: "Cond. pagamento por omissão", linkedOnly: true },
];

// D2a — as 9 filhas de marcação editáveis + a chave identificadora própria de cada uma.
const DOC_CHILD_IDKEY: Record<string, string> = {
    entitytype_docconfig: "entitytype_id",
    docconfig_detailstatus: "detailstatus_id",
    docconfig_docmovreason: "docmovreason_id",
    docconfig_docstatus: "docstatus_id",
    docconfig_local: "local_id",
    docconfig_import: "importdocconfig_id",
    docconfig_paymethod: "paymethod_id",
    docconfig_docreference: "docreference_id",
    docconfig_paycond: "paycond_id",
};
const normTxt = (s: string) => s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");

/**
 * XPLENDOR — Restauração › Documentos (Fase 1, só leitura). Tipos de documento
 * do PingWin numa tabela limpa e filtrável. Exibição PAGINADA (paginação
 * Laravel). Filtro com react-select (padrão do sistema), off-canvas em mobile, e
 * a tabela colapsa em cards no telemóvel (sem overflow horizontal).
 *
 * UI-2a: PageCard + DataTable. A pesquisa e o tipo de entidade vão à API (como antes); a lista
 * lê todas as páginas do resultado e a tabela ordena e pagina no browser.
 */

const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

const API_PER_PAGE = 200;
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
                <div className="text-muted fs-12">Nenhum vinculado.</div>
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

    const companyId = useWorkingCompanyId();

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
    // D1 — edição do maindataset (só tab Geral; filhas read-only).
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState<Record<string, any>>({});
    // D2a — estado das filhas editáveis: {filha: {id: vinculado?}} + estado inicial p/ diff.
    const [childState, setChildState] = useState<Record<string, Record<string, boolean>>>({});
    const [childOrig, setChildOrig] = useState<Record<string, Record<string, boolean>>>({});
    const [childSearch, setChildSearch] = useState<Record<string, string>>({});
    // D2b — estado contabilístico por conta: 'none'|'credit'|'debit'|'both'(read-only) + inicial p/ diff.
    const [daState, setDaState] = useState<Record<string, string>>({});
    const [daOrig, setDaOrig] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    // D3 — criar documento novo (modal próprio).
    const [showCreate, setShowCreate] = useState(false);
    const [createForm, setCreateForm] = useState<Record<string, any>>({});
    const [createOptions, setCreateOptions] = useState<Record<string, Array<Record<string, any>>>>({});
    const [creating2, setCreating2] = useState(false);
    // D4 — anular documento (confirmação explícita).
    const [voidTarget, setVoidTarget] = useState<PingwinDocumentConfig | null>(null);
    const [voiding, setVoiding] = useState(false);
    const pollRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    useEffect(() => () => { if (pollRef.current) clearTimeout(pollRef.current); }, []);

    const entityOptions: Opt[] = useMemo(
        () => [{ value: "", label: "Todas as entidades" }, ...entityTypes.map((t) => ({ value: t, label: t }))],
        [entityTypes]
    );

    const activeFilterCount = [!!entityFilter].filter(Boolean).length;

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        const params = { search: search.trim() || undefined, entitytype: entityFilter || undefined };
        try {
            const { rows: all, first } = await fetchAllPages<PingwinDocumentConfig>(
                (page) => getPingwinDocuments(companyId, { ...params, page, perPage: API_PER_PAGE }), (r) => r?.data?.documents);
            setRows(all);
            setEntityTypes(first?.data?.entitytypes ?? []);
            setLastSynced(first?.data?.last_synced_at ?? null);
        } catch {
            setRows([]);
        } finally {
            setLoading(false);
        }
    }, [companyId, search, entityFilter]);

    useEffect(() => {
        const t = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(t);
    }, [fetchRows]);

    const clearFilters = () => { setSearch(""); setEntityFilter(""); };

    const runSync = async () => {
        if (!companyId) return;
        setSyncing(true);
        try {
            await syncPingwinDocuments(companyId);
            toast.info("A sincronizar documentos… será notificado no sino quando terminar.");
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
            toast.info("A obter a config completa dos documentos… será notificado no sino quando terminar.");
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
        setEditing(false);
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

    // Entra em modo edição: pré-preenche o form (maindataset) + o estado das 9 filhas editáveis.
    const startEdit = () => {
        const r = detailDoc?.raw || {};
        const f: Record<string, any> = {};
        DOC_EDITABLE_FIELDS.forEach((k) => { f[k] = r[k] ?? ""; });
        DOC_FLAGS.forEach((fl) => { f[fl.field] = (r[fl.field] === 1 || r[fl.field] === "1") ? 1 : 0; });
        DOC_DEFAULT_SELECTS.forEach((d) => { f[d.field] = r[d.field] ?? ""; });   // D2c
        setForm(f);
        // filhas: vinculado = deleted:0. Guarda estado inicial para o diff.
        const cs: Record<string, Record<string, boolean>> = {};
        Object.entries(DOC_CHILD_IDKEY).forEach(([child, idk]) => {
            const map: Record<string, boolean> = {};
            ((detailDoc as any)?.[child] ?? []).forEach((row: any) => {
                map[String(row[idk])] = !(row.deleted === 1 || row.deleted === "1" || row.deleted === true);
            });
            cs[child] = map;
        });
        setChildState(cs);
        setChildOrig(JSON.parse(JSON.stringify(cs)));
        setChildSearch({});
        // docaccount (D2b): estado por conta a partir dos 3 flags do vivo.
        const da: Record<string, string> = {};
        ((detailDoc as any)?.docconfig_docaccount ?? []).forEach((r: any) => {
            const c = r.credit === 1 || r.credit === "1", d = r.debit === 1 || r.debit === "1";
            da[String(r.docaccount_id)] = c && d ? "both" : c ? "credit" : d ? "debit" : "none";
        });
        setDaState(da);
        setDaOrig({ ...da });
        setDetailTab("geral");
        setEditing(true);
    };

    // D2b — estado→3 flags (coerente). 'both' nunca é enviado pela UI.
    const daFlags = (state: string): { deleted: number; credit: number; debit: number } =>
        state === "credit" ? { deleted: 0, credit: 1, debit: 0 }
            : state === "debit" ? { deleted: 0, credit: 0, debit: 1 }
            : { deleted: 1, credit: 0, debit: 0 };   // none

    const setField = (k: string, v: any) => setForm((p) => ({ ...p, [k]: v }));
    const toggleChild = (child: string, id: string, checked: boolean) =>
        setChildState((p) => ({ ...p, [child]: { ...(p[child] || {}), [id]: checked } }));

    // D2a — lista editável de uma filha de marcação (checkboxes; pesquisa opcional p/ import).
    const renderEditableChild = (child: string, label: string, searchable = false) => {
        const idk = DOC_CHILD_IDKEY[child];
        const rows: any[] = (detailDoc as any)?.[child] ?? [];
        const q = normTxt(childSearch[child] || "");
        const shown = q ? rows.filter((r) => normTxt(String(r.description || "")).includes(q) || normTxt(String(r[idk] || "")).includes(q)) : rows;
        const cs = childState[child] || {};
        const linkedCount = rows.filter((r) => cs[String(r[idk])]).length;
        return (
            <div className="mb-3" key={child}>
                <div className="d-flex align-items-center justify-content-between mb-1">
                    <span className="fw-semibold fs-13">{label}</span>
                    <span className="text-muted fs-12">{linkedCount}/{rows.length} vinculados</span>
                </div>
                {searchable && (
                    <Input bsSize="sm" className="mb-2" placeholder="Pesquisar documento…" style={{ maxWidth: 320 }}
                        value={childSearch[child] || ""} onChange={(e) => setChildSearch((p) => ({ ...p, [child]: e.target.value }))} />
                )}
                <div style={{ maxHeight: searchable ? 240 : undefined, overflowY: searchable ? "auto" : undefined }}>
                    <Row>
                        {shown.map((r, i) => { const id = String(r[idk]); return (
                            <Col md={6} key={i}>
                                <FormGroup check className="mb-1">
                                    <Input type="checkbox" id={`c-${child}-${id}`} checked={!!cs[id]} onChange={(e) => toggleChild(child, id, e.target.checked)} />
                                    <Label check for={`c-${child}-${id}`} className="fs-13">{r.description || id}</Label>
                                </FormGroup>
                            </Col>
                        ); })}
                        {shown.length === 0 && <Col><div className="text-muted fs-12">Nenhum resultado.</div></Col>}
                    </Row>
                </div>
            </div>
        );
    };

    // D2b — editor do docaccount: por conta, 3 estados (Não usada / Crédito / Débito).
    // Uma conta que venha com ambos=1 do vivo mostra "Outro (C+D)" até ser mudada.
    const renderDocaccountEditor = () => {
        const rows: any[] = (detailDoc as any)?.docconfig_docaccount ?? [];
        const used = rows.filter((r) => { const s = daState[String(r.docaccount_id)]; return s && s !== "none"; }).length;
        return (
            <div className="mb-3">
                <div className="d-flex align-items-center justify-content-between mb-1">
                    <span className="fw-semibold fs-13">Contas de documento (crédito/débito)</span>
                    <span className="text-muted fs-12">{used}/{rows.length} usadas</span>
                </div>
                <Row>
                    {rows.map((r, i) => {
                        const id = String(r.docaccount_id);
                        const st = daState[id] || "none";
                        return (
                            <Col md={6} key={i} className="mb-2 d-flex align-items-center gap-2">
                                <span style={{ minWidth: 130 }} className="fs-13">{r.description || id}</span>
                                <div className="flex-grow-1" style={{ minWidth: 0 }}>
                                    <XSelect ariaLabel={`Uso da conta ${r.description || id}`} value={st}
                                        onChange={(v) => setDaState((p) => ({ ...p, [id]: v }))}
                                        options={[
                                            ...(st === "both" ? [{ value: "both", label: "Outro (crédito+débito)" }] : []),
                                            { value: "none", label: "Não usada" },
                                            { value: "credit", label: "Crédito" },
                                            { value: "debit", label: "Débito" },
                                        ]} />
                                </div>
                            </Col>
                        );
                    })}
                </Row>
            </div>
        );
    };

    const pollWrite = useCallback((writeId: number, externalId: string) => {
        getPingwinDocumentConfigWrite(companyId, writeId)
            .then((res: any) => {
                const status = res?.data?.status;
                if (status === "a_criar") {
                    pollRef.current = setTimeout(() => pollWrite(writeId, externalId), 1500);
                    return;
                }
                setSaving(false);
                if (status === "ok") {
                    toast.success("Documento atualizado no PingWin.");
                    setEditing(false);
                    openDetail(externalId);   // recarrega o detalhe com os valores confirmados
                    fetchRows();
                } else {
                    toast.error(res?.data?.error_message || "Não foi possível atualizar o documento.");
                }
            })
            .catch(() => { setSaving(false); toast.error("Falha a consultar o estado da edição."); });
    }, [companyId]);   // eslint-disable-line react-hooks/exhaustive-deps

    const saveEdit = async () => {
        if (!companyId || !detailDoc) return;
        const extId = detailDoc.external_id;
        // Envia só os campos editáveis (o backend aplica o whitelist e preserva as filhas).
        const fields: Record<string, any> = {};
        DOC_EDITABLE_FIELDS.forEach((k) => { if (form[k] !== undefined && form[k] !== "") fields[k] = form[k]; });
        DOC_FLAGS.forEach((fl) => { fields[fl.field] = form[fl.field] ? 1 : 0; });
        // D2c — defaults: emitir sempre (incl. "" = sem default) para permitir limpar.
        DOC_DEFAULT_SELECTS.forEach((d) => { fields[d.field] = form[d.field] ?? ""; });
        if (form.description !== undefined) fields.description = form.description;
        if (form.shortname !== undefined) fields.shortname = form.shortname;
        // D2a — diff das filhas: só os ids cujo estado mudou vs o inicial.
        const children: Record<string, Array<{ id: string; deleted: number }>> = {};
        Object.keys(DOC_CHILD_IDKEY).forEach((child) => {
            const now = childState[child] || {};
            const orig = childOrig[child] || {};
            const diff: Array<{ id: string; deleted: number }> = [];
            Object.keys(now).forEach((id) => {
                if (!!now[id] !== !!orig[id]) diff.push({ id, deleted: now[id] ? 0 : 1 });
            });
            if (diff.length) children[child] = diff;
        });
        // D2b — diff do docaccount: contas cujo estado mudou (nunca envia 'both').
        const docaccount: Array<{ docaccount_id: string; deleted: number; credit: number; debit: number }> = [];
        Object.keys(daState).forEach((id) => {
            if (daState[id] !== daOrig[id] && daState[id] !== "both") {
                docaccount.push({ docaccount_id: id, ...daFlags(daState[id]) });
            }
        });
        setSaving(true);
        try {
            const res: any = await updatePingwinDocumentConfig(companyId, extId, fields, children, docaccount);
            const writeId = res?.data?.write_id;
            if (!writeId) { setSaving(false); toast.error("Resposta inesperada ao gravar."); return; }
            toast.info("A atualizar no PingWin… a confirmar.");
            pollWrite(writeId, extId);
        } catch (e: any) {
            setSaving(false);
            toast.error(e?.message ?? "Não foi possível atualizar o documento.");
        }
    };

    // D3 — abre o modal de criar; empresta as options dos selects do detalhe de um documento
    // existente (são install-wide, iguais a todos). Sem configuração de filhas (fazem-se depois).
    const openCreate = async () => {
        if (!companyId) return;
        setCreateForm({ code: "", description: "", shortname: "", doctype_id: "", docfiscaltype_id: "", stock_signal: "", contacttype_id: "", taxscenario_id: "", docseries_id: "" });
        setCreateOptions({});
        setShowCreate(true);
        const ref = rows[0]?.external_id;
        if (ref) {
            try {
                const res: any = await getPingwinDocumentConfigDetail(companyId, ref);
                setCreateOptions(res?.data?.document?.options ?? {});
            } catch { /* segue sem options — o utilizador ainda cria com code/descrição */ }
        }
    };

    const pollCreateWrite = useCallback((writeId: number) => {
        getPingwinDocumentConfigWrite(companyId, writeId)
            .then((res: any) => {
                const status = res?.data?.status;
                if (status === "a_criar") { pollRef.current = setTimeout(() => pollCreateWrite(writeId), 1500); return; }
                setCreating2(false);
                if (status === "ok") {
                    toast.success("Documento criado no PingWin. Abra-o em “Ver detalhe” para configurar as tabelas.");
                    setShowCreate(false);
                    fetchRows();
                } else {
                    toast.error(res?.data?.error_message || "Não foi possível criar o documento.");
                }
            })
            .catch(() => { setCreating2(false); toast.error("Falha a consultar o estado da criação."); });
    }, [companyId, fetchRows]);

    // D4 — anular só após confirmação explícita (nomeia o documento).
    const pollVoidWrite = useCallback((writeId: number) => {
        getPingwinDocumentConfigWrite(companyId, writeId)
            .then((res: any) => {
                const status = res?.data?.status;
                if (status === "a_criar") { pollRef.current = setTimeout(() => pollVoidWrite(writeId), 1500); return; }
                setVoiding(false);
                if (status === "ok") {
                    toast.success("Documento anulado no PingWin.");
                    setVoidTarget(null);
                    fetchRows();
                } else {
                    toast.error(res?.data?.error_message || "Não foi possível anular o documento.");
                }
            })
            .catch(() => { setVoiding(false); toast.error("Falha a consultar o estado da anulação."); });
    }, [companyId, fetchRows]);

    const confirmVoid = async () => {
        if (!companyId || !voidTarget) return;
        setVoiding(true);
        try {
            const res: any = await voidPingwinDocumentConfig(companyId, voidTarget.external_id);
            const writeId = res?.data?.write_id;
            if (!writeId) { setVoiding(false); toast.error("Resposta inesperada ao anular."); return; }
            toast.info("A anular no PingWin… a confirmar.");
            pollVoidWrite(writeId);
        } catch (e: any) {
            setVoiding(false);
            toast.error(e?.message ?? "Não foi possível anular o documento.");
        }
    };

    const submitCreate = async () => {
        if (!companyId) return;
        if (!createForm.description?.trim()) { toast.error("A descrição é obrigatória."); return; }
        const fields: Record<string, any> = {};
        ["code", "description", "shortname", "doctype_id", "docfiscaltype_id", "stock_signal", "contacttype_id", "taxscenario_id", "docseries_id"]
            .forEach((k) => { if (createForm[k] !== undefined && createForm[k] !== "") fields[k] = createForm[k]; });
        fields.description = createForm.description.trim();
        setCreating2(true);
        try {
            const res: any = await createPingwinDocumentConfig(companyId, fields);
            const writeId = res?.data?.write_id;
            if (!writeId) { setCreating2(false); toast.error("Resposta inesperada ao criar."); return; }
            toast.info("A criar no PingWin… a confirmar.");
            pollCreateWrite(writeId);
        } catch (e: any) {
            setCreating2(false);
            toast.error(e?.message ?? "Não foi possível criar o documento.");
        }
    };

    const emptyMessage = !search && !entityFilter
        ? "Ainda não há documentos. Sincronize a lista para os obter do PingWin."
        : "Nenhum resultado para o filtro.";

    const filterFields = (
        <div style={{ flex: "1 1 220px", minWidth: 0 }}>
            <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>Tipo de entidade</Label>
            <XSelect
                ariaLabel="Tipo de entidade"
                small
                options={entityOptions}
                value={entityFilter}
                onChange={(v) => setEntityFilter(v)}
                searchable
                placeholder="Todas as entidades"
            />
        </div>
    );

    // Ações raras/destrutivas da linha: "Anular" abre o modal de confirmação (D4).
    const rowMenu = (d: PingwinDocumentConfig) => (
        <ActionsMenu size="sm" label={`Mais ações: ${d.description || d.code}`} items={[
            { label: "Anular", icon: "ri-forbid-2-line", danger: true, hidden: !!d.deleted, onClick: () => setVoidTarget(d) },
        ]} />
    );

    const columns: DTColumn<PingwinDocumentConfig>[] = [
        { id: "code", header: "Código", value: (d) => d.code, cell: (d) => <span className="fw-medium">{d.code || "—"}</span>, mobile: "subtitle" },
        { id: "description", header: "Descrição", value: (d) => d.description, mobile: "title" },
        {
            id: "entity", header: "Tipo de entidade", value: (d) => d.entitytype,
            cell: (d) => d.entitytype ? <span className="badge bg-info-subtle text-info">{d.entitytype}</span> : <span className="text-muted">—</span>,
        },
        { id: "fiscal", header: "Tipo fiscal", value: (d) => d.fiscaltype_description || d.fiscaltype || null },
    ];
    const cols = useDataColumns("restauracao.documentos", columns);

    // Ações por linha: "Ver detalhe" visível; "Anular" (destrutiva) só no menu "...".
    const rowActions = (d: PingwinDocumentConfig) => (
        <>
            <Button size="sm" color="outline-primary" onClick={() => openDetail(d.external_id)} title="Ver config completa" aria-label={`Ver detalhe: ${d.description || d.code}`}>
                <i className="ri-eye-line" />
            </Button>
            {rowMenu(d)}
        </>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Documentos"
                    breadcrumbs={[{ label: "Cadastros" }]}
                    info="Os tipos de documento do PingWin e a configuração de cada um."
                />

                <Row>
                    <Col xs={12}>
                        <PageCard
                            title="Tipos de documento"
                            loading={loading && rows.length > 0}
                            status={<>Última sincronização: {fmtDateTime(lastSynced)}</>}
                            actions={<>
                                {cols.selector}
                                <Button size="sm" color="outline-primary" onClick={runSync} disabled={syncing}>
                                    {syncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar lista</>}
                                </Button>
                                <ActionsMenu size="sm" label="Mais ações: Documentos" items={[
                                    {
                                        label: syncingRich ? "A obter detalhe…" : "Sincronizar detalhe", icon: "ri-stack-line", onClick: runSyncRich,
                                        disabledReason: syncingRich ? "A sincronização do detalhe já está a correr." : null,
                                    },
                                ]} />
                                <Button size="sm" color="primary" onClick={openCreate} disabled={creating2}>
                                    <i className="ri-add-line me-1" /> Novo documento
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
                                rowKey={(d) => d.id}
                                loading={loading}
                                rowActions={rowActions}
                                caption="Tipos de documento"
                                empty={{
                                    message: emptyMessage,
                                    action: !search && !entityFilter ? <Button color="outline-primary" size="sm" onClick={runSync} disabled={syncing}><i className="ri-refresh-line me-1" />Sincronizar lista</Button> : undefined,
                                }}
                            />
                        </PageCard>
                    </Col>
                </Row>
            </Container>

            {/* Fase D0 — Detalhe RICO do documento (read-only), em tabs (como no PingWin). */}
            <Modal isOpen={detailOpen} toggle={() => setDetailOpen(false)} size="xl" scrollable>
                <ModalHeader toggle={() => setDetailOpen(false)}>
                    {detailDoc ? <>Documento {detailDoc.code}: {detailDoc.description}</> : "Detalhe do documento"}
                    {detailLoading && <Spinner size="sm" className="ms-2" />}
                </ModalHeader>
                <ModalBody>
                    {!detailDoc ? (
                        <div className="text-muted py-3">{detailLoading ? "A carregar…" : "Sem dados. Execute primeiro “Sincronizar detalhe” (no menu de ações da página)."}</div>
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
                                    {!editing ? (
                                        <>
                                            <div className="d-flex justify-content-end mb-2">
                                                {!detailDoc.deleted && (
                                                    <Button size="sm" color="outline-primary" onClick={startEdit}>
                                                        <i className="ri-pencil-line me-1" /> Editar
                                                    </Button>
                                                )}
                                            </div>
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
                                                    <Col md={4} className="mb-2"><PagoReadOnly value={r.settled} idSuffix="view" /></Col>
                                                </Row>
                                            ); })()}
                                            <div className="text-muted fs-12 mt-2">maindataset: {Object.keys(detailDoc.raw || {}).length} campos · snapshot de options: {Object.keys(detailDoc.options || {}).length} selects.</div>
                                        </>
                                    ) : (
                                        <>
                                            <div className="alert alert-info py-2 fs-13">A editar o <strong>maindataset</strong>. As 14 tabelas filhas e os campos adicionais são preservados (edição na D2). O código é read-only.</div>
                                            <Row>
                                                <Col md={4} className="mb-2"><Label className="fs-12 text-muted">Código (read-only)</Label><Input value={detailDoc.code || ""} disabled /></Col>
                                                <Col md={4} className="mb-2"><Label className="fs-12 text-muted">Descrição</Label><Input value={form.description ?? ""} onChange={(e) => setField("description", e.target.value)} maxLength={120} /></Col>
                                                <Col md={4} className="mb-2"><Label className="fs-12 text-muted">Nome curto</Label><Input value={form.shortname ?? ""} onChange={(e) => setField("shortname", e.target.value)} maxLength={60} /></Col>
                                                <Col md={4} className="mb-2"><Label className="fs-12 text-muted">Nº de cópias</Label><Input type="number" min={0} value={form.number_copies ?? ""} onChange={(e) => setField("number_copies", e.target.value)} /></Col>
                                                {DOC_SELECTS.map((s) => {
                                                    const opts = (detailDoc.options?.[s.opt] ?? []) as Array<Record<string, any>>;
                                                    return (
                                                        <Col md={4} className="mb-2" key={s.field}>
                                                            <Label className="fs-12 text-muted">{s.label}</Label>
                                                            <XSelect ariaLabel={s.label} value={String(form[s.field] ?? "")} onChange={(v) => setField(s.field, v)}
                                                                options={[
                                                                    { value: "", label: "Nenhum" },
                                                                    ...opts.map((o) => ({ value: String(o.id), label: String(o.description || o.id) })),
                                                                    /* valor atual fora das options (raro) mantém-se visível */
                                                                    ...(form[s.field] && !opts.some((o) => String(o.id) === String(form[s.field]))
                                                                        ? [{ value: String(form[s.field]), label: String(form[s.field]) }] : []),
                                                                ]} />
                                                        </Col>
                                                    );
                                                })}
                                                {/* D2c — selects de DEFAULT (options de blocos, não do snapshot de valor único) */}
                                                {DOC_DEFAULT_SELECTS.map((d) => {
                                                    const src: any[] = (detailDoc as any)?.[d.src] ?? [];
                                                    const rows = d.linkedOnly ? src.filter((r) => !(r.deleted === 1 || r.deleted === "1" || r.deleted === true)) : src;
                                                    let options = rows.map((r) => ({ value: String(r[d.idKey] ?? ""), label: String(r.description || "").trim() || "Nenhum" }));
                                                    if (!options.some((o) => o.value === "")) options = [{ value: "", label: "Nenhum" }, ...options];
                                                    const cur = String(form[d.field] ?? "");
                                                    if (cur && !options.some((o) => o.value === cur)) options = [...options, { value: cur, label: `${cur} (atual)` }];
                                                    return (
                                                        <Col md={4} className="mb-2" key={d.field}>
                                                            <Label className="fs-12 text-muted">{d.label}</Label>
                                                            <XSelect ariaLabel={d.label} value={cur} onChange={(v) => setField(d.field, v)} options={options} />
                                                        </Col>
                                                    );
                                                })}
                                            </Row>
                                            <div className="mt-2 mb-1 fw-semibold fs-13">Flags</div>
                                            <Row>
                                                {DOC_FLAGS.map((fl) => (
                                                    <Col md={4} key={fl.field}>
                                                        <FormGroup check className="mb-1">
                                                            <Input type="checkbox" id={`flag-${fl.field}`} checked={!!form[fl.field]} onChange={(e) => setField(fl.field, e.target.checked ? 1 : 0)} />
                                                            <Label check for={`flag-${fl.field}`} className="fs-13">{fl.label}</Label>
                                                        </FormGroup>
                                                    </Col>
                                                ))}
                                                <Col md={4}><PagoReadOnly value={detailDoc?.raw?.settled} idSuffix="edit" /></Col>
                                            </Row>
                                        </>
                                    )}
                                </TabPane>

                                <TabPane tabId="definicoes">
                                    {editing ? (
                                        <>
                                            {renderEditableChild("entitytype_docconfig", "Tipos de entidade")}
                                            {renderEditableChild("docconfig_docstatus", "Estados de documento")}
                                            {renderEditableChild("docconfig_detailstatus", "Estados de detalhe")}
                                            {renderEditableChild("docconfig_docmovreason", "Motivos de movimento")}
                                            {renderEditableChild("docconfig_paymethod", "Métodos de pagamento")}
                                            {renderEditableChild("docconfig_docreference", "Referências de documento")}
                                            {renderEditableChild("docconfig_import", "Importação", true)}
                                            {renderDocaccountEditor()}
                                            <div className="alert alert-light border py-2 fs-12 mt-2">
                                                <i className="ri-lock-line me-1" />Perfis de utilizador são read-only aqui (fatia própria).
                                            </div>
                                            <ChildBlock title="Perfis de utilizador (só leitura)" rows={detailDoc.userrole_docconfig} />
                                        </>
                                    ) : (
                                      <>
                                    <ChildBlock title="Tipos de entidade" rows={detailDoc.entitytype_docconfig} />
                                    <ChildBlock title="Estados de documento" rows={detailDoc.docconfig_docstatus} />
                                    <ChildBlock title="Estados de detalhe" rows={detailDoc.docconfig_detailstatus} />
                                    <ChildBlock title="Motivos de movimento" rows={detailDoc.docconfig_docmovreason} />
                                    <ChildBlock title="Contas (crédito/débito)" rows={detailDoc.docconfig_docaccount} showAccount />
                                    <ChildBlock title="Métodos de pagamento" rows={detailDoc.docconfig_paymethod} />
                                    <ChildBlock title="Importação" rows={detailDoc.docconfig_import} />
                                    <ChildBlock title="Referências de documento" rows={detailDoc.docconfig_docreference} />
                                    <ChildBlock title="Perfis de utilizador" rows={detailDoc.userrole_docconfig} />
                                      </>
                                    )}
                                </TabPane>

                                <TabPane tabId="paycond">
                                    {editing ? (
                                        renderEditableChild("docconfig_paycond", "Condições de pagamento vinculadas")
                                    ) : detailLinks.length === 0 ? (
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
                                    {editing ? (
                                        <>
                                            {renderEditableChild("docconfig_local", "Locais")}
                                            <div className="alert alert-light border py-2 fs-12 mt-2"><i className="ri-lock-line me-1" />Lojas (store_docconfig) e config adicional por loja são read-only aqui (fatia D2c).</div>
                                            <ChildBlock title="Lojas (store_docconfig, só leitura)" rows={detailDoc.store_docconfig} />
                                        </>
                                    ) : (
                                        <>
                                            <ChildBlock title="Lojas (store_docconfig)" rows={detailDoc.store_docconfig} />
                                            <ChildBlock title="Locais" rows={detailDoc.docconfig_local} />
                                            <div className="text-muted fs-12 mt-1">Config adicional por loja (storedataset): {(detailDoc.additionalfields_storedataset || []).length} linha(s).</div>
                                        </>
                                    )}
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
                {detailDoc && editing && (
                    <ModalFooter>
                        <Button color="light" onClick={() => setEditing(false)} disabled={saving}>Cancelar</Button>
                        <Button color="primary" onClick={saveEdit} disabled={saving}>
                            {saving ? <><Spinner size="sm" className="me-1" /> A gravar…</> : "Guardar alterações"}
                        </Button>
                    </ModalFooter>
                )}
            </Modal>

            {/* D3 — Modal NOVO DOCUMENTO (maindataset principal; filhas configuram-se depois por "Editar"). */}
            <Modal isOpen={showCreate} toggle={() => !creating2 && setShowCreate(false)} size="lg" scrollable>
                <ModalHeader toggle={() => !creating2 && setShowCreate(false)}>Novo documento</ModalHeader>
                <ModalBody>
                    <div className="alert alert-info py-2 fs-13">
                        Crie o documento com os campos principais. As 14 tabelas (contas, estados, condições, etc.) ficam no estado inicial e configuram-se depois em <strong>“Ver detalhe → Editar”</strong>.
                    </div>
                    <Row>
                        <Col md={3} className="mb-2">
                            <Label className="fs-12 text-muted">Código (máx. 5)</Label>
                            <Input value={createForm.code ?? ""} maxLength={5} placeholder="(auto se vazio)"
                                onChange={(e) => setCreateForm((p) => ({ ...p, code: e.target.value }))} />
                        </Col>
                        <Col md={5} className="mb-2">
                            <Label className="fs-12 text-muted">Descrição *</Label>
                            <Input value={createForm.description ?? ""} maxLength={120}
                                onChange={(e) => setCreateForm((p) => ({ ...p, description: e.target.value }))} />
                        </Col>
                        <Col md={4} className="mb-2">
                            <Label className="fs-12 text-muted">Nome curto</Label>
                            <Input value={createForm.shortname ?? ""} maxLength={60}
                                onChange={(e) => setCreateForm((p) => ({ ...p, shortname: e.target.value }))} />
                        </Col>
                        {DOC_SELECTS.filter((s) => ["doctype_id", "docfiscaltype_id", "stock_signal", "contacttype_id", "taxscenario_id", "docseries_id"].includes(s.field)).map((s) => {
                            const opts = (createOptions?.[s.opt] ?? []) as Array<Record<string, any>>;
                            return (
                                <Col md={4} className="mb-2" key={s.field}>
                                    <Label className="fs-12 text-muted">{s.label}</Label>
                                    <XSelect ariaLabel={s.label} value={String(createForm[s.field] ?? "")}
                                        onChange={(v) => setCreateForm((p) => ({ ...p, [s.field]: v }))}
                                        options={[{ value: "", label: "Nenhum" }, ...opts.map((o) => ({ value: String(o.id), label: String(o.description || o.id) }))]} />
                                </Col>
                            );
                        })}
                    </Row>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setShowCreate(false)} disabled={creating2}>Cancelar</Button>
                    <ReasonButton color="primary" onClick={submitCreate} disabled={creating2} reason={!createForm.description?.trim() ? "Indique a descrição." : null}>
                        {creating2 ? <><Spinner size="sm" className="me-1" /> A criar…</> : "Criar documento"}
                    </ReasonButton>
                </ModalFooter>
            </Modal>

            {/* D4 — Confirmação EXPLÍCITA de anular (nomeia o documento; irreversível pela app). */}
            <Modal isOpen={!!voidTarget} toggle={() => !voiding && setVoidTarget(null)} centered>
                <ModalHeader toggle={() => !voiding && setVoidTarget(null)}>Anular documento</ModalHeader>
                <ModalBody>
                    {voidTarget && (
                        <>
                            <p className="mb-2">
                                Anular o documento <strong>«{voidTarget.description || "—"}»</strong> (código <strong>{voidTarget.code || "—"}</strong>)?
                            </p>
                            <p className="text-danger mb-0 fs-13">
                                <i className="ri-error-warning-line me-1" />
                                Isto torna o documento <strong>inativo</strong> no PingWin. A ação <strong>não é reversível pela aplicação</strong>.
                            </p>
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setVoidTarget(null)} disabled={voiding}>Cancelar</Button>
                    <Button color="danger" onClick={confirmVoid} disabled={voiding}>
                        {voiding ? <><Spinner size="sm" className="me-1" /> A anular…</> : "Anular documento"}
                    </Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
