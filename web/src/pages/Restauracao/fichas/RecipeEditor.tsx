import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import Skeleton from "Components/Common/Skeleton";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import InfoTip from "Components/Common/InfoTip";
import { useCan } from "contexts/ModulesContext";
import { deleteRecipeDraft, getRecipeEditor, previewRecipe, saveRecipeDraft, writeRecipe } from "helpers/laravel_helper";
import {
    RecipeComponentHit, RecipeDraftLine, RecipeDraftPayload, RecipeEditorContext, RecipePreview, RecipeUnitOption, RecipeWriteInfo, RecipeWriteMode,
} from "common/models/recipe.model";
import { Money, fmtDate, fmtPct, fmtQty } from "./recipeFormat";
import { WarningTip } from "./warningInfo";
import ComponentPicker from "./ComponentPicker";
import { OPERATION, WriteSteps, isFinal, useWriteProgress } from "./WriteProgressModal";
import { parseNum } from "./ConfirmFactorModal";

/**
 * Fatia 4 — o editor da ficha técnica, num modal grande (ecrã inteiro no telemóvel). É o mesmo no
 * detalhe de Restauração › Fichas técnicas e no separador "Ficha técnica" do artigo.
 *  · Cabeçalho fixo: o artigo, a versão, o modo ("Corrigir esta versão", por omissão, ou "Nova
 *    versão a partir de" uma data) e as lojas da versão, com o aviso de loja sem ficha.
 *  · Corpo: as linhas editáveis, adicionar componente, apagar linha.
 *  · Rodapé fixo: custo total vivo, food cost e confiança; "Cancelar" e "Gravar no PingWin".
 * As alterações ficam num rascunho na XPLENDOR (RecipeDraft) até gravar; ao reabrir, pergunta se o
 * quer retomar. A gravação corre em fila, uma ficha de cada vez, confirmada por releitura, e o
 * progresso aparece dentro deste modal; no fim, o modal fecha e a ficha por trás atualiza.
 * Com a guarda PINGWIN_BOM_WRITE_ALLOWLIST ativa (dev), "Gravar no PingWin" fica desativado com o
 * porquê num (i): nunca deixa tentar para dar erro.
 */
let uidSeq = 0;
const uid = () => `l${++uidSeq}`;
/** Quantidade: tem de ser > 0 (NaN se não for número). */
const num = (v: string) => (v.trim() === "" ? 0 : parseNum(v) ?? NaN);
/** Percentagens (desperdício, recuperação): 0 é válido; vazio = 0. */
const pct = (v: string) => {
    if (v.trim() === "") return 0;
    const n = Number(v.trim().replace(/\s/g, "").replace(",", "."));
    return Number.isFinite(n) && n >= 0 ? n : NaN;
};
const decimal = (n: number) => String(n).replace(".", ",");

function fromVersion(ctx: RecipeEditorContext, mode: RecipeWriteMode, bomId: string | null): RecipeDraftPayload {
    const v = ctx.versions.find((x) => x.id === bomId) ?? null;
    const stores: Record<string, boolean> = {};
    for (const s of ctx.stores) stores[s.store_id] = v ? !!v.stores.find((x) => x.store_id === s.store_id)?.active : true;
    return {
        mode, bom_id: v?.id ?? null, valid_from: ctx.today, stores,
        lines: (v?.lines ?? []).map((l) => ({
            uid: uid(), key: mode === "corrigir" ? l.key : null, component_id: l.component_id, description: l.description ?? l.component_id,
            code: null, unit_id: l.unit_id, units: l.units, qnt: decimal(l.qnt), waste: decimal(l.waste), recovery: decimal(l.recovery), advanced: l.recovery > 0,
        })),
    };
}

/** A versão que se corrige por omissão: a pedida, senão a em vigor, senão a mais recente. */
const defaultVersion = (ctx: RecipeEditorContext, bomId?: string | null) =>
    (bomId && ctx.versions.some((v) => v.id === bomId) ? bomId : null) ?? ctx.in_force ?? ctx.versions[ctx.versions.length - 1]?.id ?? null;

const unitLabel = (u: RecipeUnitOption, base?: RecipeUnitOption) =>
    u.base ? u.label : `${u.label} (= ${fmtQty(u.factor, 4)} ${base?.label ?? "base"})${u.suspicious ? ", fator 1?" : ""}`;

const MODE_LABEL: Record<RecipeWriteMode, string> = { criar: "Criar a ficha", nova_versao: "Nova versão", corrigir: "Corrigir a versão" };

type Phase = "loading" | "resume" | "edit" | "confirm" | "progress";

export default function RecipeEditorModal({ companyId, productId, bomId, hidden: hiddenProp, onSaved, onClose }: {
    companyId: number | null; productId: string;
    /** A versão que o ecrã está a mostrar: é a que se corrige por omissão. */
    bomId?: string | null;
    /** Sem Finanças: os euros desfocados. Sem a prop, segue a resposta da pré-visualização. */
    hidden?: boolean;
    /** A ficha por trás tem de recarregar (gravou, ou gravou sem bater certo). */
    onSaved: () => void;
    onClose: () => void;
}) {
    const [canEdit, editReason] = useCan("restauracao.editar");
    const [phase, setPhase] = useState<Phase>("loading");
    const [ctx, setCtx] = useState<RecipeEditorContext | null>(null);
    const [draft, setDraft] = useState<RecipeDraftPayload | null>(null);
    const [stale, setStale] = useState(false);
    const [preview, setPreview] = useState<RecipePreview | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [savedAt, setSavedAt] = useState<string | null>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [askClose, setAskClose] = useState(false);
    const [sending, setSending] = useState(false);
    const [write, setWrite] = useState<RecipeWriteInfo | null>(null);
    /** Há alterações que ainda não foram para o PingWin (editou, ou retomou um rascunho). */
    const dirty = useRef(false);
    /** O rascunho à espera de ir para a XPLENDOR (guarda-se com atraso; ao fechar, vai já). */
    const pendingDraft = useRef<(() => Promise<void>) | null>(null);
    const hidden = hiddenProp ?? preview?.financial?.visible === false;

    const startFresh = useCallback((c: RecipeEditorContext) => {
        setDraft(fromVersion(c, c.versions.length ? "corrigir" : "criar", c.versions.length ? defaultVersion(c, bomId) : null));
        setStale(false);
        dirty.current = false;
        setPhase("edit");
    }, [bomId]);

    const load = useCallback(async () => {
        if (!companyId) return;
        setPhase("loading");
        const res: any = await getRecipeEditor(companyId, productId);
        const c: RecipeEditorContext = res?.data;
        setCtx(c);
        setSavedAt(null);
        if (c.active_write) { setWrite(c.active_write); setPhase("progress"); return; }
        if (c.draft?.payload) { setPhase("resume"); return; }
        startFresh(c);
    }, [companyId, productId, startFresh]);
    useEffect(() => {
        load().catch(() => { toast.error("Não foi possível abrir o editor da ficha."); onClose(); });
        // Só ao abrir: recarregar a meio apagaria o que se está a editar.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const resume = () => {
        if (!ctx?.draft) return;
        setDraft(ctx.draft.payload);
        setStale(ctx.draft.base_signature !== ctx.signature);
        dirty.current = true;
        setPhase("edit");
    };
    const discardAndStart = async () => {
        if (!ctx || !companyId) return;
        try { await deleteRecipeDraft(companyId, productId); } catch { /* sem rascunho no servidor */ }
        setCtx({ ...ctx, draft: null });
        startFresh(ctx);
    };

    // Rascunho guardado na XPLENDOR (com atraso, a cada alteração).
    useEffect(() => {
        if (phase !== "edit" || !draft || !ctx || !companyId || !canEdit || !dirty.current) return;
        const save = async () => {
            pendingDraft.current = null;
            try {
                await saveRecipeDraft(companyId, productId, { payload: draft, base_signature: stale ? ctx.draft?.base_signature ?? null : ctx.signature });
                setSavedAt(new Date().toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" }));
            } catch { /* o rascunho volta a tentar na próxima alteração */ }
        };
        pendingDraft.current = save;
        const t = setTimeout(save, 800);
        return () => clearTimeout(t);
    }, [draft, ctx, companyId, productId, canEdit, stale, phase]);

    // Custo XPLENDOR do rascunho, enquanto se edita.
    const body = useMemo(() => draft && ({
        mode: draft.mode, bom_id: draft.bom_id, valid_from: draft.mode === "corrigir" ? null : draft.valid_from, stores: draft.stores,
        lines: draft.lines.filter((l) => l.component_id && l.unit_id && num(l.qnt) > 0).map((l) => ({
            key: l.key, component_id: l.component_id, description: l.description, unit_id: l.unit_id,
            qnt: num(l.qnt), waste: pct(l.waste) || 0, recovery: pct(l.recovery) || 0,
        })),
    }), [draft]);
    useEffect(() => {
        if (!body || !companyId || phase === "loading" || phase === "resume") return;
        setPreviewing(true);
        const t = setTimeout(async () => {
            try {
                const res: any = await previewRecipe(companyId, productId, body);
                setPreview(res?.data ?? null);
            } catch { /* mantém a última pré-visualização */ } finally {
                setPreviewing(false);
            }
        }, 400);
        return () => clearTimeout(t);
    }, [body, companyId, productId, phase]);

    const change = (fn: (d: RecipeDraftPayload) => RecipeDraftPayload) => {
        dirty.current = true;
        setDraft((d) => (d ? fn(d) : d));
    };
    const setLine = (u: string, patch: Partial<RecipeDraftLine>) => change((d) => ({ ...d, lines: d.lines.map((l) => (l.uid === u ? { ...l, ...patch } : l)) }));

    const switchMode = (mode: RecipeWriteMode) => {
        if (!ctx || !draft || draft.mode === mode) return;
        if (mode === "nova_versao") {
            change((d) => ({ ...d, mode, valid_from: ctx.today, lines: d.lines.map((l) => ({ ...l, key: null })) }));
            return;
        }
        // De volta a "Corrigir": as linhas voltam a apontar para as da versão (pelo componente), sem perder o que se editou.
        const v = ctx.versions.find((x) => x.id === draft.bom_id);
        const used = new Set<string>();
        change((d) => ({
            ...d, mode, lines: d.lines.map((l) => {
                const m = v?.lines.find((x) => x.component_id === l.component_id && !used.has(x.key));
                if (m) used.add(m.key);
                return { ...l, key: m?.key ?? null };
            }),
        }));
    };
    const pickVersion = (id: string) => {
        if (!ctx || !draft) return;
        dirty.current = true;
        setDraft(fromVersion(ctx, draft.mode, id));
    };

    const addComponent = (c: RecipeComponentHit) => {
        setPickerOpen(false);
        change((d) => ({
            ...d,
            lines: [...d.lines, {
                uid: uid(), key: null, component_id: c.component_id, description: c.description, code: c.code,
                unit_id: c.default_unit_id ?? c.units[0]?.id ?? "", units: c.units, qnt: "1", waste: "0", recovery: "0", advanced: false,
            }],
        }));
    };

    const requestClose = () => {
        if (sending) return;
        if ((phase === "edit" || phase === "confirm") && dirty.current) { setAskClose(true); return; }
        onClose();
    };
    const closeKeepingDraft = () => {
        pendingDraft.current?.();
        onClose();
    };

    const onFinished = useCallback((w: RecipeWriteInfo) => {
        if (w.status === "ok") {
            toast.success("Ficha gravada e confirmada no PingWin.");
            dirty.current = false;
            onSaved();
            onClose();
        } else if (w.status === "mismatch") {
            onSaved();
        }
    }, [onSaved, onClose]);

    const send = async () => {
        if (!companyId || !body || !ctx) return;
        setSending(true);
        try {
            const res: any = await writeRecipe(companyId, productId, { ...body, base_signature: ctx.signature });
            setWrite(res?.data?.write ?? null);
            setPhase("progress");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível começar a gravação.");
        } finally {
            setSending(false);
        }
    };

    const isCreate = draft?.mode === "criar";
    const title = (
        <div>
            <div>{phase === "progress" && write ? `${OPERATION[write.operation]} no PingWin` : isCreate || (ctx && !ctx.versions.length) ? "Criar ficha técnica" : "Editar ficha técnica"}</div>
            {ctx && <div className="fs-13 text-muted fw-normal" data-testid="editor-article">{ctx.product.code ? `${ctx.product.code} · ` : ""}{ctx.product.description ?? productId}</div>}
        </div>
    );

    return (
        <Modal isOpen toggle={requestClose} size="xl" fullscreen="sm" scrollable centered backdrop="static" data-testid="recipe-editor" contentClassName="xp-recipe-editor">
            <ModalHeader toggle={requestClose}>{title}</ModalHeader>

            {phase === "loading" && <ModalBody><Skeleton variant="lines" lines={6} label="A abrir o editor da ficha…" /></ModalBody>}

            {phase === "resume" && ctx?.draft && (
                <>
                    <ModalBody data-testid="editor-resume">
                        <p className="mb-2">
                            Tem um rascunho desta ficha guardado na XPLENDOR{ctx.draft.updated_at ? <> ({new Date(ctx.draft.updated_at).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })})</> : null}:{" "}
                            <strong>{MODE_LABEL[ctx.draft.payload.mode]}</strong>, {ctx.draft.payload.lines.length} {ctx.draft.payload.lines.length === 1 ? "componente" : "componentes"}. Quer retomá-lo?
                        </p>
                        {ctx.draft.base_signature !== ctx.signature && (
                            <div className="alert alert-warning py-2 fs-13 mb-0" role="alert">
                                A ficha mudou no PingWin depois deste rascunho (outra gravação ou a leitura noturna). Pode vê-lo, mas para gravar tem de recomeçar a partir da ficha atual.
                            </div>
                        )}
                    </ModalBody>
                    <ModalFooter>
                        <Button type="button" color="light" onClick={discardAndStart}>Começar da ficha atual</Button>
                        <Button type="button" color="primary" onClick={resume}>Retomar o rascunho</Button>
                    </ModalFooter>
                </>
            )}

            {phase === "progress" && write && companyId && (
                <ProgressSection companyId={companyId} write={write} onFinished={onFinished} onClose={onClose} onRestart={load} />
            )}

            {(phase === "edit" || phase === "confirm") && ctx && draft && (
                <EditorBody
                    ctx={ctx} draft={draft} phase={phase} preview={preview} previewing={previewing} hidden={hidden} stale={stale} savedAt={savedAt}
                    canEdit={canEdit} editReason={editReason} pickerOpen={pickerOpen} askClose={askClose} sending={sending} companyId={companyId} productId={productId}
                    change={change} setLine={setLine} switchMode={switchMode} pickVersion={pickVersion} setPickerOpen={setPickerOpen} addComponent={addComponent}
                    onCancel={requestClose} onKeepEditing={() => setAskClose(false)} onCloseKeepingDraft={closeKeepingDraft}
                    onAskWrite={() => setPhase("confirm")} onBack={() => setPhase("edit")} onSend={send} onRestart={discardAndStart}
                />
            )}
        </Modal>
    );
}

/** O progresso da gravação, dentro do modal do editor (os passos e, no fim, o resultado). */
function ProgressSection({ companyId, write, onFinished, onClose, onRestart }: {
    companyId: number; write: RecipeWriteInfo; onFinished: (w: RecipeWriteInfo) => void; onClose: () => void; onRestart: () => void;
}) {
    const w = useWriteProgress(companyId, write, onFinished);
    const final = isFinal(w);
    return (
        <>
            <ModalBody data-testid="editor-progress"><WriteSteps w={w} /></ModalBody>
            <ModalFooter>
                {w.status === "conflict" && <Button type="button" color="outline-primary" onClick={onRestart}>Voltar a abrir a ficha atual</Button>}
                <Button type="button" color="light" onClick={onClose}>{final ? "Fechar" : "Fechar (continua em segundo plano)"}</Button>
            </ModalFooter>
        </>
    );
}

type EditorBodyProps = {
    ctx: RecipeEditorContext; draft: RecipeDraftPayload; phase: "edit" | "confirm"; preview: RecipePreview | null; previewing: boolean;
    hidden: boolean; stale: boolean; savedAt: string | null; canEdit: boolean; editReason: string | null | undefined;
    pickerOpen: boolean; askClose: boolean; sending: boolean; companyId: number | null; productId: string;
    change: (fn: (d: RecipeDraftPayload) => RecipeDraftPayload) => void;
    setLine: (u: string, patch: Partial<RecipeDraftLine>) => void;
    switchMode: (m: RecipeWriteMode) => void; pickVersion: (id: string) => void;
    setPickerOpen: (v: boolean) => void; addComponent: (c: RecipeComponentHit) => void;
    onCancel: () => void; onKeepEditing: () => void; onCloseKeepingDraft: () => void;
    onAskWrite: () => void; onBack: () => void; onSend: () => void; onRestart: () => void;
};

/** Cabeçalho fixo (versão, modo, lojas), corpo (linhas ou confirmação) e rodapé fixo (custo e botões). */
function EditorBody(p: EditorBodyProps) {
    const { ctx, draft, preview, hidden } = p;
    const lines = draft.lines;
    const valid = lines.filter((x) => x.component_id && x.unit_id && num(x.qnt) > 0);
    const pv = (l: RecipeDraftLine) => preview?.lines?.[valid.indexOf(l)];
    const invalid = lines.find((l) => !l.unit_id || !(num(l.qnt) > 0) || Number.isNaN(pct(l.waste)) || pct(l.waste) > 100 || Number.isNaN(pct(l.recovery)) || pct(l.recovery) > 100);
    const guard = ctx.write_guard;
    const reason = !p.canEdit ? (p.editReason ?? "Precisa da permissão de editar os artigos da restauração.")
        : p.stale ? "O rascunho é de uma ficha que entretanto mudou: recomece a partir da ficha atual."
            : !lines.length ? "Adicione pelo menos um componente."
                : invalid ? `Reveja a linha ${invalid.description}: quantidade maior do que zero, unidade e percentagens entre 0 e 100.`
                    : !Object.values(draft.stores).some(Boolean) ? "Escolha pelo menos uma loja."
                        : draft.mode !== "corrigir" && draft.valid_from < ctx.today ? "A data tem de ser hoje ou uma data futura."
                            : null;
    const version = ctx.versions.find((v) => v.id === draft.bom_id);
    const effects = preview?.store_effects ?? [];
    const confidence = !lines.length || !preview ? null
        : !preview.cost_complete ? { label: "parcial", cls: "text-warning" }
            : preview.low_confidence ? { label: "baixa", cls: "text-warning" }
                : { label: "boa", cls: "text-success" };
    const versionLabel = (v: { valid_from: string | null; id: string }) => `${fmtDate(v.valid_from)}${v.id === ctx.in_force ? " (em vigor)" : ""}`;

    if (p.phase === "confirm") {
        return (
            <>
                <ModalBody data-testid="editor-confirm">
                    <p className="mb-2">
                        {draft.mode === "criar" && <>Vai criar a ficha técnica com <strong>{lines.length}</strong> {lines.length === 1 ? "componente" : "componentes"}, a valer a partir de <strong>{fmtDate(draft.valid_from)}</strong>.</>}
                        {draft.mode === "nova_versao" && <>Vai criar uma <strong>versão nova</strong> com <strong>{lines.length}</strong> {lines.length === 1 ? "componente" : "componentes"}, a valer a partir de <strong>{fmtDate(draft.valid_from)}</strong>. As versões anteriores ficam no histórico.</>}
                        {draft.mode === "corrigir" && <>Vai <strong>corrigir a versão de {fmtDate(version?.valid_from)}</strong>: a receita usada desde essa data muda.</>}
                    </p>
                    <ul className="fs-13 mb-2">
                        {effects.map((e) => (
                            <li key={e.store_id} className={e.without_bom ? "text-danger" : undefined}>
                                {e.name}: {e.without_bom ? "fica sem ficha em vigor" : e.changes ? `passa a usar esta versão${draft.mode === "corrigir" ? "" : ` a partir de ${fmtDate(e.from)}`}` : `continua com a versão de ${fmtDate(e.after)}`}
                            </li>
                        ))}
                    </ul>
                    <p className="text-muted fs-12 mb-0"><i className="ri-information-line me-1" aria-hidden />Uma ficha de cada vez: abre, compara com a XPLENDOR, grava e relê. Se a ficha tiver mudado no PingWin, pára sem gravar.</p>
                </ModalBody>
                <ModalFooter>
                    <Button type="button" color="light" onClick={p.onBack} disabled={p.sending}>Voltar</Button>
                    <Button type="button" color="primary" onClick={p.onSend} disabled={p.sending} data-testid="editor-send">
                        {p.sending ? <><Spinner size="sm" className="me-1" />A pôr na fila…</> : <><i className="ri-upload-cloud-2-line me-1" aria-hidden />Confirmar e gravar no PingWin</>}
                    </Button>
                </ModalFooter>
            </>
        );
    }

    return (
        <>
            {/* Cabeçalho fixo: versão, modo e lojas (não faz scroll com as linhas). */}
            <div className="px-3 py-3 border-bottom flex-shrink-0" data-testid="editor-head">
                <div className="d-flex flex-wrap align-items-center gap-2">
                    {draft.mode === "criar" ? (
                        <>
                            <Label className="form-label mb-0" for="recipe-valid-from">Vale a partir de</Label>
                            <Input id="recipe-valid-from" type="date" bsSize="sm" style={{ width: 160 }} min={ctx.today} value={draft.valid_from}
                                onChange={(e) => p.change((d) => ({ ...d, valid_from: e.target.value }))} />
                        </>
                    ) : (
                        <>
                            <div className="xp-seg flex-wrap" role="tablist" aria-label="Como gravar" data-testid="editor-mode">
                                <button type="button" role="tab" aria-selected={draft.mode === "corrigir"} className={draft.mode === "corrigir" ? "on" : ""} onClick={() => p.switchMode("corrigir")}>
                                    Corrigir esta versão ({fmtDate(version?.valid_from)})
                                </button>
                                <button type="button" role="tab" aria-selected={draft.mode === "nova_versao"} className={draft.mode === "nova_versao" ? "on" : ""} onClick={() => p.switchMode("nova_versao")}>
                                    Nova versão a partir de
                                </button>
                            </div>
                            {draft.mode === "nova_versao" && (
                                <Input type="date" bsSize="sm" style={{ width: 160 }} min={ctx.today} value={draft.valid_from} aria-label="Data da versão nova"
                                    onChange={(e) => p.change((d) => ({ ...d, valid_from: e.target.value }))} data-testid="editor-date" />
                            )}
                            {ctx.versions.length > 1 && (
                                <div className="d-flex align-items-center gap-2 ms-md-auto">
                                    <span className="text-muted fs-12">{draft.mode === "corrigir" ? "Versão" : "Linhas da versão"}</span>
                                    <XSelect ariaLabel={draft.mode === "corrigir" ? "Versão a corrigir" : "Copiar as linhas da versão"} small searchable={false} width={190}
                                        options={ctx.versions.map((v) => ({ value: v.id, label: versionLabel(v) }))}
                                        value={draft.bom_id ?? ""} onChange={p.pickVersion} />
                                </div>
                            )}
                        </>
                    )}
                </div>
                <div className="text-muted fs-12 mt-1" data-testid="editor-mode-hint">
                    {draft.mode === "corrigir" && <>Muda a receita desta versão desde {fmtDate(version?.valid_from)}: o custo e o stock já calculados com ela passam a usar a receita corrigida.</>}
                    {draft.mode === "nova_versao" && <>Use quando a receita mudou mesmo (porção, ingrediente ou fornecedor). Hoje ou uma data futura; a versão de {fmtDate(version?.valid_from)} fica no histórico.</>}
                    {draft.mode === "criar" && <>Hoje ou uma data futura.</>}
                </div>
                <div className="d-flex flex-wrap align-items-start gap-3 mt-2" role="group" aria-label="Lojas desta versão" data-testid="editor-stores">
                    <span className="text-muted fs-12 pt-1">Lojas:</span>
                    {ctx.stores.map((s) => {
                        const e = effects.find((x) => x.store_id === s.store_id);
                        return (
                            <div key={s.store_id}>
                                <div className="form-check mb-0">
                                    <Input type="checkbox" className="form-check-input" id={`store-${s.store_id}`} checked={!!draft.stores[s.store_id]}
                                        onChange={(ev) => p.change((d) => ({ ...d, stores: { ...d.stores, [s.store_id]: ev.target.checked } }))} />
                                    <Label className="form-check-label fs-13" for={`store-${s.store_id}`}>{s.name}</Label>
                                </div>
                                {e?.without_bom && <div className="fs-11 text-danger" data-testid="store-without"><i className="ri-alert-line me-1" aria-hidden />fica sem ficha em vigor <WarningTip type="store_without" /></div>}
                                {e && !e.without_bom && e.changes && draft.mode !== "corrigir" && <div className="fs-11 text-muted">a partir de {fmtDate(e.from)}</div>}
                                {e && !e.without_bom && !e.changes && e.after && <div className="fs-11 text-muted">continua com a de {fmtDate(e.after)}</div>}
                            </div>
                        );
                    })}
                </div>
            </div>

            <ModalBody data-testid="editor-lines">
                {p.stale && (
                    <div className="alert alert-warning py-2 fs-13 d-flex flex-wrap align-items-center gap-2" role="alert">
                        <span>O rascunho foi feito sobre uma ficha que entretanto mudou no PingWin.</span>
                        <Button type="button" size="sm" color="outline-primary" onClick={p.onRestart}>Recomeçar a partir da ficha atual</Button>
                    </div>
                )}
                <div className="table-responsive">
                    <table className="table align-middle mb-2" data-testid="recipe-editor-lines">
                        <thead className="table-light">
                            <tr>
                                <th>Componente</th>
                                <th className="text-end" style={{ width: 120 }}>Quantidade</th>
                                <th style={{ minWidth: 220 }}>Unidade</th>
                                <th className="text-end" style={{ width: 120 }}>Desperdício %</th>
                                <th className="text-end" style={{ width: 130 }}>Custo da linha</th>
                                <th style={{ width: 110 }}><span className="visually-hidden">Ações</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.length === 0 && (
                                <tr><td colSpan={6} className="text-muted text-center py-4">Ainda sem componentes. Use "Adicionar componente".</td></tr>
                            )}
                            {lines.map((l, i) => {
                                const r = pv(l);
                                const units = r?.units?.length ? r.units : l.units;
                                const base = units.find((u) => u.base);
                                const unit = units.find((u) => u.id === l.unit_id);
                                return (
                                    <tr key={l.uid} data-testid="editor-line">
                                        <td style={{ minWidth: 220 }}>
                                            <div className="fw-medium">{l.description}</div>
                                            <div className="d-flex flex-wrap gap-1 mt-1">
                                                {r?.is_bom && <span className="badge bg-primary-subtle text-primary">semi-produto</span>}
                                                {r?.inactive && <span className="badge bg-secondary-subtle text-secondary">inativo</span>}
                                                {!l.key && draft.mode === "corrigir" && <span className="badge bg-info-subtle text-info">nova</span>}
                                            </div>
                                            {r?.is_bom && r.yield && (
                                                <div className="fs-12 text-muted">
                                                    {r.yield.confirmed ? <>Rende {fmtQty(r.yield.quantity, 3)} {r.yield.unit_label}: </> : <>Rendimento por confirmar: </>}
                                                    <Money value={r.unit_cost_avg_eur} hidden={hidden} decimals={4} seed={i} /> por {r.yield.unit_label.toLowerCase()}
                                                </div>
                                            )}
                                            {r?.similar && <div className="fs-12 text-muted">Compra-se um parecido: {r.similar.code} {r.similar.description}</div>}
                                            {!!r?.warnings?.length && (
                                                <ul className="list-unstyled fs-12 mb-0 mt-1">
                                                    {r.warnings.filter((w) => w.type !== "similar").map((w, k) => (
                                                        <li key={k} className={w.level === "info" ? "text-muted" : "text-warning"}>
                                                            <i className={`${w.level === "info" ? "ri-information-line" : "ri-alert-line"} me-1`} aria-hidden />{w.text} <WarningTip type={w.type} />
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                            {l.advanced && (
                                                <div className="d-flex align-items-center gap-2 mt-2">
                                                    <Label className="form-label mb-0 fs-12" for={`rec-${l.uid}`}>Recuperação %</Label>
                                                    <Input id={`rec-${l.uid}`} bsSize="sm" style={{ width: 90 }} inputMode="decimal" value={l.recovery}
                                                        onChange={(e) => p.setLine(l.uid, { recovery: e.target.value })} />
                                                    <span className="text-muted fs-12">Mostra-se; não entra no custo.</span>
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-end">
                                            <Input bsSize="sm" className="text-end" inputMode="decimal" value={l.qnt} aria-label={`Quantidade de ${l.description}`}
                                                invalid={!(num(l.qnt) > 0)} onChange={(e) => p.setLine(l.uid, { qnt: e.target.value })} />
                                            {unit && !unit.base && num(l.qnt) > 0 && <div className="text-muted fs-11 mt-1">= {fmtQty(num(l.qnt) * unit.factor, 4)} {base?.label}</div>}
                                        </td>
                                        <td>
                                            <XSelect ariaLabel={`Unidade de ${l.description}`} small searchable={units.length > 8}
                                                options={units.map((u) => ({ value: u.id, label: unitLabel(u, base) }))}
                                                value={l.unit_id} onChange={(v) => p.setLine(l.uid, { unit_id: v })} />
                                        </td>
                                        <td className="text-end">
                                            <Input bsSize="sm" className="text-end" inputMode="decimal" value={l.waste} aria-label={`Desperdício de ${l.description}`}
                                                invalid={Number.isNaN(pct(l.waste)) || pct(l.waste) > 100} onChange={(e) => p.setLine(l.uid, { waste: e.target.value })} />
                                        </td>
                                        <td className="text-end">
                                            {r ? <Money value={r.line_cost_eur} hidden={hidden && r.line_cost_eur === null} decimals={4} seed={i + 3} /> : <span className="text-muted">—</span>}
                                            {r?.cost_status && r.cost_status !== "ok" && (
                                                <div className={`fs-11 ${r.cost_status === "zero" ? "text-muted" : "text-warning"}`}>
                                                    {({ sem_custo: "sem custo", zero: "custo 0", antigo: "antigo", parcial: "parcial", ciclo: "ciclo", fundo: "mais de 5 níveis" } as Record<string, string>)[r.cost_status] ?? r.cost_status}
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-end text-nowrap">
                                            <Button type="button" size="sm" color="link" className="p-0 me-2 fs-12" onClick={() => p.setLine(l.uid, { advanced: !l.advanced })} aria-expanded={l.advanced}>
                                                Avançado
                                            </Button>
                                            <Button type="button" size="sm" color="outline-danger" aria-label={`Apagar a linha ${l.description}`} title="Apagar a linha"
                                                onClick={() => p.change((d) => ({ ...d, lines: d.lines.filter((x) => x.uid !== l.uid) }))}>
                                                <i className="ri-delete-bin-line" aria-hidden />
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                {p.pickerOpen && p.companyId ? (
                    <ComponentPicker companyId={p.companyId} exclude={[p.productId, ...lines.map((l) => l.component_id)]}
                        onPick={p.addComponent} onClose={() => p.setPickerOpen(false)} />
                ) : (
                    <Button type="button" size="sm" color="outline-primary" onClick={() => p.setPickerOpen(true)} data-testid="add-component">
                        <i className="ri-add-line me-1" aria-hidden />Adicionar componente
                    </Button>
                )}
            </ModalBody>

            {/* Rodapé fixo: o custo vivo e as ações (ou a confirmação de fechar sem gravar). */}
            <ModalFooter className="justify-content-between gap-2" data-testid="editor-footer">
                {p.askClose ? (
                    <div className="d-flex flex-wrap align-items-center gap-2 w-100" role="alertdialog" aria-label="Fechar sem gravar" data-testid="editor-ask-close">
                        <span className="fs-13 me-auto"><i className="ri-error-warning-line text-warning me-1" aria-hidden />Fechar sem gravar no PingWin? O rascunho fica guardado na XPLENDOR e, ao voltar, pode retomá-lo.</span>
                        <Button type="button" color="light" onClick={p.onKeepEditing}>Continuar a editar</Button>
                        <Button type="button" color="primary" onClick={p.onCloseKeepingDraft}>Fechar e guardar o rascunho</Button>
                    </div>
                ) : (
                    <>
                        <div className="d-flex flex-wrap align-items-center gap-2 gap-sm-3 fs-13" data-testid="editor-totals">
                            <span>
                                Custo total: <strong><Money value={preview?.cost_eur ?? null} hidden={hidden && lines.length > 0} decimals={2} seed={50} /></strong>
                                {p.previewing && <Spinner size="sm" className="ms-1" aria-label="A recalcular" />}
                            </span>
                            <span>Food cost: <strong>{fmtPct(preview?.food_cost_pct ?? null)}</strong></span>
                            <span>
                                Confiança: <strong className={confidence?.cls}>{confidence?.label ?? "—"}</strong>
                                <InfoTip className="ms-1" label="Sobre a confiança do custo" placement="top"
                                    text="Boa: todas as linhas têm custo das compras. Parcial: há linhas sem custo. Baixa: há semi-produtos com o rendimento por confirmar." />
                            </span>
                            <span className="text-muted fs-12 d-none d-sm-inline">{p.savedAt ? `Rascunho guardado às ${p.savedAt}` : "Rascunho na XPLENDOR até gravar"}</span>
                        </div>
                        <div className="d-flex align-items-center gap-2 ms-auto">
                            <Button type="button" color="light" onClick={p.onCancel}>Cancelar</Button>
                            {guard ? (
                                <span className="d-inline-flex align-items-center gap-1" data-testid="write-guard">
                                    <Button type="button" color="primary" disabled><i className="ri-upload-cloud-2-line me-1" aria-hidden />Gravar no PingWin</Button>
                                    <InfoTip text={guard.reason} label="Porque é que não se pode gravar" placement="top" />
                                </span>
                            ) : (
                                <ReasonButton type="button" color="primary" reason={reason} onClick={p.onAskWrite} data-testid="write-button">
                                    <i className="ri-upload-cloud-2-line me-1" aria-hidden />Gravar no PingWin
                                </ReasonButton>
                            )}
                        </div>
                    </>
                )}
            </ModalFooter>
        </>
    );
}
