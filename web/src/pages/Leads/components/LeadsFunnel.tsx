import React, { useEffect, useMemo, useState } from "react";
import { DragDropContext, Droppable, Draggable, type DropResult } from "@hello-pangea/dnd";
import SimpleBar from "simplebar-react";
import { CardBody, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import CarThumbnail from "Components/Common/CarThumbnail";
import { ILead, LeadStatus, LEAD_STAGES } from "common/models/lead.model";
import { getCompanyLeadsAll, updateLead } from "helpers/laravel_helper";
import LossReasonModal from "./LossReasonModal";
import LeadDetailModal from "./LeadDetailModal";

/**
 * XPLENDOR — Funil de leads em Kanban (CRM). REUTILIZA o padrão/visual dos Kanbans
 * (tarefas/tickets): .tasks-board / .tasks-list / .task-box + SimpleBar + estilo
 * tipo Trello (CSS partilhado _kanban.scss). Colunas = fases do funil; cards = leads.
 *
 * Arrastar muda o estado (persiste). Ao largar em "Perdida" → pede o MOTIVO
 * (obrigatório). Clicar num card abre o detalhe. Componente puro (sem page-content)
 * para embeber na página de Leads.
 */

type Board = Record<LeadStatus, ILead[]>;

const emptyBoard = (): Board => LEAD_STAGES.reduce((acc, s) => { acc[s.key] = []; return acc; }, {} as Board);

const buildBoard = (leads: ILead[]): Board => {
    const board = emptyBoard();
    const stageKeys = LEAD_STAGES.map((s) => s.key);
    leads.forEach((l) => {
        if (stageKeys.includes(l.status)) board[l.status].push(l); // 'spam' fica de fora do funil
    });
    return board;
};

const LeadsFunnel = () => {
    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [board, setBoard] = useState<Board>(emptyBoard());
    const [loading, setLoading] = useState(true);

    // Fluxo "Perdida": guarda o movimento pendente até o motivo ser escolhido.
    const [pendingLost, setPendingLost] = useState<{ lead: ILead; snapshot: Board } | null>(null);
    const [savingLost, setSavingLost] = useState(false);

    // Detalhe
    const [detail, setDetail] = useState<ILead | null>(null);
    const [savingNotes, setSavingNotes] = useState(false);

    const load = () => {
        if (!companyId) { setLoading(false); return; }
        setLoading(true);
        getCompanyLeadsAll(companyId)
            .then((r: any) => setBoard(buildBoard((r?.data ?? []) as ILead[])))
            .catch(() => setBoard(emptyBoard()))
            .finally(() => setLoading(false));
    };

    useEffect(load, [companyId]);

    const persist = async (leadId: number, status: LeadStatus, snapshot: Board, lostReason?: string) => {
        try {
            await updateLead(companyId, leadId, lostReason ? { status, lost_reason: lostReason } : { status });
        } catch {
            toast.error("Não foi possível mover a lead.");
            setBoard(snapshot);
        }
    };

    const handleDragEnd = (result: DropResult) => {
        const { source, destination, draggableId } = result;
        if (!destination) return;
        if (source.droppableId === destination.droppableId && source.index === destination.index) return;

        const from = source.droppableId as LeadStatus;
        const to = destination.droppableId as LeadStatus;
        const leadId = Number(draggableId);

        const snapshot = board;
        const next: Board = { ...board };
        LEAD_STAGES.forEach((s) => { next[s.key] = [...board[s.key]]; });
        const [moved] = next[from].splice(source.index, 1);
        if (!moved) return;
        const updatedLead = { ...moved, status: to };
        next[to].splice(destination.index, 0, updatedLead);
        setBoard(next);

        if (from === to) return;

        // Cair em "Perdida" → exige motivo antes de persistir.
        if (to === "lost") {
            setPendingLost({ lead: updatedLead, snapshot });
            return;
        }

        persist(leadId, to, snapshot);
    };

    const confirmLost = async (reason: string) => {
        if (!pendingLost) return;
        setSavingLost(true);
        await persist(pendingLost.lead.id, "lost", pendingLost.snapshot, reason);
        setSavingLost(false);
        setPendingLost(null);
    };

    const cancelLost = () => {
        if (pendingLost) setBoard(pendingLost.snapshot); // reverte o movimento
        setPendingLost(null);
    };

    const saveNotes = async (notes: string) => {
        if (!detail) return;
        setSavingNotes(true);
        try {
            await updateLead(companyId, detail.id, { notes });
            setBoard((prev) => {
                const nb: Board = { ...prev };
                LEAD_STAGES.forEach((s) => { nb[s.key] = prev[s.key].map((l) => (l.id === detail.id ? { ...l, notes } : l)); });
                return nb;
            });
            toast.success("Notas guardadas.");
            setDetail(null);
        } catch {
            toast.error("Não foi possível guardar as notas.");
        } finally {
            setSavingNotes(false);
        }
    };

    if (loading) {
        return <div className="d-flex align-items-center gap-2 text-muted py-5"><Spinner size="sm" /> A carregar…</div>;
    }

    return (
        <>
            <DragDropContext onDragEnd={handleDragEnd}>
                <div className="tasks-board mb-3 d-flex" id="leadsfunnel">
                    {LEAD_STAGES.map((stage) => {
                        const items = board[stage.key];
                        return (
                            <div className="tasks-list" key={stage.key}>
                                <div className="d-flex mb-3 align-items-center">
                                    <div className="flex-grow-1">
                                        <h6 className="fs-14 text-uppercase fw-semibold mb-0">
                                            {stage.label}
                                            <small className={`badge bg-${stage.color} align-bottom ms-1 totaltask-badge`}>{items.length}</small>
                                        </h6>
                                    </div>
                                </div>

                                <SimpleBar className="tasks-wrapper px-3 mx-n3">
                                    <Droppable droppableId={stage.key}>
                                        {(dropProvided) => (
                                            <div ref={dropProvided.innerRef} {...dropProvided.droppableProps} className={items.length ? "tasks" : "tasks noTask"}>
                                                {items.map((lead, index) => {
                                                    const car = lead.car;
                                                    const img = car?.images?.find((i) => i.is_primary)?.image ?? null;
                                                    const carName = car ? `${car.brand?.name ?? ""} ${car.model?.name ?? ""}`.trim() : "";
                                                    return (
                                                        <Draggable draggableId={String(lead.id)} index={index} key={lead.id}>
                                                            {(dragProvided) => (
                                                                <div
                                                                    ref={dragProvided.innerRef}
                                                                    {...dragProvided.draggableProps}
                                                                    {...dragProvided.dragHandleProps}
                                                                    className="pb-1 task-list"
                                                                >
                                                                    <div className="card task-box mb-0" style={{ cursor: "pointer" }} onClick={() => setDetail(lead)}>
                                                                        <CardBody className="p-3">
                                                                            <h6 className="fs-14 mb-1 text-truncate">{lead.name}</h6>
                                                                            <p className="text-muted mb-2 fs-12">{lead.phone || lead.email}</p>

                                                                            {car && (
                                                                                <div className="d-flex align-items-center gap-2 mb-2">
                                                                                    <CarThumbnail src={img} variant="compact" width={40} height={28} />
                                                                                    <span className="fs-12 text-truncate">{carName}</span>
                                                                                </div>
                                                                            )}

                                                                            <div className="d-flex align-items-center justify-content-between">
                                                                                <span className="badge bg-light text-body fs-11">
                                                                                    <i className="ri-global-line me-1" />{lead.channel || lead.utm_source || "direto"}
                                                                                </span>
                                                                                <small className="text-muted fs-11">{new Date(lead.created_at).toLocaleDateString("pt-PT")}</small>
                                                                            </div>
                                                                        </CardBody>
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </Draggable>
                                                    );
                                                })}
                                                {dropProvided.placeholder}
                                                {items.length === 0 && <p className="text-muted fs-12 text-center mb-0 py-3">Sem leads</p>}
                                            </div>
                                        )}
                                    </Droppable>
                                </SimpleBar>
                            </div>
                        );
                    })}
                </div>
            </DragDropContext>

            <LossReasonModal
                isOpen={!!pendingLost}
                leadName={pendingLost?.lead.name}
                saving={savingLost}
                onConfirm={confirmLost}
                onCancel={cancelLost}
            />

            <LeadDetailModal
                lead={detail}
                saving={savingNotes}
                onSaveNotes={saveNotes}
                onClose={() => setDetail(null)}
            />
        </>
    );
};

export default LeadsFunnel;
