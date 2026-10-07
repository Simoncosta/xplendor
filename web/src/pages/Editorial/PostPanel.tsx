import { useCallback, useEffect, useRef, useState } from "react";
import { Badge, Button, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Offcanvas, OffcanvasBody, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { createEditorialPost, deleteEditorialPost, getPostWorkflow, updateEditorialPost } from "helpers/laravel_helper";
import { Network, POST_CHANNEL_META, channelIcons } from "common/models/editorialPost.model";
import { FormatTable, PostWorkflow, STAGE_META, Stage } from "common/models/editorialWorkflow.model";
import PlanningSection, { PlanValues, emptyPlan, planPayload } from "./panel/PlanningSection";
import ContentSection, { ContentHandle } from "./panel/ContentSection";
import { dirtyKeys, mergeDraft } from "./panel/draftMerge";
import ApprovalSection from "./panel/ApprovalSection";
import PublishSection from "./panel/PublishSection";
import CreativeModal from "./CreativeModal";
import { XOption } from "./XSelect";
import "./panel/post-panel.css";
import "./editorial.css";

/**
 * O MESMO painel da publicação em todas as vistas (calendário, Kanban, Feed, Resultados,
 * "Para publicar hoje" e avisos): Planeamento, Conteúdo, Aprovação, e Publicação e Análise
 * (por rede). Ao criar, só o Planeamento essencial; depois de criada, o resto.
 *
 * O que a pessoa escreve nunca se perde: o separador Conteúdo fica montado ao trocar de
 * separador; os dados novos do servidor só atualizam os campos que não foram alterados (no
 * Conteúdo e no Planeamento); fechar com alterações por gravar pede confirmação.
 */

export type PanelTab = "planning" | "content" | "approval" | "publish";
export type PanelTarget = { mode: "create"; date: string } | { mode: "edit"; postId: number; tab?: PanelTab };

const TAB_LABELS: Record<PanelTab, string> = { planning: "Planeamento", content: "Conteúdo", approval: "Aprovação", publish: "Publicação e Análise" };

/** O separador que faz sentido em cada etapa. */
const tabFor = (stage: Stage): PanelTab =>
    stage === "idea" || stage === "planning" ? "planning"
        : stage === "production" || stage === "internal_review" ? "content"
            : stage === "client_review" ? "approval" : "publish";

const dmy = (iso: string) => iso.split("-").reverse().join("/");
const errorList = (e: any): string[] => (e?.errors ? (Object.values(e.errors).flat() as string[]) : [e?.message ?? "Não foi possível guardar."]);

function planFrom(d: PostWorkflow): PlanValues {
    const p = d.post;
    const nets = p.networks.map((n) => n.network);
    return {
        title: p.title, publish_date: p.publish_date, publish_time: p.publish_time ?? "",
        site: p.channel === "site", networks: Object.fromEntries(p.networks.map((n) => [n.network, n.media_format ?? ""])) as Partial<Record<Network, string>>,
        order: nets.length ? nets : ["instagram"], format: p.format, pillar: p.pillar ?? "",
        link: p.anchor_id ? `a:${p.anchor_id}` : p.own_anchor_id ? `o:${p.own_anchor_id}` : "", keyword: p.keyword ?? "", blog_id: p.blog_id ? String(p.blog_id) : "",
    };
}

type Props = {
    target: PanelTarget | null;
    onClose: () => void;
    companyId: number;
    anchors: XOption[];
    pillars: string[];
    range?: { from: string; to: string } | null;
    canProduce: boolean;
    formats: FormatTable | null;
    monthOpen: (date: string) => boolean;
    /** O calendário atualizado (criar, editar, apagar). */
    onCalendar: (cal: any) => void;
    /** Algo mudou (etapa, conteúdo, publicação): recarregar as vistas. */
    onChanged: () => void;
};

export default function PostPanel({ target, onClose, companyId, anchors, pillars, range, canProduce, formats, monthOpen, onCalendar, onChanged }: Props) {
    const [postId, setPostId] = useState<number | null>(null);
    const [data, setData] = useState<PostWorkflow | null>(null);
    const [plan, setPlan] = useState<PlanValues>(emptyPlan(""));
    const [tab, setTab] = useState<PanelTab>("planning");
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [contentDirty, setContentDirty] = useState(false);
    const [creative, setCreative] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const contentRef = useRef<ContentHandle>(null);
    // Planeamento: último valor do servidor e o que acabou de ser gravado (para a junção).
    const planBase = useRef<PlanValues>(emptyPlan(""));
    const planSent = useRef<PlanValues | null>(null);

    // As funções do pai mudam a cada atualização da página: o painel só reage à publicação aberta.
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    const apply = useCallback((d: PostWorkflow, reset = false) => {
        setData(d);
        const server = planFrom(d);
        // Ao abrir, o que está no servidor; depois, só os campos que não foram alterados.
        setPlan((current) => (reset ? server : mergeDraft(current, planBase.current, server, planSent.current)));
        planBase.current = server;
        planSent.current = null;
    }, []);

    const load = useCallback(async (id: number, tabWanted?: PanelTab, reset = false) => {
        try {
            const r: any = await getPostWorkflow(companyId, id);
            apply(r.data, reset);
            setTab(tabWanted ?? (r.data.post.channel === "site" ? "planning" : tabFor(r.data.post.stage)));
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível abrir a publicação.");
            onCloseRef.current();
        }
    }, [companyId, apply]);

    useEffect(() => {
        setErrors([]);
        setConfirmDelete(false);
        setContentDirty(false);
        if (!target) return;
        if (target.mode === "create") {
            setPostId(null);
            setData(null);
            setPlan(emptyPlan(target.date));
            setTab("planning");
        } else {
            setPostId(target.postId);
            setData(null);
            void load(target.postId, target.tab, true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    const changed = (d: PostWorkflow) => { apply(d); onChanged(); };

    const savePlan = async (): Promise<boolean> => {
        setBusy(true);
        setErrors([]);
        try {
            if (!postId) {
                const r: any = await createEditorialPost(companyId, planPayload(plan));
                onCalendar(r.data);
                toast.success("Publicação criada.");
                const id = r.data?.created_post_id;
                if (id) { setPostId(id); await load(id, plan.site ? "planning" : "content", true); }
                else onClose();
                return true;
            } else {
                const r: any = await updateEditorialPost(companyId, postId, planPayload(plan));
                onCalendar(r.data);
                toast.success("Planeamento guardado.");
                planSent.current = plan;
                await load(postId, "planning");
                onChanged();
                return true;
            }
        } catch (e: any) {
            setErrors(errorList(e));
            return false;
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        if (!postId) return;
        if (!confirmDelete) { setConfirmDelete(true); toast.info("Carregue outra vez em Apagar para confirmar."); return; }
        setBusy(true);
        try {
            const r: any = await deleteEditorialPost(companyId, postId);
            onCalendar(r.data);
            toast.success("Publicação apagada.");
            onClose();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível apagar.");
        } finally {
            setBusy(false);
        }
    };

    // Fechar com alterações por gravar: pedir confirmação (e avisar ao sair da página).
    const planDirty = !!postId && !!data && dirtyKeys(plan, planBase.current).length > 0;
    const unsaved = contentDirty || planDirty || (!postId && !!target && plan.title.trim() !== "");
    const requestClose = () => { if (unsaved) setConfirmClose(true); else onClose(); };
    useEffect(() => {
        if (!unsaved) return;
        const warn = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ""; };
        window.addEventListener("beforeunload", warn);
        return () => window.removeEventListener("beforeunload", warn);
    }, [unsaved]);
    const saveAndClose = async () => {
        let ok = true;
        if (contentDirty && contentRef.current) ok = await contentRef.current.flush();
        if (ok && (planDirty || !postId)) ok = await savePlan();
        setConfirmClose(false);
        if (ok) onClose();
    };

    const p = data?.post;
    const creating = !postId;
    const site = creating ? plan.site : p?.channel === "site";
    const tabs: PanelTab[] = site ? ["planning"] : ["planning", "content", "approval", "publish"];
    const editable = canProduce && monthOpen(plan.publish_date || p?.publish_date || "");
    const sm = p ? STAGE_META[p.stage] : null;

    return (
        <Offcanvas isOpen={!!target} toggle={requestClose} direction="end" className="xp-post-panel" scrollable>
            <div className="offcanvas-header border-bottom align-items-start gap-2">
                <div className="flex-grow-1 min-w-0">
                    {creating ? <h5 className="mb-0">Nova publicação</h5> : p ? (
                        <>
                            <div className="d-flex align-items-center gap-2">
                                {channelIcons(p as any).map((c) => <i key={c.icon} className={c.icon} title={c.label} aria-label={c.label} />)}
                                <h5 className="mb-0 text-truncate">{p.title}</h5>
                            </div>
                            <div className="d-flex flex-wrap gap-1 mt-1">
                                {sm && <Badge color={`${sm.color}-subtle`} className={`text-${sm.color === "dark" ? "body" : sm.color}`}><i className={`${sm.icon} me-1`} />{sm.label}</Badge>}
                                {p.overdue && <Badge color="danger">Atrasada</Badge>}
                                <Badge color="light" className="text-body">{dmy(p.publish_date)}{p.publish_time ? ` · ${p.publish_time}` : ""}</Badge>
                                {p.networks.length > 1 && ["scheduled", "published", "analysis"].includes(p.stage) && (
                                    <Badge color="light" className="text-body">{data!.publishing.published_count} de {data!.publishing.active_count} publicadas</Badge>
                                )}
                            </div>
                        </>
                    ) : <Spinner size="sm" />}
                </div>
                {p?.stage === "scheduled" && data?.publishing.can_mark && tab !== "publish" && (
                    <Button color="success" size="sm" className="flex-shrink-0" onClick={() => setTab("publish")}><i className="ri-checkbox-circle-line me-1" />Marcar como publicada</Button>
                )}
                <button type="button" className="btn-close flex-shrink-0" aria-label="Fechar" onClick={requestClose} />
            </div>
            {!creating && tabs.length > 1 && (
                <div className="xp-panel-tabs border-bottom px-2 overflow-auto">
                    <Nav tabs className="nav-tabs-custom border-0 flex-nowrap">
                        {tabs.map((t) => (
                            <NavItem key={t}>
                                <NavLink href="#" active={tab === t} onClick={(e) => { e.preventDefault(); setTab(t); }}>
                                    {TAB_LABELS[t]}
                                    {t === "approval" && p?.stage === "client_review" && <Badge color="warning" className="ms-1">À espera</Badge>}
                                    {t === "publish" && p?.overdue && <Badge color="danger" className="ms-1">!</Badge>}
                                </NavLink>
                            </NavItem>
                        ))}
                    </Nav>
                </div>
            )}
            <OffcanvasBody>
                {!creating && !data ? <div className="text-center py-5"><Spinner /></div> : (
                    <>
                        {tab === "planning" && (
                            <PlanningSection companyId={companyId} values={plan} onChange={setPlan} creating={creating} disabled={!editable}
                                lockedNetworks={!!p && ["published", "analysis"].includes(p.stage)} formats={formats} anchors={anchors} pillars={pillars} range={range}
                                busy={busy} onSubmit={() => void savePlan()} onDelete={!creating && editable ? () => void remove() : undefined} errors={errors}
                                writeArticleUrl={p && p.channel === "site" ? `/blogs/create?${new URLSearchParams({ editorial_post_id: String(p.id), title: p.title, ...(p.keyword ? { keyword: p.keyword } : {}) }).toString()}` : null} />
                        )}
                        {tab === "planning" && !editable && !creating && <p className="text-muted fs-12 mt-2"><i className="ri-lock-2-line me-1" />{canProduce ? "O mês desta publicação está fechado: só consulta." : "A produção é feita pela equipa XPLENDOR."}</p>}
                        {/* O Conteúdo fica montado ao trocar de separador: o texto por gravar não se perde. */}
                        {data && p && p.channel !== "site" && (
                            <div className={tab === "content" ? undefined : "d-none"}>
                                <ContentSection key={p.id} ref={contentRef} companyId={companyId} data={data} onChanged={changed} onCreative={() => setCreative(true)} onDirty={setContentDirty} />
                            </div>
                        )}
                        {data && tab === "approval" && (
                            <ApprovalSection companyId={companyId} data={data} onChanged={changed}
                                beforeMove={async () => {
                                    // Antes de mudar de etapa, grava o texto por gravar (se não der, não muda).
                                    if (!contentDirty || !contentRef.current) return true;
                                    const ok = await contentRef.current.flush();
                                    if (!ok) toast.warning("Grave primeiro o conteúdo (separador Conteúdo).");
                                    return ok;
                                }} />
                        )}
                        {data && tab === "publish" && <PublishSection companyId={companyId} data={data} onChanged={changed} />}
                        {data && site && p?.blog && (
                            <p className="fs-13 mt-3 mb-0"><i className={`${POST_CHANNEL_META.site.icon} me-1`} />Artigo ligado: <a href={`/app/blogs/${p.blog.id}`}>{p.blog.title}</a>. O estado vem do artigo.</p>
                        )}
                    </>
                )}
            </OffcanvasBody>
            <Modal isOpen={confirmClose} toggle={() => setConfirmClose(false)} centered>
                <ModalHeader toggle={() => setConfirmClose(false)}>Alterações por gravar</ModalHeader>
                <ModalBody className="fs-14">
                    Há texto nesta publicação que ainda não foi gravado. Quer gravar antes de fechar?
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setConfirmClose(false)}>Continuar a editar</Button>
                    <Button color="soft-danger" onClick={() => { setConfirmClose(false); onClose(); }}>Fechar sem gravar</Button>
                    <Button color="primary" disabled={busy} onClick={() => void saveAndClose()}>{busy ? <Spinner size="sm" /> : "Gravar e fechar"}</Button>
                </ModalFooter>
            </Modal>
            {postId && p && p.channel !== "site" && (
                <CreativeModal isOpen={creative} toggle={() => setCreative(false)} companyId={companyId} postId={postId} postTitle={p.title}
                    onSaved={() => { void load(postId, "content"); onChanged(); }} />
            )}
        </Offcanvas>
    );
}
