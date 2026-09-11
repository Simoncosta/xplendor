// React
import { useCallback, useEffect, useState } from "react";
import { useDispatch } from "react-redux";
import { Card, CardBody, CardHeader, Table, Badge } from "reactstrap";
import { toast } from "react-toastify";
// Components
import XButton from "Components/Common/XButton";
import ExpenseFormModal from "pages/Expenses/components/ExpenseFormModal";
// Redux / helpers
import { deleteExpense, updateExpense } from "slices/expenses/thunk";
import { getExpenses } from "helpers/laravel_helper";
// Models
import { IExpense } from "common/models/expense.model";
// Helpers
import { confirmDelete, alertMessage } from "helpers/swal";

interface CarExpensesCardProps {
    companyId: number;
    carId: number;
}

const eur = (v: number) =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 2 }).format(v || 0);

/**
 * Bloco "Despesas" da Ficha da viatura. Lista as despesas DAQUELA viatura e
 * permite adicionar — o car_id fica preenchido automaticamente (contexto).
 * Estado local (desacoplado da tela geral de Despesas).
 */
export default function CarExpensesCard({ companyId, carId }: CarExpensesCardProps) {
    const dispatch: any = useDispatch();
    const [expenses, setExpenses] = useState<IExpense[]>([]);
    const [loading, setLoading] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<IExpense | null>(null);

    const fetchList = useCallback(() => {
        if (!companyId || !carId) return;
        setLoading(true);
        // include_archived=1 → o histórico da viatura mostra também as arquivadas.
        getExpenses(companyId, { car_id: carId, include_archived: 1, perPage: 200 })
            .then((res: any) => setExpenses((res?.data?.data as IExpense[]) ?? []))
            .catch(() => setExpenses([]))
            .finally(() => setLoading(false));
    }, [companyId, carId]);

    useEffect(() => { fetchList(); }, [fetchList]);

    const total = expenses.filter((e) => !e.archived).reduce((sum, e) => sum + (e.amount || 0), 0);

    const openCreate = () => { setEditing(null); setFormOpen(true); };
    const openEdit = (e: IExpense) => { setEditing(e); setFormOpen(true); };

    const setArchived = async (e: IExpense, archivedValue: boolean) => {
        try {
            await dispatch(updateExpense({ companyId, id: e.id, data: { archived: archivedValue } })).unwrap();
            toast.success(archivedValue ? "Despesa arquivada." : "Despesa restaurada.");
            fetchList();
        } catch {
            toast.error("Não foi possível atualizar a despesa.");
        }
    };

    const handleDelete = async (e: IExpense) => {
        if (!e.can_delete) {
            await alertMessage("Esta despesa está vinculada. Arquive-a em vez de a eliminar.", "Não é possível eliminar", "warning");
            return;
        }
        const ok = await confirmDelete(`Vais eliminar a despesa "${e.description}".`);
        if (!ok) return;
        try {
            await dispatch(deleteExpense({ companyId, id: e.id })).unwrap();
            toast.success("Despesa eliminada.");
            fetchList();
        } catch {
            await alertMessage("Esta despesa está vinculada. Arquive-a em vez de a eliminar.", "Não é possível eliminar", "warning");
            fetchList();
        }
    };

    return (
        <Card className="mt-3">
            <CardHeader className="d-flex align-items-center justify-content-between">
                <div>
                    <h5 className="mb-0">Despesas</h5>
                    <small className="text-muted">Custos associados a esta viatura · Total {eur(total)}</small>
                </div>
                <XButton variant="success" outline type="button" icon={<i className="ri-add-line" />} onClick={openCreate}>
                    Adicionar despesa
                </XButton>
            </CardHeader>
            <CardBody>
                <div className="table-responsive">
                    <Table className="align-middle table-nowrap mb-0">
                        <thead className="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Descrição</th>
                                <th>Categoria</th>
                                <th>Fornecedor</th>
                                <th className="text-end">Valor</th>
                                <th>Estado</th>
                                <th className="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && <tr><td colSpan={7} className="text-center text-muted py-3">A carregar…</td></tr>}
                            {!loading && expenses.length === 0 && <tr><td colSpan={7} className="text-center text-muted py-3">Sem despesas registadas para esta viatura.</td></tr>}
                            {!loading && expenses.map((e) => (
                                <tr key={e.id} className={e.archived ? "text-muted" : ""}>
                                    <td>{e.date}</td>
                                    <td className={e.archived ? "" : "fw-medium"}>
                                        {e.description}
                                        {e.archived && <Badge color="light" className="text-muted ms-2">Arquivada</Badge>}
                                    </td>
                                    <td>{e.category_name || <span className="text-muted">Sem categoria</span>}</td>
                                    <td>{e.supplier_name || <span className="text-muted">—</span>}</td>
                                    <td className="text-end fw-medium">{eur(e.amount)}</td>
                                    <td>
                                        <Badge color={e.is_paid ? "success" : "warning"} className="bg-opacity-75">
                                            {e.is_paid ? "Paga" : "Em aberto"}
                                        </Badge>
                                    </td>
                                    <td className="text-end">
                                        <button className="btn btn-sm btn-soft-primary me-1" onClick={() => openEdit(e)} title="Editar"><i className="ri-pencil-line" /></button>
                                        {e.archived ? (
                                            <button className="btn btn-sm btn-soft-success me-1" onClick={() => setArchived(e, false)} title="Restaurar"><i className="ri-inbox-unarchive-line" /></button>
                                        ) : (
                                            <button className="btn btn-sm btn-soft-secondary me-1" onClick={() => setArchived(e, true)} title="Arquivar"><i className="ri-archive-line" /></button>
                                        )}
                                        <button className="btn btn-sm btn-soft-danger" onClick={() => handleDelete(e)} title="Eliminar"><i className="ri-delete-bin-line" /></button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            </CardBody>

            <ExpenseFormModal
                isOpen={formOpen}
                toggle={() => setFormOpen(false)}
                expense={editing}
                companyId={companyId}
                fixedCarId={carId}
                onSaved={fetchList}
            />
        </Card>
    );
}
