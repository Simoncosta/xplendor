// React
import React, { useCallback, useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Button, Container } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
// Components
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import ActionsMenu from "Components/Common/ActionsMenu";
import ExpenseCategoryFormModal from "./components/ExpenseCategoryFormModal";
import ImportSuggestedModal from "./components/ImportSuggestedModal";
// Redux
import {
    getExpenseCategories,
    deleteExpenseCategory,
    updateExpenseCategory,
} from "slices/expense-categories/thunk";
// Models
import { IExpenseCategory } from "common/models/expense-category.model";
// Helpers
import { confirmDelete, alertMessage } from "helpers/swal";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

const selectState = (state: any) => state.ExpenseCategory;

const selectViewModel = createSelector([selectState], (s) => ({
    categories: s.data.categories as IExpenseCategory[],
    loading: s.loading.list,
}));

const ColorDot = ({ color }: { color: string | null }) => (
    <span
        className="d-inline-block rounded-circle align-middle me-2"
        style={{ width: 12, height: 12, backgroundColor: color || "#ced4da" }}
    />
);

const ExpenseCategoryList = () => {
    const dispatch: any = useDispatch();
    document.title = "Categorias de Despesa | Xplendor";

    const { categories, loading } = useSelector(selectViewModel);

    const companyId = useWorkingCompanyId();

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<IExpenseCategory | null>(null);
    const [showArchived, setShowArchived] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [search, setSearch] = useState("");

    const fetchList = useCallback(() => {
        if (!companyId) return;
        dispatch(getExpenseCategories({ companyId }));
    }, [dispatch, companyId]);

    useEffect(() => {
        fetchList();
    }, [fetchList]);

    const active = categories.filter((c) => !c.archived);
    const archived = categories.filter((c) => c.archived);
    const isEmpty = !loading && categories.length === 0;

    const openCreate = () => {
        setEditing(null);
        setFormOpen(true);
    };

    const openEdit = (c: IExpenseCategory) => {
        setEditing(c);
        setFormOpen(true);
    };

    const setArchived = async (c: IExpenseCategory, archivedValue: boolean) => {
        if (!companyId) return;
        try {
            await dispatch(updateExpenseCategory({ companyId, id: c.id, data: { archived: archivedValue } })).unwrap();
            toast.success(archivedValue ? "Categoria arquivada." : "Categoria restaurada.");
            fetchList();
        } catch {
            toast.error("Não foi possível atualizar a categoria.");
        }
    };

    const handleDelete = async (c: IExpenseCategory) => {
        if (!companyId) return;

        // Regra: com despesas associadas não se elimina — arquiva-se.
        if (!c.can_delete) {
            await alertMessage(
                "Esta categoria tem despesas associadas. Arquive-a em vez de a eliminar.",
                "Não é possível eliminar",
                "warning"
            );
            return;
        }

        const confirmed = await confirmDelete(`Vai eliminar a categoria "${c.name}". Esta ação não pode ser anulada.`);
        if (!confirmed) return;

        try {
            await dispatch(deleteExpenseCategory({ companyId, id: c.id })).unwrap();
            toast.success("Categoria eliminada.");
        } catch {
            // Salvaguarda: se o backend bloquear (regra activa na 1c.2b), informa.
            await alertMessage(
                "Esta categoria tem despesas associadas. Arquive-a em vez de a eliminar.",
                "Não é possível eliminar",
                "warning"
            );
            fetchList();
        }
    };

    const cols = useDataColumns<IExpenseCategory>("financas.categorias-despesa", [
        {
            id: "name", header: "Categoria", value: (c) => c.name, hideable: false, mobile: "title",
            cell: (c) => (
                <span className={c.archived ? "" : "fw-medium"}>
                    <ColorDot color={c.color} />
                    {c.name}
                    {c.archived && <span className="badge bg-light text-muted ms-2">Arquivada</span>}
                    {c.locked && <span className="badge bg-primary-subtle text-primary ms-2">Da plataforma</span>}
                </span>
            ),
        },
    ] as DTColumn<IExpenseCategory>[]);

    const rowActions = (c: IExpenseCategory) => c.locked ? (
        <span className="text-muted" title="Categoria das faturas da XPLENDOR: não pode ser alterada nem apagada."><i className="ri-lock-line" /></span>
    ) : (
        <>
            {c.archived ? (
                <Button size="sm" color="outline-primary" onClick={() => setArchived(c, false)} title="Restaurar" aria-label={`Restaurar: ${c.name}`}>
                    <i className="ri-inbox-unarchive-line" />
                </Button>
            ) : (
                <Button size="sm" color="outline-primary" onClick={() => openEdit(c)} title="Editar" aria-label={`Editar: ${c.name}`}>
                    <i className="ri-pencil-line" />
                </Button>
            )}
            <ActionsMenu size="sm" label={`Mais ações: ${c.name}`} items={[
                { label: "Arquivar", icon: "ri-archive-line", hidden: !!c.archived, onClick: () => void setArchived(c, true) },
                { label: "Eliminar", icon: "ri-delete-bin-line", danger: true, onClick: () => void handleDelete(c) },
            ]} />
        </>
    );

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <PageHeader title="Categorias de Despesa" breadcrumbs={[{ label: "Finanças" }]}
                        info="Classificam as despesas de viatura (para agrupar nos gráficos)." />

                    {/* Estado vazio — importar sugeridas ou criar do zero. */}
                    {isEmpty ? (
                        <PageCard title="Categorias" flush={false}>
                            <div className="text-center py-4">
                                <div className="mb-3">
                                    <i className="ri-price-tag-3-line display-5 text-muted" />
                                </div>
                                <h5 className="mb-1">Ainda não tem categorias</h5>
                                <p className="text-muted mb-4">
                                    Importe as 14 categorias sugeridas para começar depressa, ou crie as suas de raiz.
                                </p>
                                <div className="d-flex justify-content-center flex-wrap gap-2">
                                    <Button color="outline-primary" onClick={openCreate}><i className="ri-add-line me-1" />Criar do zero</Button>
                                    <Button color="primary" onClick={() => setImportOpen(true)}><i className="ri-download-2-line me-1" />Importar categorias sugeridas</Button>
                                </div>
                            </div>
                        </PageCard>
                    ) : (
                        <PageCard
                            title="Categorias"
                            status={<>{active.length} ativa{active.length === 1 ? "" : "s"}{archived.length > 0 ? ` · ${archived.length} arquivada${archived.length === 1 ? "" : "s"}` : ""}</>}
                            loading={loading && categories.length > 0}
                            actions={<>
                                <Button size="sm" color="outline-primary" onClick={() => setImportOpen(true)}><i className="ri-download-2-line me-1" />Importar sugeridas</Button>
                                <Button size="sm" color="primary" onClick={openCreate}><i className="ri-add-line me-1" />Nova categoria</Button>
                            </>}
                            filters={
                                <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar categoria…"
                                    activeCount={(search ? 1 : 0) + (showArchived ? 1 : 0)} onClear={() => { setSearch(""); setShowArchived(false); }}>
                                    {archived.length > 0 && (
                                        <div className="form-check mb-1 align-self-center" style={{ flex: "0 0 auto" }}>
                                            <input className="form-check-input" type="checkbox" id="cat-show-archived" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} />
                                            <label className="form-check-label fs-12" htmlFor="cat-show-archived">Mostrar arquivadas ({archived.length})</label>
                                        </div>
                                    )}
                                </RestFilterBar>
                            }
                        >
                            <DataTable
                                columns={cols}
                                data={showArchived ? [...active, ...archived] : active}
                                rowKey={(c) => c.id}
                                loading={loading}
                                search={search}
                                caption="Categorias de despesa"
                                rowClassName={(c) => (c.archived ? "text-muted" : undefined)}
                                empty={{ message: "Sem categorias ativas." }}
                                rowActions={rowActions}
                            />
                        </PageCard>
                    )}
                </Container>
            </div>

            <ExpenseCategoryFormModal
                isOpen={formOpen}
                toggle={() => setFormOpen(false)}
                category={editing}
                companyId={companyId}
                onSaved={fetchList}
            />

            <ImportSuggestedModal
                isOpen={importOpen}
                toggle={() => setImportOpen(false)}
                companyId={companyId}
                existingNames={categories.map((c) => c.name)}
                onImported={fetchList}
            />
        </React.Fragment>
    );
};

export default ExpenseCategoryList;
