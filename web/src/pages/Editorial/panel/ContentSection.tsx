import { forwardRef, useCallback, useEffect, useImperativeHandle, useRef, useState } from "react";
import { Badge, Button, Col, Input, Label, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { savePostContent } from "helpers/laravel_helper";
import { Network, POST_CHANNEL_META, mediaFormatLabel } from "common/models/editorialPost.model";
import { PostWorkflow, VERSION_STATUS_LABEL } from "common/models/editorialWorkflow.model";
import PostPreview from "../PostPreview";
import VersionMediaEditor from "../VersionMediaEditor";
import { dirtyKeys, mergeDraft } from "./draftMerge";

/**
 * Conteúdo da publicação: um só texto para as redes escolhidas, com uma legenda própria
 * opcional no Facebook ("Usar uma legenda diferente no Facebook"); os ficheiros; o criativo
 * sugerido; e a pré-visualização em cada rede. Uma versão congelada cria a seguinte ao
 * guardar (o servidor decide).
 *
 * O que a pessoa escreve nunca se perde: os dados que chegam do servidor (depois de enviar,
 * ordenar ou remover ficheiros) só atualizam os campos que a pessoa não alterou; o texto
 * grava-se sozinho ao sair do campo e pouco depois de parar de escrever ("A gravar…",
 * "Gravado"). Numa versão congelada (enviada ou aprovada), gravar cria a versão seguinte e
 * pede nova aprovação: aí só se grava com o botão, e o texto fica guardado no painel até lá.
 */

const AUTOSAVE_MS = 1200;

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Draft = { caption: string; fbOwn: boolean; fbCaption: string; hashtags: string; cta: string; first_comment: string };
type SaveStatus = "idle" | "dirty" | "saving" | "saved" | "error";

/** O conteúdo gravado na versão atual (ou o criativo aceite, antes da primeira versão). */
function fromServer(data: PostWorkflow): Draft {
    const current = data.versions.find((v) => v.id === data.post.current_version_id) ?? null;
    const src = current ?? (data.creative ? { ...data.creative, first_comment: null, network_captions: {} as Record<string, string> } : null);
    const fb = (src as any)?.network_captions?.facebook ?? "";
    return {
        caption: src?.caption ?? "", fbOwn: !!fb, fbCaption: fb, hashtags: (src?.hashtags ?? []).join(" "),
        cta: src?.cta ?? "", first_comment: (src as any)?.first_comment ?? "",
    };
}

/** O que o painel pode pedir ao separador: gravar já (ao fechar, ao mudar de etapa). */
export type ContentHandle = { flush: () => Promise<boolean>; isDirty: () => boolean };

type Props = { companyId: number; data: PostWorkflow; onChanged: (d: PostWorkflow) => void; onCreative: () => void; onDirty: (dirty: boolean) => void };

const ContentSection = forwardRef<ContentHandle, Props>(function ContentSection({ companyId, data, onChanged, onCreative, onDirty }, ref) {
    const p = data.post;
    const current = data.versions.find((v) => v.id === p.current_version_id) ?? null;
    const networks = p.networks.filter((n) => n.state !== "skipped").map((n) => n.network);
    const both = networks.includes("instagram") && networks.includes("facebook");
    const [view, setView] = useState<"edit" | "preview">("edit");
    const canEdit = data.permissions.can_edit_content;
    const frozen = !!current?.frozen;

    // Rascunho do ecrã, último valor do servidor (base) e o que está a ser gravado (sent).
    const [draft, setDraftState] = useState<Draft>(() => fromServer(data));
    const draftRef = useRef(draft);
    const baseRef = useRef<Draft>(fromServer(data));
    const sentRef = useRef<Draft | null>(null);
    const savingRef = useRef<Promise<boolean> | null>(null);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [status, setStatus] = useState<SaveStatus>("idle");
    const setDraft = (next: Draft) => { draftRef.current = next; setDraftState(next); };
    const isDirty = () => dirtyKeys(draftRef.current, baseRef.current).length > 0;

    // Dados novos do servidor (ficheiros, gravação, etapa): só os campos não alterados mudam.
    useEffect(() => {
        const server = fromServer(data);
        setDraft(mergeDraft(draftRef.current, baseRef.current, server, sentRef.current));
        baseRef.current = server;
        sentRef.current = null;
        const dirty = isDirty();
        onDirty(dirty);
        setStatus((st) => (dirty ? (st === "saving" ? st : "dirty") : st === "dirty" ? "saved" : st));
        // Uma ação de ficheiros numa versão congelada cria a seguinte: o texto por gravar grava-se nela.
        const nowFrozen = !!data.versions.find((v) => v.id === data.post.current_version_id)?.frozen;
        if (dirty && data.permissions.can_edit_content && !nowFrozen && !savingRef.current) {
            if (timerRef.current) clearTimeout(timerRef.current);
            timerRef.current = setTimeout(() => { void saveRef.current(); }, AUTOSAVE_MS);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data]);

    const payload = (d: Draft) => ({
        caption: d.caption, cta: d.cta, first_comment: d.first_comment,
        hashtags: d.hashtags.split(/[\s,]+/).filter(Boolean),
        network_captions: both && d.fbOwn && d.fbCaption.trim() ? { facebook: d.fbCaption } : {},
    });

    /** Grava o rascunho (uma gravação de cada vez; o que se escreveu entretanto grava-se a seguir). */
    const save = useCallback(async (): Promise<boolean> => {
        if (timerRef.current) { clearTimeout(timerRef.current); timerRef.current = null; }
        if (savingRef.current) { await savingRef.current; }
        if (!canEdit || !isDirty()) return true;
        const snapshot = { ...draftRef.current };
        setStatus("saving");
        const run = (async () => {
            try {
                const r: any = await savePostContent(companyId, p.id, payload(snapshot));
                sentRef.current = snapshot;
                onChanged(r.data);
                setStatus(isDirty() ? "dirty" : "saved");
                return true;
            } catch (e: any) {
                setStatus("error");
                toast.error(errorMessage(e, "Não foi possível gravar o conteúdo. O texto continua no ecrã."));
                return false;
            }
        })();
        savingRef.current = run;
        const ok = await run;
        savingRef.current = null;
        return ok;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [canEdit, companyId, p.id, both]);

    const saveRef = useRef(save);
    saveRef.current = save;
    useImperativeHandle(ref, () => ({ flush: save, isDirty }), [save]);

    const set = (patch: Partial<Draft>) => {
        setDraft({ ...draftRef.current, ...patch });
        const dirty = isDirty();
        onDirty(dirty);
        if (!dirty) return;
        setStatus("dirty");
        // Gravação automática pouco depois de parar de escrever (não numa versão congelada).
        if (timerRef.current) clearTimeout(timerRef.current);
        if (canEdit && !frozen) timerRef.current = setTimeout(() => { void save(); }, AUTOSAVE_MS);
    };
    /** Ao sair de um campo, grava logo (não numa versão congelada). */
    const onBlur = () => { if (canEdit && !frozen && isDirty()) void save(); };
    useEffect(() => () => { if (timerRef.current) clearTimeout(timerRef.current); }, []);
    const dirty = isDirty();

    const captionFor = (n: Network) => (n === "facebook" && draft.fbOwn && draft.fbCaption.trim() ? draft.fbCaption : draft.caption);
    const coverFormat = p.networks.map((n) => n.media_format).find((f) => f && ["ig_reel", "fb_reel", "fb_video"].includes(f)) ?? p.networks[0]?.media_format ?? null;

    return (
        <div>
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div className="btn-group btn-group-sm" role="group" aria-label="Vista do conteúdo">
                    <Button color="primary" outline={view !== "edit"} onClick={() => setView("edit")}><i className="ri-edit-line me-1" />Editar</Button>
                    <Button color="primary" outline={view !== "preview"} onClick={() => setView("preview")}><i className="ri-smartphone-line me-1" />Pré-visualização</Button>
                </div>
                <span className="fs-13 text-muted d-flex align-items-center gap-2">
                    {current ? <>Versão {current.number} <Badge color="light" className="text-body fw-normal">{VERSION_STATUS_LABEL[current.status]}</Badge></> : "Ainda sem versão"}
                    <span data-save-status={status} aria-live="polite" className={status === "error" ? "text-danger" : status === "dirty" ? "text-warning" : "text-muted"}>
                        {status === "saving" ? <><Spinner size="sm" className="me-1" />A gravar…</>
                            : status === "saved" ? <><i className="ri-check-line me-1" />Gravado</>
                                : status === "error" ? <><i className="ri-error-warning-line me-1" />Não gravado</>
                                    : dirty ? (frozen ? "Alterações por gravar" : "Por gravar") : null}
                    </span>
                </span>
            </div>

            {view === "preview" ? (
                <Row className="g-3">
                    {networks.map((n) => (
                        <Col md={networks.length > 1 ? 6 : 12} key={n}>
                            <div className="fs-12 text-muted text-center mb-1"><i className={`${POST_CHANNEL_META[n].icon} me-1`} />{POST_CHANNEL_META[n].label}{p.networks.find((x) => x.network === n)?.media_format ? ` · ${mediaFormatLabel(p.networks.find((x) => x.network === n)?.media_format)}` : ""}</div>
                            <PostPreview channel={n} mediaFormat={p.networks.find((x) => x.network === n)?.media_format ?? null} caption={captionFor(n)}
                                hashtags={draft.hashtags.split(/[\s,]+/).filter(Boolean)} items={current?.media.items ?? []} cover={current?.media.cover ?? null}
                                accountName={p.account_name || "conta"} />
                        </Col>
                    ))}
                    <Col xs={12}><p className="text-muted fs-11 text-center mb-0">Pré-visualização aproximada: a rede pode ajustar margens, cortes e o tamanho do texto.</p></Col>
                </Row>
            ) : (
                <>
                    {current?.frozen && canEdit && (
                        <div className="alert alert-light border fs-12 py-2">
                            A versão {current.number} está congelada ({VERSION_STATUS_LABEL[current.status].toLowerCase()}). Ao guardar, nasce a versão {current.number + 1}
                            {(p.stage === "client_review" || p.stage === "scheduled") ? " e a publicação volta a Produção para nova aprovação" : ""}.
                        </div>
                    )}
                    {!current && data.creative && <p className="text-muted fs-12">Pré-preenchido com o criativo aceite.</p>}
                    <div className="mb-2">
                        <div className="d-flex justify-content-between"><Label className="mb-1" for="pc-caption">Legenda{both ? " (Instagram e Facebook)" : ""}</Label><small className="text-muted">{draft.caption.length}/2200</small></div>
                        <textarea id="pc-caption" className="form-control" rows={6} maxLength={2200} disabled={!canEdit} value={draft.caption} onChange={(e) => set({ caption: e.target.value })} onBlur={onBlur} />
                    </div>
                    {both && (
                        <div className="mb-2">
                            <div className="form-check form-switch">
                                <Input type="checkbox" role="switch" className="form-check-input" id="pc-fb-own" disabled={!canEdit} checked={draft.fbOwn} onChange={(e) => set({ fbOwn: e.target.checked })} onBlur={onBlur} />
                                <Label className="form-check-label fs-13" for="pc-fb-own">Usar uma legenda diferente no Facebook</Label>
                            </div>
                            {draft.fbOwn && (
                                <textarea className="form-control mt-1" rows={4} maxLength={2200} disabled={!canEdit} aria-label="Legenda no Facebook" placeholder="Legenda só para o Facebook"
                                    value={draft.fbCaption} onChange={(e) => set({ fbCaption: e.target.value })} onBlur={onBlur} />
                            )}
                        </div>
                    )}
                    <Row className="g-2">
                        <Col md={6}><Label className="mb-1" for="pc-tags">Hashtags</Label><Input id="pc-tags" value={draft.hashtags} disabled={!canEdit} onChange={(e) => set({ hashtags: e.target.value })} onBlur={onBlur} placeholder="#inverno #estrada" /></Col>
                        <Col md={6}><Label className="mb-1" for="pc-cta">Chamada à ação</Label><Input id="pc-cta" value={draft.cta} maxLength={300} disabled={!canEdit} onChange={(e) => set({ cta: e.target.value })} onBlur={onBlur} /></Col>
                        <Col xs={12}><Label className="mb-1" for="pc-first">Primeiro comentário</Label><Input id="pc-first" value={draft.first_comment} maxLength={2200} disabled={!canEdit} onChange={(e) => set({ first_comment: e.target.value })} onBlur={onBlur} /></Col>
                    </Row>
                    <div className="d-flex flex-wrap gap-2 mt-2">
                        {canEdit && <Button color="success" size="sm" disabled={status === "saving" || !dirty} onClick={() => void save()}>{status === "saving" ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />{frozen ? `Gravar (nasce a versão ${(current?.number ?? 0) + 1})` : "Gravar agora"}</>}</Button>}
                        {data.permissions.can_produce && <Button color="soft-primary" size="sm" onClick={onCreative}><i className="ri-magic-line me-1" />Criativo sugerido</Button>}
                    </div>
                    <VersionMediaEditor companyId={companyId} postId={p.id} media={current?.media ?? { items: [], cover: null }}
                        validation={data.media_validation} mediaFormat={coverFormat} canEdit={canEdit}
                        onChanged={(d) => onChanged(d)} />
                </>
            )}
        </div>
    );
});

export default ContentSection;
