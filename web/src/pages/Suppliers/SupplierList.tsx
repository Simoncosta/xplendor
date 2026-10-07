// React
import React, { useCallback, useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Button, Card, CardBody, Container, Table } from "reactstrap";
import { toast } from "react-toastify";
import { ToastContainer } from "react-toastify";
// Components
import Pagination from "Components/Common/Pagination";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import SupplierFormModal from "./components/SupplierFormModal";
import QuickAddSupplierModal from "./components/QuickAddSupplierModal";
// Redux
import { getSuppliers, deleteSupplier, updateSupplier } from "slices/suppliers/thunk";
// Helpers
import { confirmDelete, alertMessage } from "helpers/swal";
// Models
import { ISupplier } from "common/models/supplier.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

const selectSupplierState = (state: any) => state.Supplier;

const selectSupplierListViewModel = createSelector(
    [selectSupplierState],
    (supplierState) => ({
        suppliers: supplierState.data.suppliers as ISupplier[],
        meta: supplierState.data.meta,
        loading: supplierState.loading.list,
    })
);

const SupplierList = () => {
    const dispatch: any = useDispatch();
    document.title = "Fornecedores | Xplendor";

    const { suppliers, meta, loading } = useSelector(selectSupplierListViewModel);

    const companyId = useWorkingCompanyId();

    const [pagination, setPagination] = useState({ pageIndex: 0, pageSize: 10 });

    // Modais
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ISupplier | null>(null);
    const [quickOpen, setQuickOpen] = useState(false);

    const fetchList = useCallback(() => {
        if (!companyId) return;
        dispatch(
            getSuppliers({
                companyId,
                page: pagination.pageIndex + 1,
                perPage: pagination.pageSize,
            })
        );
    }, [dispatch, companyId, pagination.pageIndex, pagination.pageSize]);

    useEffect(() => {
        fetchList();
    }, [fetchList]);

    const openCreate = () => {
        setEditing(null);
        setFormOpen(true);
    };

    const openEdit = (supplier: ISupplier) => {
        setEditing(supplier);
        setFormOpen(true);
    };

    const handleDelete = async (supplier: ISupplier) => {
        if (!companyId) return;

        // Regra 1c.2b: fornecedor com despesas não se elimina — arquiva-se.
        if (supplier.can_delete === false) {
            await alertMessage(
                "Este fornecedor tem despesas associadas. Arquive-o em vez de o eliminar.",
                "Não é possível eliminar",
                "warning"
            );
            return;
        }

        const confirmed = await confirmDelete(
            `Vai eliminar o fornecedor "${supplier.name}". Esta ação não pode ser anulada.`
        );
        if (!confirmed) return;

        try {
            await dispatch(deleteSupplier({ companyId, id: supplier.id })).unwrap();
            toast.success("Fornecedor eliminado.");
            fetchList();
        } catch {
            await alertMessage(
                "Este fornecedor tem despesas associadas. Arquive-o em vez de o eliminar.",
                "Não é possível eliminar",
                "warning"
            );
            fetchList();
        }
    };

    const setArchived = async (supplier: ISupplier, archivedValue: boolean) => {
        if (!companyId) return;
        try {
            await dispatch(updateSupplier({ companyId, id: supplier.id, data: { archived: archivedValue } })).unwrap();
            toast.success(archivedValue ? "Fornecedor arquivado." : "Fornecedor restaurado.");
            fetchList();
        } catch {
            toast.error("Não foi possível atualizar o fornecedor.");
        }
    };

    const locationOf = (s: ISupplier): string =>
        [s.parish_name, s.municipality_name, s.district_name].filter(Boolean).join(", ") || "-";

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <PageHeader title="Fornecedores" breadcrumbs={[{ label: "Finanças" }]}
                        description="Os fornecedores da sua empresa (base para as despesas)."
                        actions={<>
                            <Button color="outline-primary" onClick={() => setQuickOpen(true)}><i className="ri-flashlight-line me-1" />Criação rápida</Button>
                            <Button color="primary" onClick={openCreate}><i className="ri-add-line me-1" />Novo fornecedor</Button>
                        </>} />

                    <Card>
                        <CardBody>
                            <div className="table-responsive">
                                <Table className="align-middle table-nowrap mb-0">
                                    <thead className="table-light">
                                        <tr>
                                            <th>Nome</th>
                                            <th>NIF</th>
                                            <th>Telefone</th>
                                            <th>Email</th>
                                            <th>Localidade</th>
                                            <th className="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {loading && (
                                            <tr>
                                                <td colSpan={6} className="text-center text-muted py-4">A carregar…</td>
                                            </tr>
                                        )}
                                        {!loading && suppliers.length === 0 && (
                                            <tr>
                                                <td colSpan={6} className="text-center text-muted py-4">
                                                    Sem fornecedores. Clique em "Novo fornecedor" para criar o primeiro.
                                                </td>
                                            </tr>
                                        )}
                                        {!loading && suppliers.map((s) => (
                                            <tr key={s.id} className={s.archived ? "text-muted" : ""}>
                                                <td className={s.archived ? "" : "fw-medium"}>
                                                    {s.name}
                                                    {s.archived && <span className="badge bg-light text-muted ms-2">Arquivado</span>}
                                                </td>
                                                <td>{s.nif || "-"}</td>
                                                <td>{s.phone || "-"}</td>
                                                <td>{s.email || "-"}</td>
                                                <td>{locationOf(s)}</td>
                                                <td className="text-end">
                                                    <div className="d-inline-flex gap-1">
                                                        <Button size="sm" color="outline-primary" onClick={() => openEdit(s)} title="Editar" aria-label={`Editar: ${s.name}`}>
                                                            <i className="ri-pencil-line" />
                                                        </Button>
                                                        <ActionsMenu size="sm" label={`Mais ações: ${s.name}`} items={[
                                                            s.archived
                                                                ? { label: "Restaurar", icon: "ri-inbox-unarchive-line", onClick: () => void setArchived(s, false) }
                                                                : { label: "Arquivar", icon: "ri-archive-line", onClick: () => void setArchived(s, true) },
                                                            { label: "Eliminar", icon: "ri-delete-bin-line", danger: true, onClick: () => void handleDelete(s) },
                                                        ]} />
                                                    </div>
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

            <SupplierFormModal
                isOpen={formOpen}
                toggle={() => setFormOpen(false)}
                supplier={editing}
                companyId={companyId}
                onSaved={fetchList}
            />

            <QuickAddSupplierModal
                isOpen={quickOpen}
                toggle={() => setQuickOpen(false)}
                companyId={companyId}
                onCreated={fetchList}
            />
        </React.Fragment>
    );
};

export default SupplierList;
