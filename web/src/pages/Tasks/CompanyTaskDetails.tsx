import React, { useEffect, useMemo, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner, Input, Label } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import BreadCrumb from "Components/Common/BreadCrumb";
import {
    ICompanyTask, CompanyTaskStatus, TASK_COLUMNS, TASK_STATUS_META,
} from "common/models/companyTask.model";
import {
    getCompanyTasks, updateCompanyTask, moveCompanyTask, getCompanyUsers,
} from "helpers/laravel_helper";

/**
 * XPLENDOR — DETALHE de uma tarefa interna (B1), com o VISUAL do TaskDetails do
 * template Velzon (template/pages/Tasks/TaskDetails): layout de duas colunas —
 * barra lateral (Col xxl=3) com o cartão de detalhes (estilo "table-card" +
 * select de "board") e o cartão "Assigned To"; e a coluna principal (Col xxl=9)
 * com o cartão "Summary".
 *
 * ÂMBITO: só os campos que a entidade company_tasks JÁ tem — título, descrição,
 * coluna/estado, responsável. As secções do template para campos que NÃO temos
 * foram OMITIDAS (não mostradas vazias):
 *   · Time Tracking (timer)      → não há tempo
 *   · Priority / Due Date / Nº projeto (linhas da tabela) → não existem
 *   · Sub-tasks (checklist)      → não há
 *   · Tasks Tags                 → não há
 *   · Attachments                → não há
 *   · Comments                   → não há
 *
 * Tenancy: scoped por company_id (lê via index já scoped; edita via os endpoints
 * existentes). Sem backend novo — reutiliza CompanyTaskController/Service.
 */

type CompanyUser = { id: number; name: string };

const initials = (name?: string | null): string => {
    if (!name) return "?";
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? "") + (parts.length > 1 ? parts[parts.length - 1][0] : "")).toUpperCase() || "?";
};

const CompanyTaskDetails = () => {
    document.title = "Detalhe da tarefa | Xplendor";
    const { id } = useParams();
    const navigate = useNavigate();
    const taskId = Number(id);

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [task, setTask] = useState<ICompanyTask | null>(null);
    const [users, setUsers] = useState<CompanyUser[]>([]);
    const [loading, setLoading] = useState(true);
    const [notFound, setNotFound] = useState(false);

    // Campos editáveis (título/descrição têm botão Guardar; estado e responsável
    // persistem logo na alteração).
    const [fTitle, setFTitle] = useState("");
    const [fDescription, setFDescription] = useState("");
    const [savingSummary, setSavingSummary] = useState(false);

    useEffect(() => {
        if (!companyId || !taskId) { setLoading(false); setNotFound(true); return; }
        setLoading(true);
        // Lê via o index (já scoped por empresa) e encontra a tarefa — sem backend novo.
        getCompanyTasks(companyId)
            .then((r: any) => {
                const list = (r?.data ?? []) as ICompanyTask[];
                const found = list.find((t) => t.id === taskId) ?? null;
                if (!found) { setNotFound(true); return; }
                setTask(found);
                setFTitle(found.title);
                setFDescription(found.description ?? "");
            })
            .catch(() => setNotFound(true))
            .finally(() => setLoading(false));

        getCompanyUsers(companyId)
            .then((r: any) => setUsers(((r?.data ?? []) as any[]).map((u) => ({ id: u.id, name: u.name }))))
            .catch(() => setUsers([]));
    }, [companyId, taskId]);

    const saveSummary = async () => {
        if (!task) return;
        if (!fTitle.trim()) { toast.error("Dá um título à tarefa."); return; }
        setSavingSummary(true);
        try {
            const r: any = await updateCompanyTask(companyId, task.id, {
                title: fTitle.trim(), description: fDescription.trim(),
            });
            setTask(r?.data ?? { ...task, title: fTitle.trim(), description: fDescription.trim() });
            toast.success("Tarefa atualizada.");
        } catch (e: any) {
            toast.error(e?.response?.data?.message || "Não foi possível guardar.");
        } finally {
            setSavingSummary(false);
        }
    };

    const changeStatus = async (status: CompanyTaskStatus) => {
        if (!task || status === task.status) return;
        const prev = task;
        setTask({ ...task, status });
        try {
            // Reutiliza o endpoint de mover (só muda o estado; a ordem mantém-se).
            const r: any = await moveCompanyTask(companyId, task.id, status, []);
            if (r?.data) setTask(r.data);
        } catch {
            toast.error("Não foi possível mudar o estado.");
            setTask(prev);
        }
    };

    const changeAssignee = async (value: string) => {
        if (!task) return;
        const assignee = value ? Number(value) : null;
        const prev = task;
        const name = users.find((u) => u.id === assignee)?.name ?? null;
        setTask({ ...task, assignee_user_id: assignee, assignee_name: name });
        try {
            const r: any = await updateCompanyTask(companyId, task.id, { assignee_user_id: assignee });
            if (r?.data) setTask(r.data);
        } catch (e: any) {
            toast.error(e?.response?.data?.message || "Não foi possível mudar o responsável.");
            setTask(prev);
        }
    };

    if (loading) {
        return (
            <div className="page-content">
                <Container fluid>
                    <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                </Container>
            </div>
        );
    }

    if (notFound || !task) {
        return (
            <div className="page-content">
                <Container fluid>
                    <BreadCrumb title="Detalhe da tarefa" pageTitle="Tarefas" pageLink="/tasks" />
                    <Card><CardBody>
                        <p className="text-muted mb-2">Tarefa não encontrada.</p>
                        <button className="btn btn-primary" onClick={() => navigate("/tasks")}>Voltar ao quadro</button>
                    </CardBody></Card>
                </Container>
            </div>
        );
    }

    const statusMeta = TASK_STATUS_META[task.status];

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <BreadCrumb title="Detalhe da tarefa" pageTitle="Tarefas" pageLink="/tasks" />
                <Row>
                    {/* Barra lateral — estilo TimeTracking do template (sem timer/anexos). */}
                    <Col xxl={3}>
                        {/* Cartão de detalhes (estilo "table-card") + select de estado
                            (o "Select Task board" do template). */}
                        <Card className="mb-3">
                            <CardBody>
                                <div className="mb-4">
                                    <Label className="form-label">Coluna</Label>
                                    <select
                                        className="form-control"
                                        value={task.status}
                                        onChange={(e) => changeStatus(e.target.value as CompanyTaskStatus)}
                                    >
                                        {TASK_COLUMNS.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                                    </select>
                                </div>
                                <div className="table-card">
                                    <table className="table mb-0">
                                        <tbody>
                                            <tr>
                                                <td className="fw-medium">Nº tarefa</td>
                                                <td>#{task.id}</td>
                                            </tr>
                                            <tr>
                                                <td className="fw-medium">Título</td>
                                                <td>{task.title}</td>
                                            </tr>
                                            <tr>
                                                <td className="fw-medium">Estado</td>
                                                <td><span className={`badge bg-${statusMeta.color}-subtle text-${statusMeta.color}`}>{statusMeta.label}</span></td>
                                            </tr>
                                            {task.creator_name && (
                                                <tr>
                                                    <td className="fw-medium">Criada por</td>
                                                    <td>{task.creator_name}</td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardBody>
                        </Card>

                        {/* Cartão "Assigned To" do template → Responsável (assignee). */}
                        <div className="card mb-3">
                            <div className="card-body">
                                <div className="d-flex mb-3">
                                    <h6 className="card-title mb-0 flex-grow-1">Responsável</h6>
                                </div>
                                <ul className="list-unstyled vstack gap-3 mb-3">
                                    <li>
                                        <div className="d-flex align-items-center">
                                            <div className="flex-shrink-0">
                                                <div className="avatar-xs">
                                                    <div className="avatar-title rounded-circle bg-primary-subtle text-primary">
                                                        {initials(task.assignee_name)}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex-grow-1 ms-2">
                                                <h6 className="mb-0">{task.assignee_name ?? "Sem responsável"}</h6>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                                <select
                                    className="form-control"
                                    value={task.assignee_user_id ?? ""}
                                    onChange={(e) => changeAssignee(e.target.value)}
                                >
                                    <option value="">Sem responsável</option>
                                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                            </div>
                        </div>
                    </Col>

                    {/* Coluna principal — cartão "Summary" do template. */}
                    <Col xxl={9}>
                        <Card>
                            <CardBody>
                                <div className="mb-3">
                                    <Label className="form-label text-uppercase">Título</Label>
                                    <Input type="text" value={fTitle} onChange={(e) => setFTitle(e.target.value)} />
                                </div>
                                <div className="mb-3">
                                    <h6 className="mb-2 text-uppercase">Descrição</h6>
                                    <Input
                                        type="textarea"
                                        rows={6}
                                        value={fDescription}
                                        onChange={(e) => setFDescription(e.target.value)}
                                        placeholder="Detalhes da tarefa (opcional)"
                                    />
                                </div>
                                <div className="text-end">
                                    <button className="btn btn-primary" onClick={saveSummary} disabled={savingSummary}>
                                        {savingSummary ? <><Spinner size="sm" className="me-1" /> A guardar…</> : "Guardar"}
                                    </button>
                                </div>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default CompanyTaskDetails;
