// React
import React, { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Button, Card, CardBody, Container, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
// Components
import Pagination from "Components/Common/Pagination";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import CustomerFormModal from "./components/CustomerFormModal";
import QuickAddCustomerModal from "./components/QuickAddCustomerModal";
// Redux
import { getCustomers, deleteCustomer, updateCustomer } from "slices/customers/thunk";
// Helpers / models
import { confirmDelete, alertMessage } from "helpers/swal";
import { ICustomer } from "common/models/customer.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

const selectState = (state: any) => state.Customer;
const selectViewModel = createSelector([selectState], (s) => ({
    customers: s.data.customers as ICustomer[],
    meta: s.data.meta,
    loading: s.loading.list,
}));

const CustomerList = () => {
    const dispatch: any = useDispatch();
    document.title = "Clientes | Xplendor";

    const { customers, meta, loading } = useSelector(selectViewModel);

    const companyId = useWorkingCompanyId();

    const [pagination, setPagination] = useState({ pageIndex: 0, pageSize: 10 });
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ICustomer | null>(null);
    const [quickOpen, setQuickOpen] = useState(false);

    const fetchList = useCallback(() => {
        if (!companyId) return;
        dispatch(getCustomers({ companyId, page: pagination.pageIndex + 1, perPage: pagination.pageSize }));
    }, [dispatch, companyId, pagination.pageIndex, pagination.pageSize]);

    useEffect(() => { fetchList(); }, [fetchList]);

    const openCreate = () => { setEditing(null); setFormOpen(true); };
    const openEdit = (c: ICustomer) => { setEditing(c); setFormOpen(true); };

    const handleDelete = async (c: ICustomer) => {
        if (!companyId) return;
        if (c.can_delete === false) {
            await alertMessage("Este cliente tem vendas associadas. Arquive-o em vez de o eliminar.", "Não é possível eliminar", "warning");
            return;
        }
        const ok = await confirmDelete(`Vai eliminar o cliente "${c.name}". Esta ação não pode ser anulada.`);
        if (!ok) return;
        try {
            await dispatch(deleteCustomer({ companyId, id: c.id })).unwrap();
            toast.success("Cliente eliminado.");
            fetchList();
        } catch {
            await alertMessage("Este cliente tem vendas associadas. Arquive-o em vez de o eliminar.", "Não é possível eliminar", "warning");
            fetchList();
        }
    };

    const setArchived = async (c: ICustomer, archivedValue: boolean) => {
        if (!companyId) return;
        try {
            await dispatch(updateCustomer({ companyId, id: c.id, data: { archived: archivedValue } })).unwrap();
            toast.success(archivedValue ? "Cliente arquivado." : "Cliente restaurado.");
            fetchList();
        } catch {
            toast.error("Não foi possível atualizar o cliente.");
        }
    };

    const locationOf = (c: ICustomer): string =>
        [c.parish_name, c.municipality_name, c.district_name].filter(Boolean).join(", ") || "-";

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <PageHeader title="Clientes" breadcrumbs={[{ label: "Comercial" }]}
                        description="Os clientes da sua empresa (base para os documentos de venda)."
                        actions={<>
                            <Button color="outline-primary" onClick={() => setQuickOpen(true)}><i className="ri-flashlight-line me-1" />Criação rápida</Button>
                            <Button color="primary" onClick={openCreate}><i className="ri-add-line me-1" />Novo cliente</Button>
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
                                        {loading && <tr><td colSpan={6} className="text-center text-muted py-4">A carregar…</td></tr>}
                                        {!loading && customers.length === 0 && (
                                            <tr><td colSpan={6} className="text-center text-muted py-4">Sem clientes. Clique em "Novo cliente" para criar o primeiro.</td></tr>
                                        )}
                                        {!loading && customers.map((c) => (
                                            <tr key={c.id} className={c.archived ? "text-muted" : ""}>
                                                <td className={c.archived ? "" : "fw-medium"}>
                                                    {c.name}
                                                    {c.archived && <span className="badge bg-light text-muted ms-2">Arquivado</span>}
                                                </td>
                                                <td>{c.nif || "-"}</td>
                                                <td>{c.phone || "-"}</td>
                                                <td>{c.email || "-"}</td>
                                                <td>{locationOf(c)}</td>
                                                <td className="text-end">
                                                    <div className="d-inline-flex gap-1">
                                                        <Link to={`/customers/${c.id}`} className="btn btn-sm btn-outline-primary" title="Ver ficha" aria-label={`Ver ficha: ${c.name}`}><i className="ri-user-line" /></Link>
                                                        <Button size="sm" color="outline-primary" onClick={() => openEdit(c)} title="Editar" aria-label={`Editar: ${c.name}`}><i className="ri-pencil-line" /></Button>
                                                        <ActionsMenu size="sm" label={`Mais ações: ${c.name}`} items={[
                                                            c.archived
                                                                ? { label: "Restaurar", icon: "ri-inbox-unarchive-line", onClick: () => void setArchived(c, false) }
                                                                : { label: "Arquivar", icon: "ri-archive-line", onClick: () => void setArchived(c, true) },
                                                            { label: "Eliminar", icon: "ri-delete-bin-line", danger: true, onClick: () => void handleDelete(c) },
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

            <CustomerFormModal isOpen={formOpen} toggle={() => setFormOpen(false)} customer={editing} companyId={companyId} onSaved={fetchList} />
            <QuickAddCustomerModal isOpen={quickOpen} toggle={() => setQuickOpen(false)} companyId={companyId} onCreated={fetchList} />
        </React.Fragment>
    );
};

export default CustomerList;
