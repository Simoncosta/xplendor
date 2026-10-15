import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button, Col, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import { useCan } from "contexts/ModulesContext";
import { deleteRecipeDraft, getRecipeEditor, previewRecipe, saveRecipeDraft, writeRecipe } from "helpers/laravel_helper";
import {
    RecipeComponentHit, RecipeDraftLine, RecipeDraftPayload, RecipeEditorContext, RecipePreview, RecipeUnitOption, RecipeWriteInfo, RecipeWriteMode,
} from "common/models/recipe.model";
import { Money, fmtDate, fmtPct, fmtQty } from "./recipeFormat";
import { WarningTip } from "./warningInfo";
import ComponentPicker from "./ComponentPicker";
import WriteProgressModal from "./WriteProgressModal";
import { parseNum } from "./ConfirmFactorModal";

/**
 * Fatia 4 — o editor da ficha técnica. As alterações ficam num rascunho na XPLENDOR até "Gravar
 * na PingWin"; o custo XPLENDOR atualiza-se enquanto se edita. Editar uma ficha que já existe cria,
 * por omissão, uma versão NOVA (hoje ou numa data futura), para guardar o histórico; "Corrigir esta
 * versão" muda a receita usada até hoje. A gravação corre em fila, uma ficha de cada vez, e é
 * confirmada por releitura.
 * Fica dentro do formulário do artigo (separador "Ficha técnica"): o Enter nos campos não submete
 * esse formulário e todos os botões são type="button".
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

function fromVersion(ctx: RecipeEditorContext, mode: RecipeWriteMode, bomId: string | null): RecipeDraftPayload {
    const v = ctx.versions.find((x) => x.id === bomId) ?? null;
    const stores: Record<string, boolean> = {};
    for (const s of ctx.stores) stores[s.store_id] = v ? !!v.stores.find((x) => x.store_id === s.store_id)?.active : true;
    return {
        mode, bom_id: v?.id ?? null, valid_from: ctx.today, stores,
        lines: (v?.lines ?? []).map((l) => ({
            uid: uid(), key: mode === "corrigir" ? l.key : null, component_id: l.component_id, description: l.description ?? l.component_id,
            code: null, unit_id: l.unit_id, units: l.units, qnt: String(l.qnt).replace(".", ","), waste: String(l.waste).replace(".", ","),
            recovery: String(l.recovery).replace(".", ","), advanced: l.recovery > 0,
        })),
    };
}

const unitLabel = (u: RecipeUnitOption, base?: RecipeUnitOption) =>
    u.base ? u.label : `${u.label} (= ${fmtQty(u.factor, 4)} ${base?.label ?? "base"})${u.suspicious ? ", fator 1?" : ""}`;

export default function RecipeEditor({ companyId, productId, hidden: hiddenProp, onSaved, onClose }: {
    /** Sem Finanças: os euros desfocados. Sem a prop, segue a resposta da pré-visualização. */
    companyId: number | null; productId: string; hidden?: boolean; onSaved: () => void; onClose: () => void;
}) {
    const [canEdit, editReason] = useCan("restauracao.editar");
    const [ctx, setCtx] = useState<RecipeEditorContext | null>(null);
    const [draft, setDraft] = useState<RecipeDraftPayload | null>(null);
    const [stale, setStale] = useState(false);
    const [preview, setPreview] = useState<RecipePreview | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [savedAt, setSavedAt] = useState<string | null>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [write, setWrite] = useState<RecipeWriteInfo | null>(null);
    const touched = useRef(false);
    const hidden = hiddenProp ?? preview?.financial?.visible === false;

    const load = useCallback(async () => {
        if (!companyId) return;
        const res: any = await getRecipeEditor(companyId, productId);
        const c: RecipeEditorContext = res?.data;
        setCtx(c);
        if (c.active_write) setWrite(c.active_write);
        if (c.draft?.payload) {
            setDraft(c.draft.payload);
            setStale(c.draft.base_signature !== c.signature);
        } else {
            setDraft(fromVersion(c, c.versions.length ? "nova_versao" : "criar", c.in_force));
            setStale(false);
        }
        touched.current = false;
    }, [companyId, productId]);
    useEffect(() => { load().catch(() => toast.error("Não foi possível abrir o editor da ficha.")); }, [load]);

    // Rascunho guardado na XPLENDOR (com atraso, a cada alteração).
    useEffect(() => {
        if (!draft || !ctx || !companyId || !canEdit || !touched.current) return;
        const t = setTimeout(async () => {
            try {
                await saveRecipeDraft(companyId, productId, { payload: draft, base_signature: stale ? ctx.draft?.base_signature ?? null : ctx.signature });
                setSavedAt(new Date().toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" }));
            } catch { /* o rascunho volta a tentar na próxima alteração */ }
        }, 800);
        return () => clearTimeout(t);
    }, [draft, ctx, companyId, productId, canEdit, stale]);

    // Custo XPLENDOR do rascunho, enquanto se edita.
    const body = useMemo(() => draft && ({
        mode: draft.mode, bom_id: draft.bom_id, valid_from: draft.mode === "corrigir" ? null : draft.valid_from, stores: draft.stores,
        lines: draft.lines.filter((l) => l.component_id && l.unit_id && num(l.qnt) > 0).map((l) => ({
            key: l.key, component_id: l.component_id, description: l.description, unit_id: l.unit_id,
            qnt: num(l.qnt), waste: pct(l.waste) || 0, recovery: pct(l.recovery) || 0,
        })),
    }), [draft]);
    useEffect(() => {
        if (!body || !companyId) return;
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
    }, [body, companyId, productId]);

    const change = (fn: (d: RecipeDraftPayload) => RecipeDraftPayload) => {
        touched.current = true;
        setDraft((d) => (d ? fn(d) : d));
    };
    const setLine = (u: string, patch: Partial<RecipeDraftLine>) => change((d) => ({ ...d, lines: d.lines.map((l) => (l.uid === u ? { ...l, ...patch } : l)) }));

    const switchMode = (mode: RecipeWriteMode) => {
        if (!ctx || !draft) return;
        touched.current = true;
        if (mode === "corrigir") setDraft(fromVersion(ctx, "corrigir", draft.bom_id ?? ctx.in_force ?? ctx.versions[ctx.versions.length - 1]?.id ?? null));
        else setDraft({ ...draft, mode, valid_from: ctx.today, lines: draft.lines.map((l) => ({ ...l, key: null })) });
    };

    const restart = async () => {
        if (!ctx || !companyId) return;
        try { await deleteRecipeDraft(companyId, productId); } catch { /* sem rascunho no servidor */ }
        await load();
        setSavedAt(null);
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

    const onFinished = useCallback((w: RecipeWriteInfo) => {
        if (w.status === "ok") { toast.success("Ficha gravada e confirmada na PingWin."); onSaved(); load(); }
        else if (w.status === "conflict") load();
        else if (w.status === "mismatch") onSaved();
    }, [onSaved, load]);

    if (!ctx || !draft) return <PageCard title="Editar ficha técnica" flush={false}><Skeleton variant="lines" lines={4} label="" /></PageCard>;

    const lines = draft.lines;
    const pv = (i: number) => preview?.lines?.[i];
    const invalid = lines.find((l) => !l.unit_id || !(num(l.qnt) > 0) || Number.isNaN(pct(l.waste)) || pct(l.waste) > 100 || Number.isNaN(pct(l.recovery)) || pct(l.recovery) > 100);
    const reason = !canEdit ? (editReason ?? "Precisa da permissão de editar os artigos da restauração.")
        : !ctx.write_allowed ? "Gravar fichas na PingWin só está autorizado no artigo de teste."
            : stale ? "O rascunho é de uma ficha que entretanto mudou: recomece a partir da ficha atual."
                : write && !["ok", "mismatch", "conflict", "failed"].includes(write.status) ? "Há uma gravação desta ficha em curso."
                    : !lines.length ? "Junte pelo menos um componente."
                        : invalid ? `Reveja a linha ${invalid.description}: quantidade maior do que zero, unidade e percentagens entre 0 e 100.`
                            : !Object.values(draft.stores).some(Boolean) ? "Escolha pelo menos uma loja."
                                : draft.mode !== "corrigir" && draft.valid_from < ctx.today ? "A data tem de ser hoje ou uma data futura."
                                    : null;
    const corrigirVersion = ctx.versions.find((v) => v.id === draft.bom_id);
    const effects = preview?.store_effects ?? [];

    const send = async () => {
        if (!companyId || !body) return;
        setSending(true);
        try {
            const res: any = await writeRecipe(companyId, productId, { ...body, base_signature: ctx.signature });
            setConfirmOpen(false);
            setWrite(res?.data?.write ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível começar a gravação.");
        } finally {
            setSending(false);
        }
    };

    return (
        <div
            // Dentro do formulário do artigo: o Enter num campo não grava o artigo.
            onKeyDown={(e) => { if (e.key === "Enter" && (e.target as HTMLElement).tagName === "INPUT") e.preventDefault(); }}
            data-testid="recipe-editor"
        >
            <PageCard
                title={draft.mode === "criar" ? "Criar ficha técnica" : "Editar ficha técnica"}
                status={<>{savedAt ? `Rascunho guardado na XPLENDOR às ${savedAt}` : "As alterações ficam num rascunho na XPLENDOR até gravar na PingWin"}</>}
                loading={previewing && !!preview}
                flush={false}
                actions={draft.mode !== "criar" ? (
                    <div className="xp-seg" role="tablist" aria-label="Como gravar">
                        <button type="button" role="tab" aria-selected={draft.mode === "nova_versao"} className={draft.mode === "nova_versao" ? "on" : ""} onClick={() => switchMode("nova_versao")}>Nova versão</button>
                        <button type="button" role="tab" aria-selected={draft.mode === "corrigir"} className={draft.mode === "corrigir" ? "on" : ""} onClick={() => switchMode("corrigir")}>Corrigir esta versão</button>
                    </div>
                ) : undefined}
            >
                {stale && (
                    <div className="alert alert-warning py-2 fs-13 d-flex flex-wrap align-items-center gap-2" role="alert">
                        <span>O rascunho foi feito sobre uma ficha que entretanto mudou na PingWin.</span>
                        <Button type="button" size="sm" color="outline-primary" onClick={restart}>Recomeçar a partir da ficha atual</Button>
                    </div>
                )}

                <Row className="g-3 mb-3">
                    {draft.mode === "corrigir" ? (
                        <Col md={6}>
                            <Label className="form-label">Versão a corrigir</Label>
                            <XSelect ariaLabel="Versão a corrigir" searchable={false}
                                options={ctx.versions.map((v) => ({ value: v.id, label: `${fmtDate(v.valid_from)}${v.id === ctx.in_force ? " (em vigor)" : ""}` }))}
                                value={draft.bom_id ?? ""} onChange={(id) => { touched.current = true; setDraft(fromVersion(ctx, "corrigir", id)); }} />
                            <div className="alert alert-warning py-2 fs-13 mt-2 mb-0" role="note">
                                <i className="ri-error-warning-line me-1" aria-hidden />Corrigir muda a receita desta versão desde {fmtDate(corrigirVersion?.valid_from)}: o que já se calculou com ela (custo, consumo de stock) passa a usar a receita nova. Para guardar o histórico, crie uma versão nova.
                            </div>
                        </Col>
                    ) : (
                        <Col md={4}>
                            <Label className="form-label" for="recipe-valid-from">Vale a partir de</Label>
                            <Input id="recipe-valid-from" type="date" min={ctx.today} value={draft.valid_from}
                                onChange={(e) => change((d) => ({ ...d, valid_from: e.target.value }))} />
                            <div className="text-muted fs-12 mt-1">Hoje ou uma data futura. As vendas até lá continuam com a versão atual.</div>
                        </Col>
                    )}
                    <Col md={draft.mode === "corrigir" ? 6 : 8}>
                        <Label className="form-label">Lojas desta versão</Label>
                        <div className="d-flex flex-column gap-1">
                            {ctx.stores.map((s) => {
                                const e = effects.find((x) => x.store_id === s.store_id);
                                return (
                                    <div key={s.store_id} className="d-flex flex-wrap align-items-center gap-2">
                                        <div className="form-check mb-0">
                                            <Input type="checkbox" className="form-check-input" id={`store-${s.store_id}`} checked={!!draft.stores[s.store_id]}
                                                onChange={(ev) => change((d) => ({ ...d, stores: { ...d.stores, [s.store_id]: ev.target.checked } }))} />
                                            <Label className="form-check-label" for={`store-${s.store_id}`}>{s.name}</Label>
                                        </div>
                                        {e?.without_bom && <span className="badge bg-danger-subtle text-danger" data-testid="store-without">fica sem ficha em vigor</span>}
                                        {e && !e.without_bom && e.changes && <span className="text-muted fs-12">passa a usar esta versão {draft.mode === "corrigir" ? "já" : `a partir de ${fmtDate(e.from)}`}</span>}
                                        {e && !e.without_bom && !e.changes && e.after && <span className="text-muted fs-12">continua com a versão de {fmtDate(e.after)}</span>}
                                    </div>
                                );
                            })}
                        </div>
                    </Col>
                </Row>

                <div className="table-responsive">
                    <table className="table align-middle mb-2" data-testid="recipe-editor-lines">
                        <thead className="table-light">
                            <tr>
                                <th>Componente</th>
                                <th className="text-end" style={{ width: 120 }}>Quantidade</th>
                                <th style={{ minWidth: 220 }}>Unidade</th>
                                <th className="text-end" style={{ width: 120 }}>Desperdício %</th>
                                <th className="text-end" style={{ width: 130 }}>Custo da linha</th>
                                <th style={{ width: 90 }}><span className="visually-hidden">Ações</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.length === 0 && (
                                <tr><td colSpan={6} className="text-muted text-center py-4">Ainda sem componentes. Use "Juntar componente".</td></tr>
                            )}
                            {lines.map((l, i) => {
                                const p = pv(lines.filter((x) => x.component_id && x.unit_id && num(x.qnt) > 0).indexOf(l));
                                const units = p?.units?.length ? p.units : l.units;
                                const base = units.find((u) => u.base);
                                const unit = units.find((u) => u.id === l.unit_id);
                                return (
                                    <tr key={l.uid} data-testid="editor-line">
                                        <td style={{ minWidth: 220 }}>
                                            <div className="fw-medium">{l.description}</div>
                                            <div className="d-flex flex-wrap gap-1 mt-1">
                                                {p?.is_bom && <span className="badge bg-primary-subtle text-primary">semi-produto</span>}
                                                {p?.inactive && <span className="badge bg-secondary-subtle text-secondary">inativo</span>}
                                                {l.key && <span className="badge bg-light text-body" title="Linha que já existe na versão">existente</span>}
                                            </div>
                                            {p?.is_bom && p.yield && (
                                                <div className="fs-12 text-muted">
                                                    {p.yield.confirmed ? <>Rende {fmtQty(p.yield.quantity, 3)} {p.yield.unit_label}: </> : <>Rendimento por confirmar: </>}
                                                    <Money value={p.unit_cost_avg_eur} hidden={hidden} decimals={4} seed={i} /> por {p.yield.unit_label.toLowerCase()}
                                                </div>
                                            )}
                                            {p?.similar && <div className="fs-12 text-muted">Compra-se um parecido: {p.similar.code} {p.similar.description}</div>}
                                            {!!p?.warnings?.length && (
                                                <ul className="list-unstyled fs-12 mb-0 mt-1">
                                                    {p.warnings.filter((w) => w.type !== "similar").map((w, k) => (
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
                                                        onChange={(e) => setLine(l.uid, { recovery: e.target.value })} />
                                                    <span className="text-muted fs-12">Mostra-se; não entra no custo.</span>
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-end">
                                            <Input bsSize="sm" className="text-end" inputMode="decimal" value={l.qnt} aria-label={`Quantidade de ${l.description}`}
                                                invalid={!(num(l.qnt) > 0)} onChange={(e) => setLine(l.uid, { qnt: e.target.value })} />
                                            {unit && !unit.base && num(l.qnt) > 0 && <div className="text-muted fs-11 mt-1">= {fmtQty(num(l.qnt) * unit.factor, 4)} {base?.label}</div>}
                                        </td>
                                        <td>
                                            <XSelect ariaLabel={`Unidade de ${l.description}`} small searchable={units.length > 8}
                                                options={units.map((u) => ({ value: u.id, label: unitLabel(u, base) }))}
                                                value={l.unit_id} onChange={(v) => setLine(l.uid, { unit_id: v })} />
                                        </td>
                                        <td className="text-end">
                                            <Input bsSize="sm" className="text-end" inputMode="decimal" value={l.waste} aria-label={`Desperdício de ${l.description}`}
                                                invalid={Number.isNaN(pct(l.waste)) || pct(l.waste) > 100} onChange={(e) => setLine(l.uid, { waste: e.target.value })} />
                                        </td>
                                        <td className="text-end">
                                            {p ? <Money value={p.line_cost_eur} hidden={hidden && p.line_cost_eur === null} decimals={4} seed={i + 3} /> : <span className="text-muted">—</span>}
                                            {p?.cost_status && p.cost_status !== "ok" && (
                                                <div className={`fs-11 ${p.cost_status === "zero" ? "text-muted" : "text-warning"}`}>
                                                    {({ sem_custo: "sem custo", zero: "custo 0", antigo: "antigo", parcial: "parcial", ciclo: "ciclo", fundo: "mais de 5 níveis" } as Record<string, string>)[p.cost_status] ?? p.cost_status}
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-end text-nowrap">
                                            <Button type="button" size="sm" color="link" className="p-0 me-2 fs-12" onClick={() => setLine(l.uid, { advanced: !l.advanced })} aria-expanded={l.advanced}>
                                                Avançado
                                            </Button>
                                            <Button type="button" size="sm" color="outline-danger" aria-label={`Apagar a linha ${l.description}`}
                                                onClick={() => change((d) => ({ ...d, lines: d.lines.filter((x) => x.uid !== l.uid) }))}>
                                                <i className="ri-delete-bin-line" aria-hidden />
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr className="table-light">
                                <td colSpan={4}>
                                    <Button type="button" size="sm" color="outline-primary" onClick={() => setPickerOpen(true)}>
                                        <i className="ri-add-line me-1" aria-hidden />Juntar componente
                                    </Button>
                                </td>
                                <td className="text-end fw-semibold">
                                    <Money value={preview?.cost_eur ?? null} hidden={hidden && lines.length > 0} decimals={2} seed={50} />
                                    {preview && !preview.cost_complete && lines.length > 0 && <div className="fs-11 text-warning">parcial</div>}
                                    {preview?.low_confidence && <div className="fs-11 text-warning">baixa confiança</div>}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div className="d-flex flex-wrap gap-4 fs-13 mb-3">
                    <span>Food cost: <strong>{fmtPct(preview?.food_cost_pct ?? null)}</strong></span>
                    <span>Margem: <strong><Money value={preview?.margin_eur ?? null} hidden={hidden && (preview?.price_eur === null)} seed={51} /></strong></span>
                    <span className="text-muted">Preço sem IVA (90 dias): <Money value={preview?.price_eur ?? null} hidden={hidden} seed={52} /></span>
                </div>

                <div className="d-flex flex-wrap justify-content-end gap-2 border-top pt-3">
                    <Button type="button" color="light" onClick={onClose}>Fechar o editor</Button>
                    <Button type="button" color="outline-danger" onClick={restart} disabled={!canEdit}>Descartar o rascunho</Button>
                    <ReasonButton type="button" color="primary" reason={reason} onClick={() => setConfirmOpen(true)}>
                        <i className="ri-upload-cloud-2-line me-1" aria-hidden />Gravar na PingWin
                    </ReasonButton>
                </div>
            </PageCard>

            {companyId && (
                <ComponentPicker isOpen={pickerOpen} companyId={companyId} exclude={[productId, ...lines.map((l) => l.component_id)]}
                    onPick={addComponent} onClose={() => setPickerOpen(false)} />
            )}

            <Modal isOpen={confirmOpen} toggle={() => !sending && setConfirmOpen(false)} centered>
                <ModalHeader toggle={() => !sending && setConfirmOpen(false)}>Gravar na PingWin</ModalHeader>
                <ModalBody>
                    <p className="mb-2">
                        {draft.mode === "criar" && <>Vai criar a ficha técnica com <strong>{lines.length}</strong> {lines.length === 1 ? "componente" : "componentes"}, a valer a partir de <strong>{fmtDate(draft.valid_from)}</strong>.</>}
                        {draft.mode === "nova_versao" && <>Vai criar uma <strong>versão nova</strong> com <strong>{lines.length}</strong> {lines.length === 1 ? "componente" : "componentes"}, a valer a partir de <strong>{fmtDate(draft.valid_from)}</strong>. As versões anteriores ficam no histórico.</>}
                        {draft.mode === "corrigir" && <>Vai <strong>corrigir a versão de {fmtDate(corrigirVersion?.valid_from)}</strong>: a receita usada desde essa data muda.</>}
                    </p>
                    <ul className="fs-13 mb-2">
                        {effects.map((e) => (
                            <li key={e.store_id} className={e.without_bom ? "text-danger" : undefined}>
                                {e.name}: {e.without_bom ? "fica sem ficha em vigor" : e.changes ? `passa a usar esta versão ${draft.mode === "corrigir" ? "" : `a partir de ${fmtDate(e.from)}`}` : `continua com a versão de ${fmtDate(e.after)}`}
                            </li>
                        ))}
                    </ul>
                    <p className="text-muted fs-12 mb-0"><i className="ri-information-line me-1" aria-hidden />Uma ficha de cada vez: abre, compara com a XPLENDOR, grava e relê. Se a ficha tiver mudado na PingWin, pára sem gravar.</p>
                </ModalBody>
                <ModalFooter>
                    <Button type="button" color="light" onClick={() => setConfirmOpen(false)} disabled={sending}>Cancelar</Button>
                    <Button type="button" color="primary" onClick={send} disabled={sending}>
                        {sending ? <><Spinner size="sm" className="me-1" />A pôr na fila…</> : "Gravar na PingWin"}
                    </Button>
                </ModalFooter>
            </Modal>

            {write && companyId && (
                <WriteProgressModal companyId={companyId} write={write} onClose={() => setWrite(null)} onFinished={onFinished} />
            )}
        </div>
    );
}
