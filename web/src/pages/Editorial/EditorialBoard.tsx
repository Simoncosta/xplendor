import { useCallback, useEffect, useMemo, useState } from "react";
import { DragDropContext, Draggable, Droppable, type DragStart, type DropResult } from "@hello-pangea/dnd";
import { Badge, Button, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { approveAllPosts, getEditorialBoard, movePostStage } from "helpers/laravel_helper";
import { POST_CHANNEL_META, channelIcons, mediaFormatLabel } from "common/models/editorialPost.model";
import XSelect from "./XSelect";
import { BoardData, BoardPost, STAGE_META, STAGE_ORDER, Stage, fmtInt, fmtRate } from "common/models/editorialWorkflow.model";

/**
 * Kanban da Linha Editorial (F3a): uma coluna por etapa, para o mês escolhido. Só se pode
 * largar um cartão numa coluna permitida (o servidor diz quais, por papel e definições da
 * empresa, e volta a validar). As publicações do Site mostram a etapa do artigo do blog e
 * não se arrastam. "Aprovar tudo" para os aprovadores da empresa.
 */

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

const dm = (iso: string) => { const [, m, d] = iso.split("-"); return `${d}/${m}`; };

type Props = { companyId: number; monthKey: string; reloadKey: number; onOpen: (postId: number) => void; onChanged: () => void };

export default function EditorialBoard({ companyId, monthKey, reloadKey, onOpen, onChanged }: Props) {
    const [data, setData] = useState<BoardData | null>(null);
    const [loading, setLoading] = useState(true);
    const [channel, setChannel] = useState("");
    const [dragging, setDragging] = useState<BoardPost | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        if (!companyId || !monthKey) return;
        setLoading(true);
        try {
            const r: any = await getEditorialBoard(companyId, monthKey);
            setData(r.data);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível carregar o quadro."));
        } finally {
            setLoading(false);
        }
    }, [companyId, monthKey]);

    useEffect(() => { void load(); }, [load, reloadKey]);

    // Filtro por rede (uma publicação nas duas redes aparece nas duas) ou Site.
    const posts = useMemo(() => (data?.posts ?? []).filter((p) => !channel || (channel === "site" ? p.channel === "site" : p.networks.some((n) => n.network === channel))), [data, channel]);
    const columns = useMemo(() => {
        const by: Record<Stage, BoardPost[]> = Object.fromEntries(STAGE_ORDER.map((s) => [s, []])) as any;
        posts.forEach((p) => (by[p.stage] ?? by.planning).push(p));
        return by;
    }, [posts]);
    const waiting = posts.filter((p) => p.can_approve);

    const onDragStart = (start: DragStart) => setDragging(posts.find((p) => String(p.id) === start.draggableId) ?? null);

    const onDragEnd = async (result: DropResult) => {
        setDragging(null);
        const to = result.destination?.droppableId as Stage | undefined;
        const post = posts.find((p) => String(p.id) === result.draggableId);
        if (!to || !post || to === post.stage) return;
        if (!post.moves.includes(to)) {
            toast.warning(to === "scheduled" && post.stage === "client_review"
                ? "Para aprovar, abra a publicação: só o cliente aprova."
                : `Não é possível passar de ${STAGE_META[post.stage].label} para ${STAGE_META[to].label}.`);
            return;
        }
        // Atualização otimista; repõe se o servidor recusar.
        setData((d) => d && { ...d, posts: d.posts.map((p) => (p.id === post.id ? { ...p, stage: to } : p)) });
        try {
            await movePostStage(companyId, post.id, to);
            toast.success(`Passou para ${STAGE_META[to].label}.`);
            onChanged();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível mudar a etapa."));
        }
        void load();
    };

    const approveAll = async () => {
        if (!window.confirm(`Aprovar ${waiting.length === 1 ? "a publicação" : `as ${waiting.length} publicações`} à espera?`)) return;
        setBusy(true);
        try {
            const r: any = await approveAllPosts(companyId, waiting.map((p) => p.id));
            toast.success(r?.message ?? "Publicações aprovadas.");
            onChanged();
            void load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível aprovar."));
        } finally {
            setBusy(false);
        }
    };

    if (loading && !data) return <div className="text-center py-5"><Spinner /></div>;

    return (
        <div>
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <XSelect small width={190} ariaLabel="Canal" value={channel} onChange={setChannel}
                    options={[{ value: "", label: "Todos os canais" }, { value: "instagram", label: "Instagram" }, { value: "facebook", label: "Facebook" }, { value: "site", label: "Site (blog)" }]} />
                <div className="d-flex align-items-center gap-2 fs-12 text-muted">
                    {data?.settings.internal_review_required && <span><i className="ri-eye-line me-1" />Revisão interna obrigatória</span>}
                    {data && !data.settings.content_approval_required && <span><i className="ri-information-line me-1" />Sem aprovação do cliente</span>}
                    {data?.is_approver && waiting.length > 0 && (
                        <Button color="success" size="sm" disabled={busy} onClick={approveAll}>
                            <i className="ri-check-double-line me-1" />Aprovar tudo ({waiting.length})
                        </Button>
                    )}
                </div>
            </div>

            <DragDropContext onDragStart={onDragStart} onDragEnd={onDragEnd}>
                <div className="tasks-board tasks-board--editorial mb-2 d-flex">
                    {STAGE_ORDER.map((stage) => {
                        const meta = STAGE_META[stage];
                        const items = columns[stage];
                        const allowed = dragging ? dragging.moves.includes(stage) || dragging.stage === stage : true;
                        return (
                            <div className="tasks-list" key={stage} style={{ opacity: allowed ? 1 : 0.45 }}>
                                <div className="d-flex mb-2">
                                    <div className="flex-grow-1">
                                        <h6 className="fs-13 text-uppercase fw-semibold mb-0">
                                            <i className={`${meta.icon} me-1`} />{meta.label}
                                            <small className="badge bg-light text-body align-bottom ms-1">{items.length}</small>
                                        </h6>
                                        <div className="text-muted fs-11">{meta.hint}</div>
                                    </div>
                                </div>
                                <Droppable droppableId={stage}>
                                    {(drop) => (
                                        <div ref={drop.innerRef} {...drop.droppableProps} className="tasks" style={{ minHeight: 80 }}>
                                            {items.map((p, index) => (
                                                <Draggable key={p.id} draggableId={String(p.id)} index={index} isDragDisabled={p.channel === "site" || p.moves.length === 0}>
                                                    {(drag) => (
                                                        <div ref={drag.innerRef} {...drag.draggableProps} {...drag.dragHandleProps} className="task-list">
                                                            <div className="card task-box mb-0" role="button" onClick={() => onOpen(p.id)}>
                                                                <div className="card-body p-2">
                                                                    <div className="d-flex align-items-start gap-2">
                                                                        <span className="d-flex gap-1 fs-16 mt-1 flex-shrink-0">{channelIcons(p).map((c) => <i key={c.icon} className={c.icon} title={c.label} />)}</span>
                                                                        <div className="fw-medium fs-13 text-break flex-grow-1 lh-sm">{p.title}</div>
                                                                    </div>
                                                                    <div className="d-flex flex-wrap align-items-center gap-1 mt-2 fs-11">
                                                                        <Badge color="light" className="text-body fw-normal">{dm(p.publish_date)}{p.publish_time ? ` ${p.publish_time}` : ""}</Badge>
                                                                        {p.overdue && <Badge color="danger" className="fw-normal" title="A data e a hora passaram e ainda não foi marcada como publicada">Atrasada</Badge>}
                                                                        {p.version && <Badge color="light" className="text-body fw-normal">v{p.version.number}</Badge>}
                                                                        {p.networks.filter((n) => n.media_format).map((n) => <Badge key={n.network} color="light" className="text-body fw-normal" title={POST_CHANNEL_META[n.network].label}>{mediaFormatLabel(n.media_format)}</Badge>)}
                                                                        {p.networks.length > 1 && ["scheduled", "published"].includes(p.stage) && (
                                                                            <Badge color="light" className="text-body fw-normal">{p.networks.filter((n) => n.state === "published").length} de {p.networks.filter((n) => n.state !== "skipped").length} publicadas</Badge>
                                                                        )}
                                                                        {p.changes_requested && p.stage === "production" && <Badge color="warning" className="fw-normal">Alterações pedidas</Badge>}
                                                                        {p.can_approve && <Badge color="warning" className="fw-normal">Aprovar</Badge>}
                                                                        {p.channel === "site" && <Badge color="info-subtle" className="text-info fw-normal">Blog</Badge>}
                                                                        {p.comments_count > 0 && <span className="text-muted ms-auto"><i className="ri-chat-3-line" /> {p.comments_count}</span>}
                                                                    </div>
                                                                    {/* F3d: na Análise, o alcance e a taxa de envolvimento. */}
                                                                    {p.stage === "analysis" && p.channel !== "site" && (
                                                                        <div className="vstack gap-1 mt-2 pt-2 border-top fs-12">
                                                                            {(p.results ?? []).length === 0 && <span className="text-muted">Sem alcance registado</span>}
                                                                            {(p.results ?? []).map((r) => (
                                                                                <div key={r.network} className="d-flex gap-3">
                                                                                    <i className={`${POST_CHANNEL_META[r.network].icon} text-muted`} title={POST_CHANNEL_META[r.network].label} />
                                                                                    <span title="Alcance"><i className="ri-eye-line me-1 text-muted" />{r.reach != null ? fmtInt(r.reach) : <span className="text-muted">Sem alcance</span>}</span>
                                                                                    {r.engagement_rate != null && <span title="Taxa de envolvimento (interações ÷ alcance)"><i className="ri-heart-pulse-line me-1 text-muted" />{fmtRate(r.engagement_rate)}</span>}
                                                                                </div>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}
                                                </Draggable>
                                            ))}
                                            {drop.placeholder}
                                            {items.length === 0 && <p className="text-muted fs-12 text-center mb-0 py-2">Sem publicações</p>}
                                        </div>
                                    )}
                                </Droppable>
                            </div>
                        );
                    })}
                </div>
            </DragDropContext>
        </div>
    );
}
