import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Col, Input, Label, Modal, ModalBody, ModalHeader, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { approvePost, commentOnPost, getPostWorkflow, movePostStage, requestPostChanges, savePostContent } from "helpers/laravel_helper";
import { MEDIA_FORMATS, POST_CHANNEL_META } from "common/models/editorialPost.model";
import PostPreview from "./PostPreview";
import VersionMediaEditor from "./VersionMediaEditor";
import MarkPublishedModal from "./MarkPublishedModal";
import PostResultsSection from "./PostResultsSection";
import { PostWorkflow, STAGE_META, STAGE_ORDER, Stage, VERSION_STATUS_LABEL, fmtDateTimePt, mediaSrc } from "common/models/editorialWorkflow.model";

/**
 * Produção de uma publicação (F3a): o conteúdo por versões, as passagens de etapa que o
 * utilizador pode fazer, aprovar ou pedir alterações (aprovadores da empresa, nunca em
 * sessão como cliente), comentários (internos só para a equipa) e o histórico com a pessoa
 * real. Na F3b, os ficheiros da versão (imagem, carrossel, vídeo com capa) e a
 * pré-visualização como na rede. O servidor decide sempre o que é permitido; aqui só se mostra.
 */

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

const dmy = (iso: string) => { const [y, m, d] = iso.split("-"); return `${d}/${m}/${y}`; };

type Draft = { caption: string; hashtags: string; cta: string; first_comment: string; media_format: string };
const EMPTY: Draft = { caption: "", hashtags: "", cta: "", first_comment: "", media_format: "" };

type Props = { isOpen: boolean; toggle: () => void; companyId: number; postId: number | null; onChanged: () => void };

export default function PostWorkflowModal({ isOpen, toggle, companyId, postId, onChanged }: Props) {
    const [data, setData] = useState<PostWorkflow | null>(null);
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [comment, setComment] = useState("");
    const [internal, setInternal] = useState(false);
    const [changesOpen, setChangesOpen] = useState(false);
    const [changesMsg, setChangesMsg] = useState("");
    const [viewVersion, setViewVersion] = useState<number | null>(null);
    const [tab, setTab] = useState<"edit" | "preview">("edit");
    const [marking, setMarking] = useState(false);

    const apply = useCallback((d: PostWorkflow) => {
        setData(d);
        const current = d.versions.find((v) => v.id === d.post.current_version_id) ?? null;
        const src = current ?? (d.creative ? { ...d.creative, first_comment: null } : null);
        setDraft(src ? {
            caption: src.caption ?? "", hashtags: (src.hashtags ?? []).join(" "), cta: src.cta ?? "",
            first_comment: (src as any).first_comment ?? "", media_format: src.media_format ?? "",
        } : { ...EMPTY, media_format: d.post.media_format ?? "" });
        setDirty(false);
        setViewVersion(null);
    }, []);

    const load = useCallback(async () => {
        if (!postId) return;
        try {
            const r: any = await getPostWorkflow(companyId, postId);
            apply(r.data);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível abrir a publicação."));
        }
    }, [companyId, postId, apply]);

    useEffect(() => { if (isOpen) { setData(null); setComment(""); setChangesOpen(false); setTab("edit"); void load(); } }, [isOpen, load]);

    // Mudar os ficheiros não deve apagar o texto por guardar.
    const mediaChanged = (d: PostWorkflow) => {
        if (dirty) setData(d); else apply(d);
        onChanged();
    };

    const run = async (fn: () => Promise<any>, ok: string) => {
        setBusy(true);
        try {
            const r: any = await fn();
            apply(r.data);
            toast.success(ok);
            onChanged();
            return true;
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível concluir a ação."));
            return false;
        } finally {
            setBusy(false);
        }
    };

    const save = () => run(() => savePostContent(companyId, postId!, {
        caption: draft.caption, cta: draft.cta, first_comment: draft.first_comment, media_format: draft.media_format || null,
        hashtags: draft.hashtags.split(/[\s,]+/).filter(Boolean),
    }), "Conteúdo guardado.");

    const move = async (stage: Stage) => {
        if (dirty && !(await save())) return;
        await run(() => movePostStage(companyId, postId!, stage), `Passou para ${STAGE_META[stage].label}.`);
    };

    const set = (k: keyof Draft, v: string) => { setDraft((d) => ({ ...d, [k]: v })); setDirty(true); };

    if (!isOpen) return null;

    const p = data?.post;
    const current = data?.versions.find((v) => v.id === p?.current_version_id) ?? null;
    const shown = viewVersion ? data?.versions.find((v) => v.number === viewVersion) ?? null : null;
    const allowed = data ? (Object.entries(data.moves).filter(([, r]) => r === null).map(([s]) => s as Stage)) : [];
    const blocked = data ? (Object.entries(data.moves).filter(([, r]) => r !== null) as [Stage, string][]) : [];
    const forward = allowed.filter((s) => p && STAGE_ORDER.indexOf(s) > STAGE_ORDER.indexOf(p.stage));
    const backward = allowed.filter((s) => p && STAGE_ORDER.indexOf(s) < STAGE_ORDER.indexOf(p.stage));
    const canEdit = !!data?.permissions.can_edit_content;
    const formats = p && (p.channel === "instagram" || p.channel === "facebook") ? MEDIA_FORMATS[p.channel] : [];

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="xl" centered scrollable>
            <ModalHeader toggle={toggle}>
                {p ? (
                    <span className="d-flex flex-wrap align-items-center gap-2">
                        <i className={POST_CHANNEL_META[p.channel].icon} />
                        <span className="text-break">{p.title}</span>
                        <Badge color={`${STAGE_META[p.stage].color}-subtle`} className={`text-${STAGE_META[p.stage].color === "dark" ? "body" : STAGE_META[p.stage].color} fs-12`}>
                            <i className={`${STAGE_META[p.stage].icon} me-1`} />{STAGE_META[p.stage].label}
                        </Badge>
                        <span className="text-muted fs-13 fw-normal">{dmy(p.publish_date)}{p.publish_time ? ` · ${p.publish_time}` : ""}</span>
                        {p.overdue && <Badge color="danger" className="fs-12">Atrasada</Badge>}
                    </span>
                ) : "Produção"}
            </ModalHeader>
            <ModalBody>
                {!data || !p ? (
                    <div className="text-center py-5"><Spinner /></div>
                ) : (
                    <>
                        {/* Ações: avançar, recuar, aprovar ou pedir alterações. */}
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                            {forward.map((s) => (
                                <Button key={s} color="primary" size="sm" disabled={busy} onClick={() => move(s)}>
                                    <i className={`${STAGE_META[s].icon} me-1`} />
                                    {s === "client_review" ? "Enviar ao cliente" : s === "internal_review" ? "Enviar para revisão interna" : `Passar a ${STAGE_META[s].label}`}
                                </Button>
                            ))}
                            {p.stage === "scheduled" && data.publishing.can_mark && (
                                <Button color="success" size="sm" disabled={busy} onClick={() => setMarking(true)}>
                                    <i className="ri-checkbox-circle-line me-1" />Marcar como publicada
                                </Button>
                            )}
                            {data.permissions.can_approve && (
                                <>
                                    <Button color="success" size="sm" disabled={busy} onClick={() => run(() => approvePost(companyId, p.id), "Publicação aprovada.")}>
                                        <i className="ri-check-double-line me-1" />Aprovar a versão {current?.number}
                                    </Button>
                                    <Button color="warning" size="sm" disabled={busy} onClick={() => setChangesOpen((o) => !o)}>
                                        <i className="ri-chat-1-line me-1" />Pedir alterações
                                    </Button>
                                </>
                            )}
                            {backward.length > 0 && (
                                <Input type="select" bsSize="sm" style={{ width: "auto" }} value="" disabled={busy} aria-label="Recuar para"
                                    onChange={(e) => e.target.value && move(e.target.value as Stage)}>
                                    <option value="">{p.stage === "client_review" ? "Retirar da aprovação…" : "Recuar para…"}</option>
                                    {backward.map((s) => <option key={s} value={s}>{STAGE_META[s].label}</option>)}
                                </Input>
                            )}
                            {busy && <Spinner size="sm" />}
                        </div>
                        {!data.permissions.can_produce && data.settings.production_mode === "team" && (
                            <div className="alert alert-info fs-13 py-2">
                                <i className="ri-team-line me-1" />A produção desta empresa é feita pela equipa XPLENDOR. Pode comentar, aprovar ou pedir alterações.
                            </div>
                        )}
                        {blocked.length > 0 && forward.length === 0 && !data.permissions.can_approve && (
                            <p className="text-muted fs-12 mb-3"><i className="ri-information-line me-1" />{blocked[0][1]}</p>
                        )}
                        {p.stage === "client_review" && !data.permissions.can_approve && (
                            <div className="alert alert-warning fs-13 py-2">À espera da aprovação do cliente{data.permissions.is_team ? " (a aprovação não é possível em sessão como cliente)" : ""}.</div>
                        )}
                        {p.overdue && (
                            <div className="alert alert-danger fs-13 py-2">
                                <i className="ri-alarm-warning-line me-1" /><strong>Atrasada.</strong> Estava programada para {dmy(p.publish_date)}{p.publish_time ? ` às ${p.publish_time}` : ""} e ainda não foi marcada como publicada.
                            </div>
                        )}
                        {data.results && (
                            <PostResultsSection data={data} companyId={companyId} onChanged={(d) => { apply(d); onChanged(); }} onEditPublished={() => setMarking(true)} />
                        )}
                        {changesOpen && (
                            <div className="border rounded p-2 mb-3">
                                <Label className="fs-13 mb-1">O que deve ser alterado?</Label>
                                <textarea className="form-control mb-2" rows={3} maxLength={2000} value={changesMsg} onChange={(e) => setChangesMsg(e.target.value)} />
                                <Button color="warning" size="sm" disabled={busy || !changesMsg.trim()}
                                    onClick={async () => { if (await run(() => requestPostChanges(companyId, p.id, changesMsg.trim()), "Alterações pedidas.")) { setChangesOpen(false); setChangesMsg(""); } }}>
                                    Devolver à produção
                                </Button>
                            </div>
                        )}
                        {p.changes_requested_at && p.stage === "production" && data.reviews[0]?.decision === "changes_requested" && (
                            <div className="alert alert-warning fs-13 py-2">
                                <strong>Alterações pedidas</strong> por {data.reviews[0].reviewer ?? "o cliente"}: {data.reviews[0].message}
                            </div>
                        )}

                        <Row className="g-3">
                            <Col lg={7}>
                                <div className="btn-group btn-group-sm mb-3" role="group" aria-label="Vista do conteúdo">
                                    <Button color="primary" outline={tab !== "edit"} onClick={() => setTab("edit")}><i className="ri-edit-line me-1" />Conteúdo</Button>
                                    <Button color="primary" outline={tab !== "preview"} onClick={() => setTab("preview")}><i className="ri-smartphone-line me-1" />Pré-visualização</Button>
                                </div>
                                {tab === "preview" ? (
                                    <>
                                        <PostPreview channel={p.channel} mediaFormat={draft.media_format || null} caption={draft.caption}
                                            hashtags={draft.hashtags.split(/[\s,]+/).filter(Boolean)} items={current?.media.items ?? []} cover={current?.media.cover ?? null}
                                            accountName={p.account_name || "conta"} />
                                        <p className="text-muted fs-11 text-center mt-2 mb-0">Aproximação: a rede pode ajustar margens, cortes e o tamanho do texto.</p>
                                    </>
                                ) : (<>
                                <div className="d-flex align-items-center justify-content-between mb-2">
                                    <h6 className="mb-0">
                                        Conteúdo {current ? <>· versão {current.number} <Badge color="light" className="text-body fw-normal">{VERSION_STATUS_LABEL[current.status]}</Badge></> : <span className="text-muted fw-normal">· ainda sem versão</span>}
                                    </h6>
                                    {dirty && <span className="text-warning fs-12">Alterações por guardar</span>}
                                </div>
                                {current?.frozen && canEdit && (
                                    <div className="alert alert-light border fs-12 py-2">
                                        A versão {current.number} está congelada ({VERSION_STATUS_LABEL[current.status].toLowerCase()}). Ao guardar, nasce a versão {current.number + 1}
                                        {(p.stage === "client_review" || p.stage === "scheduled") ? " e a publicação volta a Produção para nova aprovação" : ""}.
                                    </div>
                                )}
                                {!current && data.creative && <p className="text-muted fs-12">Pré-preenchido com o criativo aceite.</p>}
                                <div className="mb-2">
                                    <div className="d-flex justify-content-between"><Label className="mb-1">Legenda</Label><small className="text-muted">{draft.caption.length}/2200</small></div>
                                    <textarea className="form-control" rows={7} maxLength={2200} disabled={!canEdit} value={draft.caption} onChange={(e) => set("caption", e.target.value)} />
                                </div>
                                <Row className="g-2">
                                    <Col md={6}>
                                        <Label className="mb-1">Hashtags</Label>
                                        <Input value={draft.hashtags} disabled={!canEdit} onChange={(e) => set("hashtags", e.target.value)} placeholder="#inverno #estrada" />
                                    </Col>
                                    <Col md={6}>
                                        <Label className="mb-1">Formato</Label>
                                        <Input type="select" value={draft.media_format} disabled={!canEdit} onChange={(e) => set("media_format", e.target.value)}>
                                            <option value="">Por escolher</option>
                                            {formats.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
                                        </Input>
                                    </Col>
                                    <Col md={6}>
                                        <Label className="mb-1">Chamada à ação</Label>
                                        <Input value={draft.cta} maxLength={300} disabled={!canEdit} onChange={(e) => set("cta", e.target.value)} />
                                    </Col>
                                    <Col md={6}>
                                        <Label className="mb-1">Primeiro comentário</Label>
                                        <Input value={draft.first_comment} maxLength={2200} disabled={!canEdit} onChange={(e) => set("first_comment", e.target.value)} />
                                    </Col>
                                </Row>
                                {canEdit && (
                                    <div className="mt-2">
                                        <Button color="success" size="sm" disabled={busy || !dirty} onClick={save}><i className="ri-save-line me-1" />Guardar</Button>
                                    </div>
                                )}
                                {(p.channel === "instagram" || p.channel === "facebook") && (
                                    <VersionMediaEditor companyId={companyId} postId={p.id} media={current?.media ?? { items: [], cover: null }}
                                        validation={data.media_validation} mediaFormat={current?.media_format ?? (draft.media_format || null)} canEdit={canEdit} onChanged={mediaChanged} />
                                )}
                                </>)}

                                {data.versions.length > 1 && (
                                    <div className="mt-4">
                                        <h6 className="mb-2">Versões</h6>
                                        <div className="vstack gap-1">
                                            {data.versions.map((v) => (
                                                <button key={v.id} type="button" className={`btn btn-sm text-start border ${viewVersion === v.number ? "btn-light" : "btn-ghost-secondary"}`}
                                                    onClick={() => setViewVersion(viewVersion === v.number ? null : v.number)}>
                                                    <strong>Versão {v.number}</strong> · {VERSION_STATUS_LABEL[v.status]}
                                                    <span className="text-muted fs-12"> · {v.author ?? ""} · {fmtDateTimePt(v.sent_at ?? v.created_at)}</span>
                                                </button>
                                            ))}
                                        </div>
                                        {shown && (
                                            <div className="border rounded p-2 mt-2 fs-13 bg-light-subtle">
                                                <div style={{ whiteSpace: "pre-line" }}>{shown.caption || <em className="text-muted">Sem legenda</em>}</div>
                                                {shown.hashtags.length > 0 && <div className="text-primary mt-1">{shown.hashtags.join(" ")}</div>}
                                                {shown.cta && <div className="mt-1"><strong>Chamada à ação:</strong> {shown.cta}</div>}
                                                {shown.media.items.length > 0 && (
                                                    <div className="d-flex flex-wrap gap-1 mt-2">
                                                        {shown.media.items.map((a) => <img key={a.id} src={mediaSrc(a.thumb_url)} alt="" className="rounded border" style={{ width: 48, height: 48, objectFit: "cover" }} />)}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </Col>

                            <Col lg={5}>
                                <h6 className="mb-2">Comentários</h6>
                                <div className="vstack gap-2 mb-2" style={{ maxHeight: 260, overflowY: "auto" }}>
                                    {data.comments.length === 0 && <p className="text-muted fs-13 mb-0">Ainda sem comentários.</p>}
                                    {data.comments.map((c) => (
                                        <div key={c.id} className={`border rounded p-2 fs-13 ${c.visibility === "internal" ? "border-warning-subtle bg-warning-subtle" : ""}`}>
                                            <div className="d-flex justify-content-between gap-2">
                                                <strong className="text-break">{c.author}</strong>
                                                <span className="text-muted fs-11 text-nowrap">{fmtDateTimePt(c.created_at)}</span>
                                            </div>
                                            <div style={{ whiteSpace: "pre-line" }}>{c.body}</div>
                                            <div className="fs-11 text-muted mt-1">
                                                {c.visibility === "internal" && <Badge color="warning" className="me-1">Interno</Badge>}
                                                {c.version_number ? `Versão ${c.version_number}` : ""}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <textarea className="form-control mb-2" rows={2} maxLength={5000} value={comment} onChange={(e) => setComment(e.target.value)}
                                    placeholder={internal ? "Nota interna (o cliente não vê)" : "Comentário (visível para o cliente e a equipa)"} />
                                <div className="d-flex align-items-center justify-content-between gap-2 mb-4">
                                    {data.permissions.is_team ? (
                                        <div className="form-check form-switch mb-0">
                                            <Input type="checkbox" role="switch" className="form-check-input" id="wf-internal" checked={internal} onChange={(e) => setInternal(e.target.checked)} />
                                            <Label className="form-check-label fs-13" for="wf-internal">Interno (só a equipa)</Label>
                                        </div>
                                    ) : <span />}
                                    <Button color="primary" size="sm" disabled={busy || !comment.trim()}
                                        onClick={async () => { if (await run(() => commentOnPost(companyId, p.id, comment.trim(), internal ? "internal" : "shared"), "Comentário publicado.")) setComment(""); }}>
                                        Comentar
                                    </Button>
                                </div>

                                {data.reviews.length > 0 && (
                                    <>
                                        <h6 className="mb-2">Decisões do cliente</h6>
                                        <ul className="list-unstyled fs-13 mb-4">
                                            {data.reviews.map((r) => (
                                                <li key={r.id} className="mb-1">
                                                    <i className={r.decision === "approved" ? "ri-check-double-line text-success me-1" : "ri-chat-1-line text-warning me-1"} />
                                                    {r.decision === "approved" ? "Aprovou" : "Pediu alterações"} a versão {r.version_number} · {r.reviewer}{r.via === "link" ? " (link de aprovação)" : ""}
                                                    <span className="text-muted"> · {fmtDateTimePt(r.created_at)}</span>
                                                    {r.message && <div className="text-muted ms-3">{r.message}</div>}
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}

                                <h6 className="mb-2">Histórico</h6>
                                <ul className="list-unstyled fs-12 mb-0" style={{ maxHeight: 220, overflowY: "auto" }}>
                                    {data.events.map((e, i) => (
                                        <li key={i} className="mb-1">
                                            <span className="text-muted">{fmtDateTimePt(e.created_at)}</span> · <strong>{e.who ?? "Sistema"}</strong>
                                            {" · "}
                                            {e.type === "created" ? `criou em ${STAGE_META[e.to_stage as Stage]?.label ?? ""}`
                                                : e.type === "stage" && e.from_stage && e.to_stage ? `${STAGE_META[e.from_stage].label} → ${STAGE_META[e.to_stage].label}`
                                                    : e.message}
                                            {e.type === "stage" && e.message && <span className="text-muted"> ({e.message})</span>}
                                        </li>
                                    ))}
                                </ul>
                            </Col>
                        </Row>
                    </>
                )}
            </ModalBody>
            {p && (
                <MarkPublishedModal isOpen={marking} toggle={() => setMarking(false)} companyId={companyId} post={{ id: p.id, title: p.title, channel: p.channel }}
                    initialUrl={data?.publishing.url} initialAt={data?.publishing.published_at}
                    onDone={(d) => { apply(d); onChanged(); }} />
            )}
        </Modal>
    );
}
