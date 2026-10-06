import { useEffect, useState } from "react";
import { Badge, Button, Col, Input, Label, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { savePostContent } from "helpers/laravel_helper";
import { Network, POST_CHANNEL_META, mediaFormatLabel } from "common/models/editorialPost.model";
import { PostWorkflow, VERSION_STATUS_LABEL } from "common/models/editorialWorkflow.model";
import PostPreview from "../PostPreview";
import VersionMediaEditor from "../VersionMediaEditor";

/**
 * Conteúdo da publicação: um só texto para as redes escolhidas, com uma legenda própria
 * opcional no Facebook ("Usar uma legenda diferente no Facebook"); os ficheiros; o criativo
 * sugerido; e a pré-visualização em cada rede. Uma versão congelada cria a seguinte ao
 * guardar (o servidor decide).
 */

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Draft = { caption: string; fbOwn: boolean; fbCaption: string; hashtags: string; cta: string; first_comment: string };

type Props = { companyId: number; data: PostWorkflow; onChanged: (d: PostWorkflow) => void; onCreative: () => void; onDirty: (dirty: boolean) => void };

export default function ContentSection({ companyId, data, onChanged, onCreative, onDirty }: Props) {
    const p = data.post;
    const current = data.versions.find((v) => v.id === p.current_version_id) ?? null;
    const networks = p.networks.filter((n) => n.state !== "skipped").map((n) => n.network);
    const both = networks.includes("instagram") && networks.includes("facebook");
    const [draft, setDraft] = useState<Draft>({ caption: "", fbOwn: false, fbCaption: "", hashtags: "", cta: "", first_comment: "" });
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [view, setView] = useState<"edit" | "preview">("edit");
    const canEdit = data.permissions.can_edit_content;

    useEffect(() => {
        const src = current ?? (data.creative ? { ...data.creative, first_comment: null, network_captions: {} as Record<string, string> } : null);
        const fb = (src as any)?.network_captions?.facebook ?? "";
        setDraft({
            caption: src?.caption ?? "", fbOwn: !!fb, fbCaption: fb, hashtags: (src?.hashtags ?? []).join(" "),
            cta: src?.cta ?? "", first_comment: (src as any)?.first_comment ?? "",
        });
        setDirty(false);
        onDirty(false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data]);

    const set = (patch: Partial<Draft>) => { setDraft((d) => ({ ...d, ...patch })); setDirty(true); onDirty(true); };

    const save = async () => {
        setBusy(true);
        try {
            const r: any = await savePostContent(companyId, p.id, {
                caption: draft.caption, cta: draft.cta, first_comment: draft.first_comment,
                hashtags: draft.hashtags.split(/[\s,]+/).filter(Boolean),
                network_captions: both && draft.fbOwn && draft.fbCaption.trim() ? { facebook: draft.fbCaption } : {},
            });
            toast.success("Conteúdo guardado.");
            onChanged(r.data);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar o conteúdo."));
        } finally {
            setBusy(false);
        }
    };

    const captionFor = (n: Network) => (n === "facebook" && draft.fbOwn && draft.fbCaption.trim() ? draft.fbCaption : draft.caption);
    const coverFormat = p.networks.map((n) => n.media_format).find((f) => f && ["ig_reel", "fb_reel", "fb_video"].includes(f)) ?? p.networks[0]?.media_format ?? null;

    return (
        <div>
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div className="btn-group btn-group-sm" role="group" aria-label="Vista do conteúdo">
                    <Button color="primary" outline={view !== "edit"} onClick={() => setView("edit")}><i className="ri-edit-line me-1" />Editar</Button>
                    <Button color="primary" outline={view !== "preview"} onClick={() => setView("preview")}><i className="ri-smartphone-line me-1" />Pré-visualização</Button>
                </div>
                <span className="fs-13 text-muted">
                    {current ? <>Versão {current.number} <Badge color="light" className="text-body fw-normal">{VERSION_STATUS_LABEL[current.status]}</Badge></> : "Ainda sem versão"}
                    {dirty && <span className="text-warning ms-2">Alterações por guardar</span>}
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
                        <textarea id="pc-caption" className="form-control" rows={6} maxLength={2200} disabled={!canEdit} value={draft.caption} onChange={(e) => set({ caption: e.target.value })} />
                    </div>
                    {both && (
                        <div className="mb-2">
                            <div className="form-check form-switch">
                                <Input type="checkbox" role="switch" className="form-check-input" id="pc-fb-own" disabled={!canEdit} checked={draft.fbOwn} onChange={(e) => set({ fbOwn: e.target.checked })} />
                                <Label className="form-check-label fs-13" for="pc-fb-own">Usar uma legenda diferente no Facebook</Label>
                            </div>
                            {draft.fbOwn && (
                                <textarea className="form-control mt-1" rows={4} maxLength={2200} disabled={!canEdit} aria-label="Legenda no Facebook" placeholder="Legenda só para o Facebook"
                                    value={draft.fbCaption} onChange={(e) => set({ fbCaption: e.target.value })} />
                            )}
                        </div>
                    )}
                    <Row className="g-2">
                        <Col md={6}><Label className="mb-1" for="pc-tags">Hashtags</Label><Input id="pc-tags" value={draft.hashtags} disabled={!canEdit} onChange={(e) => set({ hashtags: e.target.value })} placeholder="#inverno #estrada" /></Col>
                        <Col md={6}><Label className="mb-1" for="pc-cta">Chamada à ação</Label><Input id="pc-cta" value={draft.cta} maxLength={300} disabled={!canEdit} onChange={(e) => set({ cta: e.target.value })} /></Col>
                        <Col xs={12}><Label className="mb-1" for="pc-first">Primeiro comentário</Label><Input id="pc-first" value={draft.first_comment} maxLength={2200} disabled={!canEdit} onChange={(e) => set({ first_comment: e.target.value })} /></Col>
                    </Row>
                    <div className="d-flex flex-wrap gap-2 mt-2">
                        {canEdit && <Button color="success" size="sm" disabled={busy || !dirty} onClick={() => void save()}>{busy ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />Guardar conteúdo</>}</Button>}
                        {data.permissions.can_produce && <Button color="soft-primary" size="sm" onClick={onCreative}><i className="ri-magic-line me-1" />Criativo sugerido</Button>}
                    </div>
                    <VersionMediaEditor companyId={companyId} postId={p.id} media={current?.media ?? { items: [], cover: null }}
                        validation={data.media_validation} mediaFormat={coverFormat} canEdit={canEdit}
                        onChanged={(d) => onChanged(d)} />
                </>
            )}
        </div>
    );
}
