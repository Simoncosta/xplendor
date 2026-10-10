// React
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Button, Card, CardBody, Col, Container, Row, Input, Label, Badge } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
// Components
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import ActionsMenu from "Components/Common/ActionsMenu";
import XSelect from "Components/Common/Select";
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
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import XplendorChargesNotice from "pages/Charges/XplendorChargesNotice";
import { CHARGE_STATUS_META, dmy } from "common/models/charge.model";
import { companyChargeInvoicePath } from "helpers/laravel_helper";
import { openPdfGet } from "helpers/download_helper";
import { useModules } from "contexts/ModulesContext";

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

    const companyId = useWorkingCompanyId();

    // Opções para filtros + formulário.
    const [categoryOptions, setCategoryOptions] = useState<Option[]>([]);
    const [supplierOptions, setSupplierOptions] = useState<Option[]>([]);
    const [carOptions, setCarOptions] = useState<CarOption[]>([]);

    // Filtros ("" = todos; os ids vão como texto para o XSelect).
    const [fCategory, setFCategory] = useState<string>("");
    const [fSupplier, setFSupplier] = useState<string>("");
    const [fCar, setFCar] = useState<string>("");
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

    }, [companyId]);

    // Viaturas só com o módulo de stock (sem ele, o pedido daria 403).
    const { has, loading: modulesLoading } = useModules();
    const hasStock = !modulesLoading && has("stock");
    useEffect(() => {
        if (!companyId || !hasStock) { setCarOptions([]); return; }
        loadAllCarOptions(companyId).then(setCarOptions).catch(() => setCarOptions([]));
    }, [companyId, hasStock]);

    const filterParams = useMemo(() => {
        const p: Record<string, any> = {};
        if (fCategory) p.expense_category_id = Number(fCategory);
        if (fSupplier) p.supplier_id = Number(fSupplier);
        if (fCar) p.car_id = Number(fCar);
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
        setFCategory(""); setFSupplier(""); setFCar("");
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
        const ok = await confirmDelete(`Vai eliminar a despesa "${e.description}". Esta ação não pode ser anulada.`);
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

    const activeFilterCount = [fCategory, fSupplier, fCar, fPaid, fFrom, fTo, includeArchived].filter(Boolean).length;
    const all = (label: string, opts: { value: number; label: string }[]) => [{ value: "", label }, ...opts.map((o) => ({ value: String(o.value), label: o.label }))];
    const FL = "text-muted fw-semibold fs-11 text-uppercase mb-1";
    const field = (flex = "1 1 170px") => ({ flex, minWidth: 0 });

    const cols = useDataColumns<IExpense>("financas.despesas", [
        { id: "date", header: "Data", value: (e) => e.date, nowrap: true },
        {
            id: "description", header: "Descrição", value: (e) => e.description, hideable: false, mobile: "title",
            cell: (e) => (
                <span className={e.archived ? "" : "fw-medium"}>
                    {e.description}
                    {e.is_automatic && !e.is_xplendor_charge && <Badge color="info" className="bg-info-subtle text-info ms-2" title="Despesa automática: atualizada todos os dias a partir do gasto reportado pela Meta (sem IVA). Não é editável.">Automática (Meta)</Badge>}
                    {e.is_xplendor_charge && e.charge && (
                        <>
                            {e.charge.overdue && <Badge color="danger" className="ms-2 fw-normal">Vencida</Badge>}
                            <div className="text-muted fs-12">Cobrança da XPLENDOR · vence a {dmy(e.charge.due_date)}</div>
                        </>
                    )}
                    {e.archived && <Badge color="light" className="text-muted ms-2">Arquivada</Badge>}
                </span>
            ),
        },
        {
            id: "category", header: "Categoria", value: (e) => e.category_name ?? "",
            cell: (e) => e.category_name ? (
                <span>
                    <span className="d-inline-block rounded-circle align-middle me-1" style={{ width: 10, height: 10, backgroundColor: e.category_color || "#ced4da" }} />
                    {e.category_name}
                </span>
            ) : <span className="text-muted">Sem categoria</span>,
        },
        { id: "supplier", header: "Fornecedor", value: (e) => e.supplier_name ?? "", cell: (e) => e.supplier_name || <span className="text-muted">-</span> },
        { id: "car", header: "Viatura", value: (e) => e.car_name ?? "", cell: (e) => e.car_name || <span className="text-muted">-</span> },
        { id: "amount", header: "Valor", value: (e) => e.amount, cell: (e) => <span className="fw-medium">{eur(e.amount)}</span>, align: "end", nowrap: true },
        {
            id: "status", header: "Estado",
            cell: (e) => e.is_xplendor_charge && e.charge ? (
                <Badge color={CHARGE_STATUS_META[e.charge.status].color} className="fw-normal" title="A XPLENDOR confirma o pagamento">{CHARGE_STATUS_META[e.charge.status].label}</Badge>
            ) : (
                <Badge color={e.is_paid ? "success-subtle" : "warning-subtle"} className={`fw-normal ${e.is_paid ? "text-success" : "text-warning"}`}
                    title={e.is_automatic ? "Cobrada automaticamente pela Meta" : undefined}>
                    {e.is_paid ? `Paga${e.paid_at ? ` · ${e.paid_at}` : ""}` : "Em aberto"}
                </Badge>
            ),
        },
    ] as DTColumn<IExpense>[]);

    const rowActions = (e: IExpense) => e.is_xplendor_charge && e.charge ? (
        <Button size="sm" color="outline-primary" title="Ver a fatura da XPLENDOR (só de leitura)" aria-label={`Ver a fatura da XPLENDOR: ${e.description}`}
            onClick={async () => { const r = await openPdfGet(companyChargeInvoicePath(companyId, e.charge!.id)); if (!r.ok) toast.error("Não foi possível abrir a fatura."); }}>
            <i className="ri-lock-line me-1" /><i className="ri-file-pdf-2-line" />
        </Button>
    ) : e.is_automatic ? (
        <span className="text-muted" title="Despesa automática: atualizada todos os dias a partir do gasto reportado pela Meta (sem IVA). Não é editável."><i className="ri-lock-line" /></span>
    ) : (
        <>
            {!e.is_paid && (
                <Button size="sm" color="success" onClick={() => togglePaid(e)} title="Marcar como paga" aria-label={`Marcar como paga: ${e.description}`}><i className="ri-check-line" /></Button>
            )}
            <Button size="sm" color="outline-primary" onClick={() => openEdit(e)} title="Editar" aria-label={`Editar: ${e.description}`}><i className="ri-pencil-line" /></Button>
            <ActionsMenu size="sm" label={`Mais ações: ${e.description}`} items={[
                { label: "Marcar como em aberto", icon: "ri-arrow-go-back-line", hidden: !e.is_paid, onClick: () => void togglePaid(e) },
                e.archived
                    ? { label: "Restaurar", icon: "ri-inbox-unarchive-line", onClick: () => void setArchived(e, false) }
                    : { label: "Arquivar", icon: "ri-archive-line", onClick: () => void setArchived(e, true) },
                { label: "Eliminar", icon: "ri-delete-bin-line", danger: true, onClick: () => void handleDelete(e) },
            ]} />
        </>
    );

    const metaAuto = summary?.automatic_meta;

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <PageHeader title="Despesas" breadcrumbs={[{ label: "Finanças" }]}
                        info="Todas as despesas da empresa (com e sem viatura)." />

                    {/* Faturas da XPLENDOR por resolver: ver a fatura e indicar "Já paguei". */}
                    <XplendorChargesNotice onChanged={fetchAll} />

                    {/* Totais */}
                    <Row className="g-3 mb-3">
                        <Col md={4}>
                            <Card className="mb-0"><CardBody>
                                <p className="text-muted mb-1">Total</p>
                                <div className="fs-20 fw-semibold">{eur(summary?.total_amount ?? 0)}</div>
                                <small className="text-muted">{summary?.count ?? 0} despesa(s)</small>
                            </CardBody></Card>
                        </Col>
                        <Col md={4}>
                            <Card className="mb-0"><CardBody>
                                <p className="text-muted mb-1">Em aberto</p>
                                <div className="fs-20 fw-semibold text-danger">{eur(summary?.open_amount ?? 0)}</div>
                            </CardBody></Card>
                        </Col>
                        <Col md={4}>
                            <Card className="mb-0"><CardBody>
                                <p className="text-muted mb-1">Pagas</p>
                                <div className="fs-20 fw-semibold text-success">{eur(summary?.paid_amount ?? 0)}</div>
                            </CardBody></Card>
                        </Col>
                    </Row>

                    <PageCard
                        title="Despesas"
                        info={(metaAuto?.amount ?? 0) > 0
                            ? "A publicidade Meta automática é um valor informativo, fora dos totais: conta na margem de cada viatura e a fatura da Meta é o registo financeiro."
                            : undefined}
                        status={(metaAuto?.amount ?? 0) > 0
                            ? <>Publicidade Meta automática: {eur(metaAuto?.amount ?? 0)} ({metaAuto?.count} despesa(s)), fora dos totais</>
                            : undefined}
                        loading={loading && expenses.length > 0}
                        actions={<>
                            {cols.selector}
                            <Button size="sm" color="primary" onClick={openCreate}><i className="ri-add-line me-1" />Nova despesa</Button>
                        </>}
                        filters={
                            <RestFilterBar activeCount={activeFilterCount} onClear={clearFilters}>
                                <div style={field()}>
                                    <Label className={FL}>Categoria</Label>
                                    <XSelect small ariaLabel="Categoria" options={all("Todas", categoryOptions)} value={fCategory}
                                        onChange={(v) => { setFCategory(v); resetToFirstPage(); }} />
                                </div>
                                <div style={field()}>
                                    <Label className={FL}>Fornecedor</Label>
                                    <XSelect small ariaLabel="Fornecedor" options={all("Todos", supplierOptions)} value={fSupplier}
                                        onChange={(v) => { setFSupplier(v); resetToFirstPage(); }} />
                                </div>
                                <div style={field()}>
                                    <Label className={FL}>Viatura</Label>
                                    <XSelect small ariaLabel="Viatura" options={all("Todas", carOptions)} value={fCar}
                                        onChange={(v) => { setFCar(v); resetToFirstPage(); }} />
                                </div>
                                <div style={field("1 1 140px")}>
                                    <Label className={FL}>Estado</Label>
                                    <XSelect<"" | "1" | "0"> small ariaLabel="Estado" value={fPaid} onChange={(v) => { setFPaid(v); resetToFirstPage(); }}
                                        options={[{ value: "", label: "Todos" }, { value: "1", label: "Pagas" }, { value: "0", label: "Em aberto" }]} />
                                </div>
                                <div style={field("1 1 140px")}>
                                    <Label className={FL}>De</Label>
                                    <Input bsSize="sm" type="date" aria-label="De" value={fFrom} onChange={(e) => { setFFrom(e.target.value); resetToFirstPage(); }} />
                                </div>
                                <div style={field("1 1 140px")}>
                                    <Label className={FL}>Até</Label>
                                    <Input bsSize="sm" type="date" aria-label="Até" value={fTo} onChange={(e) => { setFTo(e.target.value); resetToFirstPage(); }} />
                                </div>
                                <div className="form-check mb-1 align-self-center" style={{ flex: "0 0 auto" }}>
                                    <input className="form-check-input" type="checkbox" id="inc-arch" checked={includeArchived}
                                        onChange={(e) => { setIncludeArchived(e.target.checked); resetToFirstPage(); }} />
                                    <label className="form-check-label fs-12" htmlFor="inc-arch">Incluir arquivadas</label>
                                </div>
                            </RestFilterBar>
                        }
                    >
                        <DataTable
                            columns={cols}
                            data={expenses}
                            rowKey={(e) => e.id}
                            mode="server"
                            loading={loading}
                            caption="Despesas"
                            rowClassName={(e) => (e.archived ? "text-muted" : undefined)}
                            empty={{ message: "Sem despesas para os filtros escolhidos." }}
                            rowActions={rowActions}
                            server={{
                                page: meta?.current_page ?? 1,
                                lastPage: meta?.last_page ?? 1,
                                total: meta?.total ?? 0,
                                perPage: meta?.per_page ?? pagination.pageSize,
                                from: meta?.from ?? 0,
                                to: meta?.to ?? 0,
                                onPageChange: (page) => setPagination((prev) => ({ ...prev, pageIndex: page - 1 })),
                            }}
                        />
                    </PageCard>
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
