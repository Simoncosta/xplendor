import { useState } from "react";
import { Badge, Button, Col, Input, Label, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { approvePost, commentOnPost, movePostStage, requestPostChanges } from "helpers/laravel_helper";
import { PostWorkflow, STAGE_META, STAGE_ORDER, Stage, VERSION_STATUS_LABEL, fmtDateTimePt, mediaSrc } from "common/models/editorialWorkflow.model";
import XSelect from "../XSelect";

/**
 * Aprovação: as passagens de etapa permitidas, aprovar ou pedir alterações (uma decisão
 * cobre as redes escolhidas), as versões, os comentários (internos só para a equipa), as
 * decisões do cliente e o histórico com a pessoa real. O servidor decide o que é permitido.
 */

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Props = { companyId: number; data: PostWorkflow; onChanged: (d: PostWorkflow) => void; beforeMove: () => Promise<boolean> };

export default function ApprovalSection({ companyId, data, onChanged, beforeMove }: Props) {
    const p = data.post;
    const [busy, setBusy] = useState(false);
    const [comment, setComment] = useState("");
    const [internal, setInternal] = useState(false);
    const [changesOpen, setChangesOpen] = useState(false);
    const [changesMsg, setChangesMsg] = useState("");
    const [viewVersion, setViewVersion] = useState<number | null>(null);

    const run = async (fn: () => Promise<any>, ok: string) => {
        setBusy(true);
        try {
            const r: any = await fn();
            onChanged(r.data);
            toast.success(ok);
            return true;
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível concluir a ação."));
            return false;
        } finally {
            setBusy(false);
        }
    };
    const move = async (stage: Stage) => {
        if (!(await beforeMove())) return;
        await run(() => movePostStage(companyId, p.id, stage), `Passou para ${STAGE_META[stage].label}.`);
    };

    const allowed = Object.entries(data.moves).filter(([, r]) => r === null).map(([s]) => s as Stage);
    const blocked = Object.entries(data.moves).filter(([, r]) => r !== null) as [Stage, string][];
    const forward = allowed.filter((s) => STAGE_ORDER.indexOf(s) > STAGE_ORDER.indexOf(p.stage));
    const backward = allowed.filter((s) => STAGE_ORDER.indexOf(s) < STAGE_ORDER.indexOf(p.stage));
    const current = data.versions.find((v) => v.id === p.current_version_id) ?? null;
    const shown = viewVersion ? data.versions.find((v) => v.number === viewVersion) ?? null : null;

    return (
        <div>
            <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                {forward.map((s) => (
                    <Button key={s} color="primary" size="sm" disabled={busy} onClick={() => void move(s)}>
                        <i className={`${STAGE_META[s].icon} me-1`} />
                        {s === "client_review" ? "Enviar ao cliente" : s === "internal_review" ? "Enviar para revisão interna" : `Passar a ${STAGE_META[s].label}`}
                    </Button>
                ))}
                {data.permissions.can_approve && (
                    <>
                        <Button color="success" size="sm" disabled={busy} onClick={() => void run(() => approvePost(companyId, p.id), "Publicação aprovada.")}>
                            <i className="ri-check-double-line me-1" />Aprovar a versão {current?.number}
                        </Button>
                        <Button color="warning" size="sm" disabled={busy} onClick={() => setChangesOpen((o) => !o)}><i className="ri-chat-1-line me-1" />Pedir alterações</Button>
                    </>
                )}
                {backward.length > 0 && (
                    <XSelect small width={220} ariaLabel="Recuar para" placeholder={p.stage === "client_review" ? "Retirar da aprovação…" : "Recuar para…"}
                        options={backward.map((s) => ({ value: s, label: STAGE_META[s].label }))} value={null} disabled={busy} onChange={(s) => void move(s as Stage)} />
                )}
                {busy && <Spinner size="sm" />}
            </div>
            {p.networks.length > 1 && <p className="text-muted fs-12">Uma só aprovação para {p.networks.map((n) => n.network === "instagram" ? "Instagram" : "Facebook").join(" e ")}.</p>}
            {!data.permissions.can_produce && data.settings.production_mode === "team" && (
                <div className="alert alert-info fs-13 py-2"><i className="ri-team-line me-1" />A produção desta empresa é feita pela equipa XPLENDOR. Pode comentar, aprovar ou pedir alterações.</div>
            )}
            {blocked.length > 0 && forward.length === 0 && !data.permissions.can_approve && <p className="text-muted fs-12 mb-3"><i className="ri-information-line me-1" />{blocked[0][1]}</p>}
            {p.stage === "client_review" && !data.permissions.can_approve && (
                <div className="alert alert-warning fs-13 py-2">À espera da aprovação do cliente{data.permissions.is_team ? " (a aprovação não é possível em sessão como cliente)" : ""}.</div>
            )}
            {changesOpen && (
                <div className="border rounded p-2 mb-3">
                    <Label className="fs-13 mb-1" for="pa-changes">O que deve ser alterado?</Label>
                    <textarea id="pa-changes" className="form-control mb-2" rows={3} maxLength={2000} value={changesMsg} onChange={(e) => setChangesMsg(e.target.value)} />
                    <Button color="warning" size="sm" disabled={busy || !changesMsg.trim()}
                        onClick={async () => { if (await run(() => requestPostChanges(companyId, p.id, changesMsg.trim()), "Alterações pedidas.")) { setChangesOpen(false); setChangesMsg(""); } }}>
                        Devolver à produção
                    </Button>
                </div>
            )}
            {p.changes_requested_at && p.stage === "production" && data.reviews[0]?.decision === "changes_requested" && (
                <div className="alert alert-warning fs-13 py-2"><strong>Alterações pedidas</strong> por {data.reviews[0].reviewer ?? "o cliente"}: {data.reviews[0].message}</div>
            )}

            <Row className="g-3">
                <Col lg={6}>
                    <h6 className="mb-2">Comentários</h6>
                    <div className="vstack gap-2 mb-2" style={{ maxHeight: 280, overflowY: "auto" }}>
                        {data.comments.length === 0 && <p className="text-muted fs-13 mb-0">Ainda sem comentários.</p>}
                        {data.comments.map((c) => (
                            <div key={c.id} className={`border rounded p-2 fs-13 ${c.visibility === "internal" ? "border-warning-subtle bg-warning-subtle" : ""}`}>
                                <div className="d-flex justify-content-between gap-2"><strong className="text-break">{c.author}</strong><span className="text-muted fs-11 text-nowrap">{fmtDateTimePt(c.created_at)}</span></div>
                                <div style={{ whiteSpace: "pre-line" }}>{c.body}</div>
                                <div className="fs-11 text-muted mt-1">{c.visibility === "internal" && <Badge color="warning" className="me-1">Interno</Badge>}{c.version_number ? `Versão ${c.version_number}` : ""}</div>
                            </div>
                        ))}
                    </div>
                    <textarea className="form-control mb-2" rows={2} maxLength={5000} value={comment} aria-label="Comentário" onChange={(e) => setComment(e.target.value)}
                        placeholder={internal ? "Nota interna (o cliente não vê)" : "Comentário (visível para o cliente e a equipa)"} />
                    <div className="d-flex align-items-center justify-content-between gap-2">
                        {data.permissions.is_team ? (
                            <div className="form-check form-switch mb-0">
                                <Input type="checkbox" role="switch" className="form-check-input" id="pa-internal" checked={internal} onChange={(e) => setInternal(e.target.checked)} />
                                <Label className="form-check-label fs-13" for="pa-internal">Interno (só a equipa)</Label>
                            </div>
                        ) : <span />}
                        <Button color="primary" size="sm" disabled={busy || !comment.trim()}
                            onClick={async () => { if (await run(() => commentOnPost(companyId, p.id, comment.trim(), internal ? "internal" : "shared"), "Comentário publicado.")) setComment(""); }}>Comentar</Button>
                    </div>
                </Col>
                <Col lg={6}>
                    {data.versions.length > 0 && (
                        <>
                            <h6 className="mb-2">Versões</h6>
                            <div className="vstack gap-1 mb-3">
                                {data.versions.map((v) => (
                                    <button key={v.id} type="button" className={`btn btn-sm text-start border ${viewVersion === v.number ? "btn-light" : "btn-ghost-secondary"}`}
                                        onClick={() => setViewVersion(viewVersion === v.number ? null : v.number)}>
                                        <strong>Versão {v.number}</strong> · {VERSION_STATUS_LABEL[v.status]}<span className="text-muted fs-12"> · {v.author ?? ""} · {fmtDateTimePt(v.sent_at ?? v.created_at)}</span>
                                    </button>
                                ))}
                            </div>
                            {shown && (
                                <div className="border rounded p-2 mb-3 fs-13 bg-light-subtle">
                                    <div style={{ whiteSpace: "pre-line" }}>{shown.caption || <em className="text-muted">Sem legenda</em>}</div>
                                    {shown.network_captions?.facebook && <div className="mt-1"><strong>No Facebook:</strong> <span style={{ whiteSpace: "pre-line" }}>{shown.network_captions.facebook}</span></div>}
                                    {shown.hashtags.length > 0 && <div className="text-primary mt-1">{shown.hashtags.join(" ")}</div>}
                                    {shown.media.items.length > 0 && <div className="d-flex flex-wrap gap-1 mt-2">{shown.media.items.map((a) => <img key={a.id} src={mediaSrc(a.thumb_url)} alt="" className="rounded border" style={{ width: 48, height: 48, objectFit: "cover" }} />)}</div>}
                                </div>
                            )}
                        </>
                    )}
                    {data.reviews.length > 0 && (
                        <>
                            <h6 className="mb-2">Decisões do cliente</h6>
                            <ul className="list-unstyled fs-13 mb-3">
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
                                <span className="text-muted">{fmtDateTimePt(e.created_at)}</span> · <strong>{e.who ?? "Sistema"}</strong>{" · "}
                                {e.type === "created" ? `criou em ${STAGE_META[e.to_stage as Stage]?.label ?? ""}`
                                    : e.type === "stage" && e.from_stage && e.to_stage ? `${STAGE_META[e.from_stage].label} → ${STAGE_META[e.to_stage].label}` : e.message}
                                {e.type === "stage" && e.message && <span className="text-muted"> ({e.message})</span>}
                            </li>
                        ))}
                    </ul>
                </Col>
            </Row>
        </div>
    );
}
