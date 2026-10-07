import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { DragDropContext, Droppable, Draggable, type DropResult } from "@hello-pangea/dnd";
import SimpleBar from "simplebar-react";
import {
    Button, Card, CardBody, Container, Spinner,
    Modal, ModalHeader, ModalBody, ModalFooter, Input, Label,
} from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import {
    ICompanyTask, CompanyTaskStatus, TASK_COLUMNS,
} from "common/models/companyTask.model";
import {
    getCompanyTasks, createCompanyTask,
    moveCompanyTask, deleteCompanyTask, getCompanyUsers,
} from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { confirmAction } from "helpers/swal";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import XSelect from "pages/Editorial/XSelect";

/**
 * XPLENDOR — Kanban de TAREFAS internas do cliente (painel do stand). Entidade
 * nova (≠ tickets): tarefas livres da equipa. PARTILHADO por company_id (toda a
 * equipa vê/edita o mesmo quadro). Colunas FIXAS: A Fazer / Em Curso / Concluído.
 *
 * VISUAL do template Velzon (.tasks-board / .tasks-list / .tasks-wrapper /
 * .task-box, SimpleBar) — o mesmo do Kanban de tickets. Ao contrário da vista de
 * tickets do cliente (só leitura), AQUI o cliente arrasta livremente: mover
 * persiste o estado + a ordem. CRUD completo por modal.
 */

type CompanyUser = { id: number; name: string };
type Board = Record<CompanyTaskStatus, ICompanyTask[]>;

const emptyBoard = (): Board => ({ todo: [], doing: [], done: [] });

const buildBoard = (tasks: ICompanyTask[]): Board => {
    const board = emptyBoard();
    tasks.forEach((t) => {
        const col = (["todo", "doing", "done"] as string[]).includes(t.status) ? t.status : "todo";
        board[col].push(t);
    });
    (Object.keys(board) as CompanyTaskStatus[]).forEach((k) => {
        board[k].sort((a, b) => a.order - b.order || a.id - b.id);
    });
    return board;
};

const CompanyTasksKanban = () => {
    document.title = "Tarefas | Xplendor";
    const navigate = useNavigate();

    const companyId = useWorkingCompanyId();

    const [board, setBoard] = useState<Board>(emptyBoard());
    const [users, setUsers] = useState<CompanyUser[]>([]);
    const [loading, setLoading] = useState(true);

    // Modal — SÓ criação. A edição de uma tarefa vive na vista de detalhe
    // (/tasks/:id, visual do TaskDetails do template); clicar num cartão abre-a.
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [fStatus, setFStatus] = useState<CompanyTaskStatus>("todo");
    const [fTitle, setFTitle] = useState("");
    const [fDescription, setFDescription] = useState("");
    const [fAssignee, setFAssignee] = useState<string>("");

    const load = () => {
        if (!companyId) return;
        setLoading(true);
        getCompanyTasks(companyId)
            .then((r: any) => setBoard(buildBoard((r?.data ?? []) as ICompanyTask[])))
            .catch(() => setBoard(emptyBoard()))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        load();
        if (companyId) {
            getCompanyUsers(companyId)
                .then((r: any) => setUsers(((r?.data ?? []) as any[]).map((u) => ({ id: u.id, name: u.name }))))
                .catch(() => setUsers([]));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId]);

    const openCreate = (status: CompanyTaskStatus) => {
        setFStatus(status);
        setFTitle(""); setFDescription(""); setFAssignee("");
        setOpen(true);
    };

    const submit = async () => {
        if (!fTitle.trim()) { toast.error("Indique o título da tarefa."); return; }
        setSaving(true);
        const assignee = fAssignee ? Number(fAssignee) : null;
        try {
            await createCompanyTask(companyId, {
                title: fTitle.trim(), description: fDescription.trim(), status: fStatus, assignee_user_id: assignee,
            });
            toast.success("Tarefa criada.");
            setOpen(false);
            load();
        } catch (e: any) {
            toast.error(e?.response?.data?.message || "Não foi possível criar a tarefa.");
        } finally {
            setSaving(false);
        }
    };

    const remove = async (t: ICompanyTask) => {
        const ok = await confirmAction({ title: `Apagar a tarefa "${t.title}"?`, text: "Esta ação não se desfaz.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" });
        if (!ok) return;
        try {
            await deleteCompanyTask(companyId, t.id);
            toast.success("Tarefa apagada.");
            load();
        } catch {
            toast.error("Não foi possível apagar a tarefa.");
        }
    };

    // Arrastar: move a tarefa e persiste estado + ordem da coluna de destino.
    const handleDragEnd = async (result: DropResult) => {
        const { source, destination, draggableId } = result;
        if (!destination) return;
        if (source.droppableId === destination.droppableId && source.index === destination.index) return;

        const from = source.droppableId as CompanyTaskStatus;
        const to = destination.droppableId as CompanyTaskStatus;
        const taskId = Number(draggableId);

        const snapshot = board;
        const next: Board = { todo: [...board.todo], doing: [...board.doing], done: [...board.done] };
        const [moved] = next[from].splice(source.index, 1);
        if (!moved) return;
        next[to].splice(destination.index, 0, { ...moved, status: to });
        setBoard(next);

        const orderedIds = next[to].map((t) => t.id);
        try {
            await moveCompanyTask(companyId, taskId, to, orderedIds);
        } catch {
            toast.error("Não foi possível mover a tarefa.");
            setBoard(snapshot);
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Tarefas" breadcrumbs={[{ label: "Equipa" }]}
                    description="O quadro de tarefas da equipa, partilhado por toda a empresa."
                    actions={<Button color="primary" onClick={() => openCreate("todo")}><i className="ri-add-line me-1" />Nova tarefa</Button>} />

                <Card>
                    <CardBody>
                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : (
                            <DragDropContext onDragEnd={handleDragEnd}>
                                <div className="tasks-board mb-3 d-flex" id="kanbanboard">
                                    {TASK_COLUMNS.map((col) => {
                                        const items = board[col.key];
                                        return (
                                            <div className="tasks-list" key={col.key}>
                                                <div className="d-flex mb-3 align-items-center">
                                                    <div className="flex-grow-1">
                                                        <h6 className="fs-14 text-uppercase fw-semibold mb-0">
                                                            {col.label}
                                                            <small className={`badge bg-${col.color} align-bottom ms-1 totaltask-badge`}>{items.length}</small>
                                                        </h6>
                                                    </div>
                                                    <Button size="sm" color="outline-primary" onClick={() => openCreate(col.key)} title="Nova tarefa" aria-label={`Nova tarefa em ${col.label}`}>
                                                        <i className="ri-add-line" />
                                                    </Button>
                                                </div>

                                                <SimpleBar className="tasks-wrapper px-3 mx-n3">
                                                    <Droppable droppableId={col.key}>
                                                        {(dropProvided) => (
                                                            <div ref={dropProvided.innerRef} {...dropProvided.droppableProps} className={items.length ? "tasks" : "tasks noTask"}>
                                                                {items.map((t, index) => (
                                                                    <Draggable draggableId={String(t.id)} index={index} key={t.id}>
                                                                        {(dragProvided) => (
                                                                            <div
                                                                                ref={dragProvided.innerRef}
                                                                                {...dragProvided.draggableProps}
                                                                                {...dragProvided.dragHandleProps}
                                                                                className="pb-1 task-list"
                                                                            >
                                                                                <div className="card task-box mb-0" style={{ cursor: "pointer" }} onClick={() => navigate(`/tasks/${t.id}`)}>
                                                                                    <CardBody className="p-3">
                                                                                        <div className="d-flex align-items-start gap-2 mb-1">
                                                                                            <h6 className="fs-14 mb-0 flex-grow-1">{t.title}</h6>
                                                                                            {/* O menu abre num portal: o clique não pode chegar ao cartão (que abre a tarefa). */}
                                                                                            <div className="flex-shrink-0" onClick={(e) => e.stopPropagation()}>
                                                                                                <ActionsMenu size="sm" label={`Mais ações: ${t.title}`} items={[
                                                                                                    { label: "Abrir", icon: "ri-eye-line", onClick: () => navigate(`/tasks/${t.id}`) },
                                                                                                    { label: "Apagar", icon: "ri-delete-bin-line", danger: true, onClick: () => void remove(t) },
                                                                                                ]} />
                                                                                            </div>
                                                                                        </div>

                                                                                        {t.description && (
                                                                                            <p className="text-muted mb-2 fs-12">{t.description}</p>
                                                                                        )}

                                                                                        <div className="d-flex align-items-center">
                                                                                            {t.assignee_name ? (
                                                                                                <span className="badge bg-light text-body">
                                                                                                    <i className="ri-user-line me-1" />{t.assignee_name}
                                                                                                </span>
                                                                                            ) : (
                                                                                                <span className="badge bg-light text-muted">
                                                                                                    <i className="ri-user-line me-1" />Sem responsável
                                                                                                </span>
                                                                                            )}
                                                                                        </div>
                                                                                    </CardBody>
                                                                                </div>
                                                                            </div>
                                                                        )}
                                                                    </Draggable>
                                                                ))}
                                                                {dropProvided.placeholder}
                                                                {items.length === 0 && (
                                                                    <p className="text-muted fs-12 text-center mb-0 py-3">Sem tarefas</p>
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
                        )}
                    </CardBody>
                </Card>
            </Container>

            {/* Modal — criar tarefa (edição vive no detalhe /tasks/:id). */}
            <Modal isOpen={open} toggle={() => setOpen(false)} centered>
                <ModalHeader toggle={() => setOpen(false)}>Nova tarefa</ModalHeader>
                <ModalBody>
                    <div className="mb-3">
                        <Label className="form-label">Título</Label>
                        <Input type="text" value={fTitle} onChange={(e) => setFTitle(e.target.value)} placeholder="O que precisa de ser feito" />
                    </div>
                    <div className="mb-3">
                        <Label className="form-label">Descrição</Label>
                        <Input type="textarea" rows={3} value={fDescription} onChange={(e) => setFDescription(e.target.value)} placeholder="Detalhes (opcional)" />
                    </div>
                    <div className="mb-1">
                        <Label className="form-label">Responsável</Label>
                        <XSelect ariaLabel="Responsável" value={fAssignee} onChange={setFAssignee}
                            options={[{ value: "", label: "Sem responsável" }, ...users.map((u) => ({ value: String(u.id), label: u.name }))]} />
                    </div>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setOpen(false)}>Cancelar</Button>
                    <Button color="primary" onClick={submit} disabled={saving}>
                        {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : "Criar tarefa"}
                    </Button>
                </ModalFooter>
            </Modal>
        </div>
    );
};

export default CompanyTasksKanban;
