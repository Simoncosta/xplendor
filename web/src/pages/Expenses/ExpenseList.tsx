// React
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Card, CardBody, Col, Container, Row, Table, Input, Label, Badge } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
// Components
import Pagination from "Components/Common/Pagination";
import XButton from "Components/Common/XButton";
import ExpenseFormModal, { CarOption } from "./components/ExpenseFormModal";
// Redux / helpers
import { getExpenses, getExpensesSummary, deleteExpense, updateExpense } from "slices/expenses/thunk";
import { getExpenseCategories, getSuppliers, getCarsPaginate } from "helpers/laravel_helper";
// Models
import { IExpense } from "common/models/expense.model";
import { IExpenseCategory } from "common/models/expense-category.model";
import { ISupplier } from "common/models/supplier.model";
// Helpers
import { confirmDelete, alertMessage } from "helpers/swal";

interface Option { value: number; label: string; }

const eur = (v: number) =>
    new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 2 }).format(v || 0);

const carLabel = (c: any): string =>
    `${c.brand?.name ?? ""} ${c.model?.name ?? ""}`.trim() + (c.license_plate ? ` (${c.license_plate})` : "") || `#${c.id}`;

/**
 * Carrega TODAS as viaturas da empresa para o select — sem filtro de status
 * (uma despesa pode ligar-se a uma viatura vendida/rascunho/inativa). Pagina de
 * 100 em 100 (o PaginateRequest limita perPage a 100; perPage=1000 dava 422).
 */
async function loadAllCarOptions(companyId: number): Promise<CarOption[]> {
    const first: any = await getCarsPaginate({ companyId, perPage: 100, page: 1 });
    const cars: any[] = [...(first?.data?.data ?? [])];
    const lastPage: number = first?.data?.last_page ?? 1;

    if (lastPage > 1) {
        const rest = await Promise.all(
            Array.from({ length: lastPage - 1 }, (_, i) =>
                getCarsPaginate({ companyId, perPage: 100, page: i + 2 }).then((r: any) => r?.data?.data ?? [])
            )
        );
        rest.forEach((page) => cars.push(...page));
    }

    return cars.map((c) => ({ value: c.id, label: carLabel(c) }));
}

const selectState = (state: any) => state.Expense;
const selectViewModel = createSelector([selectState], (s) => ({
    expenses: s.data.expenses as IExpense[],
    meta: s.data.meta,
    summary: s.data.summary,
    loading: s.loading.list,
}));

const ExpenseList = () => {
    const dispatch: any = useDispatch();
    document.title = "Despesas | Xplendor";

    const { expenses, meta, summary, loading } = useSelector(selectViewModel);

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        return Number(JSON.parse(authUser).company_id || 0);
    }, []);

    // Opções para filtros + formulário.
    const [categoryOptions, setCategoryOptions] = useState<Option[]>([]);
    const [supplierOptions, setSupplierOptions] = useState<Option[]>([]);
    const [carOptions, setCarOptions] = useState<CarOption[]>([]);

    // Filtros.
    const [fCategory, setFCategory] = useState<Option | null>(null);
    const [fSupplier, setFSupplier] = useState<Option | null>(null);
    const [fCar, setFCar] = useState<CarOption | null>(null);
    const [fPaid, setFPaid] = useState<"" | "1" | "0">("");
    const [fFrom, setFFrom] = useState<string>("");
    const [fTo, setFTo] = useState<string>("");
    const [includeArchived, setIncludeArchived] = useState(false);

    const [pagination, setPagination] = useState({ pageIndex: 0, pageSize: 15 });

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<IExpense | null>(null);

    // Carrega opções (categorias/fornecedores/viaturas) uma vez.
    useEffect(() => {
        if (!companyId) return;

        getExpenseCategories(companyId, { only_active: 1 })
            .then((res: any) => setCategoryOptions(((res?.data as IExpenseCategory[]) ?? []).map((c) => ({ value: c.id, label: c.name }))))
            .catch(() => setCategoryOptions([]));

        getSuppliers(companyId, { only_active: 1 })
            .then((res: any) => setSupplierOptions(((res?.data as ISupplier[]) ?? []).map((s) => ({ value: s.id, label: s.name }))))
            .catch(() => setSupplierOptions([]));

        loadAllCarOptions(companyId).then(setCarOptions).catch(() => setCarOptions([]));
    }, [companyId]);

    const filterParams = useMemo(() => {
        const p: Record<string, any> = {};
        if (fCategory) p.expense_category_id = fCategory.value;
        if (fSupplier) p.supplier_id = fSupplier.value;
        if (fCar) p.car_id = fCar.value;
        if (fPaid !== "") p.is_paid = fPaid;
        if (fFrom) p.date_from = fFrom;
        if (fTo) p.date_to = fTo;
        if (includeArchived) p.include_archived = 1;
        return p;
    }, [fCategory, fSupplier, fCar, fPaid, fFrom, fTo, includeArchived]);

    const fetchAll = useCallback(() => {
        if (!companyId) return;
        dispatch(getExpenses({ companyId, page: pagination.pageIndex + 1, perPage: pagination.pageSize, ...filterParams }));
        dispatch(getExpensesSummary({ companyId, ...filterParams }));
    }, [dispatch, companyId, pagination.pageIndex, pagination.pageSize, filterParams]);

    useEffect(() => {
        fetchAll();
    }, [fetchAll]);

    const resetToFirstPage = () => setPagination((p) => ({ ...p, pageIndex: 0 }));

    const clearFilters = () => {
        setFCategory(null); setFSupplier(null); setFCar(null);
        setFPaid(""); setFFrom(""); setFTo(""); setIncludeArchived(false);
        resetToFirstPage();
    };

    const openCreate = () => { setEditing(null); setFormOpen(true); };
    const openEdit = (e: IExpense) => { setEditing(e); setFormOpen(true); };

    const togglePaid = async (e: IExpense) => {
        if (!companyId) return;
        try {
            await dispatch(updateExpense({ companyId, id: e.id, data: { is_paid: !e.is_paid } })).unwrap();
            fetchAll();
        } catch {
            toast.error("Não foi possível atualizar o estado de pagamento.");
        }
    };

    const setArchived = async (e: IExpense, archivedValue: boolean) => {
        if (!companyId) return;
        try {
            await dispatch(updateExpense({ companyId, id: e.id, data: { archived: archivedValue } })).unwrap();
            toast.success(archivedValue ? "Despesa arquivada." : "Despesa restaurada.");
            fetchAll();
        } catch {
            toast.error("Não foi possível atualizar a despesa.");
        }
    };

    const handleDelete = async (e: IExpense) => {
        if (!companyId) return;
        if (!e.can_delete) {
            await alertMessage("Esta despesa está vinculada (viatura/fornecedor/categoria). Arquive-a em vez de a eliminar.", "Não é possível eliminar", "warning");
            return;
        }
        const ok = await confirmDelete(`Vais eliminar a despesa "${e.description}". Esta ação não pode ser anulada.`);
        if (!ok) return;
        try {
            await dispatch(deleteExpense({ companyId, id: e.id })).unwrap();
            toast.success("Despesa eliminada.");
            fetchAll();
        } catch {
            await alertMessage("Esta despesa está vinculada. Arquive-a em vez de a eliminar.", "Não é possível eliminar", "warning");
            fetchAll();
        }
    };

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <Row className="g-2 mb-3 align-items-center">
                        <Col>
                            <h5 className="mb-0">Despesas</h5>
                            <small className="text-muted">Todas as despesas da empresa (com e sem viatura).</small>
                        </Col>
                        <Col xs="auto">
                            <XButton variant="success" type="button" icon={<i className="ri-add-line" />} onClick={openCreate}>
                                Nova despesa
                            </XButton>
                        </Col>
                    </Row>

                    {/* Totais */}
                    <Row className="g-3 mb-3">
                        <Col md={4}>
                            <Card className="mb-0"><CardBody>
                                <p className="text-muted mb-1">Total</p>
                                <h4 className="mb-0">{eur(summary?.total_amount ?? 0)}</h4>
                                <small className="text-muted">{summary?.count ?? 0} despesa(s)</small>
                            </CardBody></Card>
                        </Col>
                        <Col md={4}>
                            <Card className="mb-0"><CardBody>
                                <p className="text-muted mb-1">Em aberto</p>
                                <h4 className="mb-0 text-danger">{eur(summary?.open_amount ?? 0)}</h4>
                            </CardBody></Card>
                        </Col>
                        <Col md={4}>
                            <Card className="mb-0"><CardBody>
                                <p className="text-muted mb-1">Pagas</p>
                                <h4 className="mb-0 text-success">{eur(summary?.paid_amount ?? 0)}</h4>
                            </CardBody></Card>
                        </Col>
                    </Row>

                    {/* Filtros */}
                    <Card className="mb-3"><CardBody>
                        <Row className="g-2">
                            <Col lg={3}>
                                <Label className="form-label mb-1">Categoria</Label>
                                <Select isClearable placeholder="Todas" options={categoryOptions} value={fCategory}
                                    onChange={(o: Option | null) => { setFCategory(o); resetToFirstPage(); }} classNamePrefix="react-select" />
                            </Col>
                            <Col lg={3}>
                                <Label className="form-label mb-1">Fornecedor</Label>
                                <Select isClearable placeholder="Todos" options={supplierOptions} value={fSupplier}
                                    onChange={(o: Option | null) => { setFSupplier(o); resetToFirstPage(); }} classNamePrefix="react-select" />
                            </Col>
                            <Col lg={3}>
                                <Label className="form-label mb-1">Viatura</Label>
                                <Select isClearable placeholder="Todas" options={carOptions} value={fCar}
                                    onChange={(o: CarOption | null) => { setFCar(o); resetToFirstPage(); }} classNamePrefix="react-select" />
                            </Col>
                            <Col lg={3}>
                                <Label className="form-label mb-1">Estado</Label>
                                <Input type="select" value={fPaid} onChange={(e) => { setFPaid(e.target.value as "" | "1" | "0"); resetToFirstPage(); }}>
                                    <option value="">Todos</option>
                                    <option value="1">Pagas</option>
                                    <option value="0">Em aberto</option>
                                </Input>
                            </Col>
                            <Col lg={3}>
                                <Label className="form-label mb-1">De</Label>
                                <Input type="date" value={fFrom} onChange={(e) => { setFFrom(e.target.value); resetToFirstPage(); }} />
                            </Col>
                            <Col lg={3}>
                                <Label className="form-label mb-1">Até</Label>
                                <Input type="date" value={fTo} onChange={(e) => { setFTo(e.target.value); resetToFirstPage(); }} />
                            </Col>
                            <Col lg={3} className="d-flex align-items-end pb-2">
                                <div className="form-check">
                                    <input className="form-check-input" type="checkbox" id="inc-arch" checked={includeArchived}
                                        onChange={(e) => { setIncludeArchived(e.target.checked); resetToFirstPage(); }} />
                                    <label className="form-check-label" htmlFor="inc-arch">Incluir arquivadas</label>
                                </div>
                            </Col>
                            <Col lg={3} className="d-flex align-items-end pb-2">
                                <XButton variant="light" type="button" icon={<i className="ri-filter-off-line" />} onClick={clearFilters}>Limpar filtros</XButton>
                            </Col>
                        </Row>
                    </CardBody></Card>

                    {/* Tabela */}
                    <Card>
                        <CardBody>
                            <div className="table-responsive">
                                <Table className="align-middle table-nowrap mb-0">
                                    <thead className="table-light">
                                        <tr>
                                            <th>Data</th>
                                            <th>Descrição</th>
                                            <th>Categoria</th>
                                            <th>Fornecedor</th>
                                            <th>Viatura</th>
                                            <th className="text-end">Valor</th>
                                            <th>Estado</th>
                                            <th className="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {loading && <tr><td colSpan={8} className="text-center text-muted py-4">A carregar…</td></tr>}
                                        {!loading && expenses.length === 0 && <tr><td colSpan={8} className="text-center text-muted py-4">Sem despesas para os filtros escolhidos.</td></tr>}
                                        {!loading && expenses.map((e) => (
                                            <tr key={e.id} className={e.archived ? "text-muted" : ""}>
                                                <td>{e.date}</td>
                                                <td className={e.archived ? "" : "fw-medium"}>
                                                    {e.description}
                                                    {e.archived && <Badge color="light" className="text-muted ms-2">Arquivada</Badge>}
                                                </td>
                                                <td>
                                                    {e.category_name ? (
                                                        <span>
                                                            <span className="d-inline-block rounded-circle align-middle me-1" style={{ width: 10, height: 10, backgroundColor: e.category_color || "#ced4da" }} />
                                                            {e.category_name}
                                                        </span>
                                                    ) : <span className="text-muted">Sem categoria</span>}
                                                </td>
                                                <td>{e.supplier_name || <span className="text-muted">—</span>}</td>
                                                <td>{e.car_name || <span className="text-muted">—</span>}</td>
                                                <td className="text-end fw-medium">{eur(e.amount)}</td>
                                                <td>
                                                    <button
                                                        className={`btn btn-sm ${e.is_paid ? "btn-soft-success" : "btn-soft-warning"}`}
                                                        onClick={() => togglePaid(e)}
                                                        title="Alternar pago/aberto"
                                                    >
                                                        {e.is_paid ? `Paga${e.paid_at ? ` · ${e.paid_at}` : ""}` : "Em aberto"}
                                                    </button>
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
                    </Card>

                    <Pagination
                        currentPage={meta?.current_page ?? 1}
                        lastPage={meta?.last_page ?? 1}
                        total={meta?.total ?? 0}
                        perPage={meta?.per_page ?? pagination.pageSize}
                        from={meta?.from ?? 0}
                        to={meta?.to ?? 0}
                        onPageChange={(page) => setPagination((prev) => ({ ...prev, pageIndex: page - 1 }))}
                    />
                </Container>
            </div>

            <ExpenseFormModal
                isOpen={formOpen}
                toggle={() => setFormOpen(false)}
                expense={editing}
                companyId={companyId}
                carOptions={carOptions}
                onSaved={fetchAll}
            />
        </React.Fragment>
    );
};

export default ExpenseList;
