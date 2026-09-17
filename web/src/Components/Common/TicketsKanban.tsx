import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { DragDropContext, Droppable, Draggable, type DropResult } from "@hello-pangea/dnd";
import SimpleBar from "simplebar-react";
import {
    CardBody, Badge,
    UncontrolledDropdown, DropdownToggle, DropdownMenu, DropdownItem,
} from "reactstrap";
import {
    ISupportTicket, SupportTicketStatus, SupportTicketType,
    TICKET_TYPE_META, TICKET_STATUS_META, QUOTE_STATUS_META, formatEuro,
} from "common/models/supportTicket.model";

/**
 * Kanban de tickets — VISUAL do template Velzon (.tasks-board / .tasks-list /
 * .tasks-wrapper / .task-box, SimpleBar). Partilhado por dois usos:
 *  · ADMIN (transversal): editável — arrastar muda estado, dropdown reclassifica
 *    tipo, mostra a empresa de cada ticket.
 *  · CLIENTE (stand): readOnly — NÃO arrasta (o estado é do admin), sem dropdown
 *    de tipo, sem coluna de empresa (é uma empresa só). Clicar abre o detalhe.
 *
 * Colunas = os 4 estados genéricos. O fluxo de orçamento fica no detalhe.
 */

const COLUMNS: SupportTicketStatus[] = ["open", "in_review", "resolved", "closed"];
const TYPE_KEYS: SupportTicketType[] = ["idea", "improvement", "bug", "suggestion", "site_change"];

type Board = Record<SupportTicketStatus, ISupportTicket[]>;

const buildBoard = (tickets: ISupportTicket[]): Board => {
    const board: Board = { open: [], in_review: [], resolved: [], closed: [] };
    tickets.forEach((t) => {
        const col = (COLUMNS as string[]).includes(t.status) ? t.status : "open";
        board[col].push(t);
    });
    return board;
};

interface Props {
    tickets: ISupportTicket[];
    /** Constrói o URL do detalhe (admin vs stand). */
    detailHref: (id: number) => string;
    /** Só leitura: sem drag, sem dropdown de tipo (vista do cliente). */
    readOnly?: boolean;
    /** Mostrar a empresa no cartão (admin transversal). */
    showCompany?: boolean;
    onStatusChange?: (id: number, status: SupportTicketStatus) => Promise<void>;
    onTypeChange?: (id: number, type: SupportTicketType) => Promise<void>;
}

const TicketsKanban: React.FC<Props> = ({
    tickets, detailHref, readOnly = false, showCompany = true, onStatusChange, onTypeChange,
}) => {
    const navigate = useNavigate();
    const [board, setBoard] = useState<Board>(() => buildBoard(tickets));

    useEffect(() => { setBoard(buildBoard(tickets)); }, [tickets]);

    const handleDragEnd = async (result: DropResult) => {
        if (readOnly || !onStatusChange) return; // vista de leitura: drag não persiste
        const { source, destination, draggableId } = result;
        if (!destination) return;
        if (source.droppableId === destination.droppableId && source.index === destination.index) return;

        const from = source.droppableId as SupportTicketStatus;
        const to = destination.droppableId as SupportTicketStatus;
        const ticketId = Number(draggableId);

        const snapshot = board;
        const next: Board = { open: [...board.open], in_review: [...board.in_review], resolved: [...board.resolved], closed: [...board.closed] };
        const [moved] = next[from].splice(source.index, 1);
        if (!moved) return;
        next[to].splice(destination.index, 0, { ...moved, status: to });
        setBoard(next);

        if (from === to) return;

        try {
            await onStatusChange(ticketId, to);
        } catch {
            setBoard(snapshot);
        }
    };

    const stop = (e: React.MouseEvent) => e.stopPropagation();

    return (
        <DragDropContext onDragEnd={handleDragEnd}>
            <div className="tasks-board mb-3 d-flex" id="kanbanboard">
                {COLUMNS.map((col) => {
                    const meta = TICKET_STATUS_META[col];
                    const items = board[col];
                    return (
                        <div className="tasks-list" key={col}>
                            <div className="d-flex mb-3">
                                <div className="flex-grow-1">
                                    <h6 className="fs-14 text-uppercase fw-semibold mb-0">
                                        {meta.label}
                                        <small className={`badge bg-${meta.color} align-bottom ms-1 totaltask-badge`}>{items.length}</small>
                                    </h6>
                                </div>
                            </div>

                            <SimpleBar className="tasks-wrapper px-3 mx-n3">
                                <Droppable droppableId={col} isDropDisabled={readOnly}>
                                    {(dropProvided) => (
                                        <div ref={dropProvided.innerRef} {...dropProvided.droppableProps} className={items.length ? "tasks" : "tasks noTask"}>
                                            {items.map((t, index) => {
                                                const tm = TICKET_TYPE_META[t.type];
                                                const isPaid = t.type === "site_change";
                                                const qm = isPaid && t.quote_status ? QUOTE_STATUS_META[t.quote_status] : null;
                                                return (
                                                    <Draggable draggableId={String(t.id)} index={index} key={t.id} isDragDisabled={readOnly}>
                                                        {(dragProvided) => (
                                                            <div
                                                                ref={dragProvided.innerRef}
                                                                {...dragProvided.draggableProps}
                                                                {...dragProvided.dragHandleProps}
                                                                className="pb-1 task-list"
                                                            >
                                                                <div className="card task-box mb-0" style={{ cursor: "pointer" }} onClick={() => navigate(detailHref(t.id))}>
                                                                    <CardBody className="p-3">
                                                                        <div className="d-flex align-items-start gap-2 mb-2">
                                                                            <span className="avatar-xs flex-shrink-0">
                                                                                <span className={"avatar-title rounded fs-16 " + (isPaid ? "bg-warning-subtle text-warning" : "bg-light text-primary")}><i className={tm.icon} /></span>
                                                                            </span>
                                                                            <h6 className="fs-14 mb-0 flex-grow-1 text-truncate">{t.title}</h6>

                                                                            {!readOnly && onTypeChange && (
                                                                                <UncontrolledDropdown className="flex-shrink-0" onClick={stop}>
                                                                                    <DropdownToggle tag="a" className="text-reset" role="button">
                                                                                        <i className="ri-more-fill" />
                                                                                    </DropdownToggle>
                                                                                    <DropdownMenu className="dropdown-menu-end">
                                                                                        <DropdownItem header>Reclassificar tipo</DropdownItem>
                                                                                        {TYPE_KEYS.map((k) => (
                                                                                            <DropdownItem key={k} active={t.type === k}
                                                                                                onClick={(e) => { e.stopPropagation(); if (t.type !== k) onTypeChange(t.id, k); }}>
                                                                                                <i className={TICKET_TYPE_META[k].icon + " me-2"} />{TICKET_TYPE_META[k].label}
                                                                                            </DropdownItem>
                                                                                        ))}
                                                                                        <DropdownItem divider />
                                                                                        <DropdownItem onClick={(e) => { e.stopPropagation(); navigate(detailHref(t.id)); }}>
                                                                                            <i className="ri-eye-line me-2" />Ver detalhe
                                                                                        </DropdownItem>
                                                                                    </DropdownMenu>
                                                                                </UncontrolledDropdown>
                                                                            )}
                                                                        </div>

                                                                        <p className="text-muted mb-2 fs-12 text-truncate">
                                                                            {showCompany && <><span className="fw-semibold">{t.company_name ?? `Empresa #${t.company_id}`}</span>{" · "}</>}
                                                                            {tm.label}
                                                                        </p>

                                                                        {qm && (
                                                                            <Badge color={qm.color}>
                                                                                <i className="ri-money-euro-circle-line me-1" />
                                                                                {qm.label}{t.quoted_amount != null ? ` · ${formatEuro(t.quoted_amount)}` : ""}
                                                                            </Badge>
                                                                        )}
                                                                    </CardBody>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </Draggable>
                                                );
                                            })}
                                            {dropProvided.placeholder}
                                            {items.length === 0 && (
                                                <p className="text-muted fs-12 text-center mb-0 py-3">Sem tickets</p>
                                            )}
                                        </div>
                                    )}
                                </Droppable>
                            </SimpleBar>
                        </div>
                    );
                })}
            </div>
        </DragDropContext>
    );
};

export default TicketsKanban;
