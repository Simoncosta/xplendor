// React
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Card, CardBody, Col, Container, Row, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
// Components
import XButton from "Components/Common/XButton";
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

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        return Number(JSON.parse(authUser).company_id || 0);
    }, []);

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<IExpenseCategory | null>(null);
    const [showArchived, setShowArchived] = useState(false);
    const [importOpen, setImportOpen] = useState(false);

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

        const confirmed = await confirmDelete(`Vais eliminar a categoria "${c.name}". Esta ação não pode ser anulada.`);
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

    const renderRow = (c: IExpenseCategory, isArchivedRow: boolean) => (
        <tr key={c.id} className={isArchivedRow ? "text-muted" : ""}>
            <td className={isArchivedRow ? "" : "fw-medium"}>
                <ColorDot color={c.color} />
                {c.name}
                {isArchivedRow && <span className="badge bg-light text-muted ms-2">Arquivada</span>}
            </td>
            <td className="text-end">
                {!isArchivedRow ? (
                    <>
                        <button className="btn btn-sm btn-soft-primary me-1" onClick={() => openEdit(c)} title="Editar">
                            <i className="ri-pencil-line" />
                        </button>
                        <button className="btn btn-sm btn-soft-secondary me-1" onClick={() => setArchived(c, true)} title="Arquivar">
                            <i className="ri-archive-line" />
                        </button>
                        <button className="btn btn-sm btn-soft-danger" onClick={() => handleDelete(c)} title="Eliminar">
                            <i className="ri-delete-bin-line" />
                        </button>
                    </>
                ) : (
                    <>
                        <button className="btn btn-sm btn-soft-success me-1" onClick={() => setArchived(c, false)} title="Restaurar">
                            <i className="ri-inbox-unarchive-line" />
                        </button>
                        <button className="btn btn-sm btn-soft-danger" onClick={() => handleDelete(c)} title="Eliminar">
                            <i className="ri-delete-bin-line" />
                        </button>
                    </>
                )}
            </td>
        </tr>
    );

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <Row className="g-2 mb-3 align-items-center">
                        <Col>
                            <h5 className="mb-0">Categorias de Despesa</h5>
                            <small className="text-muted">Classificam as despesas de viatura (para agrupar nos gráficos).</small>
                        </Col>
                        {!isEmpty && (
                            <Col xs="auto" className="d-flex gap-2">
                                <XButton variant="secondary" outline type="button" icon={<i className="ri-download-2-line" />} onClick={() => setImportOpen(true)}>
                                    Importar sugeridas
                                </XButton>
                                <XButton variant="success" type="button" icon={<i className="ri-add-line" />} onClick={openCreate}>
                                    Nova categoria
                                </XButton>
                            </Col>
                        )}
                    </Row>

                    {/* Estado vazio — importar sugeridas ou criar do zero. */}
                    {isEmpty && (
                        <Card>
                            <CardBody className="text-center py-5">
                                <div className="mb-3">
                                    <i className="ri-price-tag-3-line display-5 text-muted" />
                                </div>
                                <h5 className="mb-1">Ainda não tens categorias</h5>
                                <p className="text-muted mb-4">
                                    Importa as 14 categorias sugeridas para começar depressa, ou cria as tuas de raiz.
                                </p>
                                <div className="d-flex justify-content-center gap-2">
                                    <XButton variant="success" type="button" icon={<i className="ri-download-2-line" />} onClick={() => setImportOpen(true)}>
                                        Importar categorias sugeridas
                                    </XButton>
                                    <XButton variant="light" type="button" icon={<i className="ri-add-line" />} onClick={openCreate}>
                                        Criar do zero
                                    </XButton>
                                </div>
                            </CardBody>
                        </Card>
                    )}

                    {/* Lista de activas. */}
                    {!isEmpty && (
                        <Card>
                            <CardBody>
                                <div className="table-responsive">
                                    <Table className="align-middle table-nowrap mb-0">
                                        <thead className="table-light">
                                            <tr>
                                                <th>Categoria</th>
                                                <th className="text-end">Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {loading && (
                                                <tr><td colSpan={2} className="text-center text-muted py-4">A carregar…</td></tr>
                                            )}
                                            {!loading && active.length === 0 && (
                                                <tr><td colSpan={2} className="text-center text-muted py-4">Sem categorias activas.</td></tr>
                                            )}
                                            {!loading && active.map((c) => renderRow(c, false))}
                                        </tbody>
                                    </Table>
                                </div>

                                {archived.length > 0 && (
                                    <div className="mt-3">
                                        <button
                                            className="btn btn-sm btn-link text-decoration-none px-0"
                                            onClick={() => setShowArchived((v) => !v)}
                                        >
                                            <i className={`ri-arrow-${showArchived ? "down" : "right"}-s-line align-middle`} />
                                            {showArchived ? "Ocultar" : "Mostrar"} arquivadas ({archived.length})
                                        </button>

                                        {showArchived && (
                                            <div className="table-responsive mt-2">
                                                <Table className="align-middle table-nowrap mb-0">
                                                    <tbody>
                                                        {archived.map((c) => renderRow(c, true))}
                                                    </tbody>
                                                </Table>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </CardBody>
                        </Card>
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
