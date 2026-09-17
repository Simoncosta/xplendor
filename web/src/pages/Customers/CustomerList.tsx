// React
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Card, CardBody, Col, Container, Row, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
// Components
import Pagination from "Components/Common/Pagination";
import XButton from "Components/Common/XButton";
import CustomerFormModal from "./components/CustomerFormModal";
import QuickAddCustomerModal from "./components/QuickAddCustomerModal";
// Redux
import { getCustomers, deleteCustomer, updateCustomer } from "slices/customers/thunk";
// Helpers / models
import { confirmDelete, alertMessage } from "helpers/swal";
import { ICustomer } from "common/models/customer.model";

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

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        return Number(JSON.parse(authUser).company_id || 0);
    }, []);

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
        const ok = await confirmDelete(`Vais eliminar o cliente "${c.name}". Esta ação não pode ser anulada.`);
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
        [c.parish_name, c.municipality_name, c.district_name].filter(Boolean).join(", ") || "—";

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <Row className="g-2 mb-3 align-items-center">
                        <Col>
                            <h5 className="mb-0">Clientes</h5>
                            <small className="text-muted">Gere os clientes da sua empresa (base para os documentos de venda).</small>
                        </Col>
                        <Col xs="auto" className="d-flex gap-2">
                            <XButton variant="secondary" outline type="button" icon={<i className="ri-flashlight-line" />} onClick={() => setQuickOpen(true)}>
                                Criação rápida
                            </XButton>
                            <XButton variant="success" type="button" icon={<i className="ri-add-line" />} onClick={openCreate}>
                                Adicionar cliente
                            </XButton>
                        </Col>
                    </Row>

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
                                            <tr><td colSpan={6} className="text-center text-muted py-4">Sem clientes. Clica em "Adicionar cliente" para criar o primeiro.</td></tr>
                                        )}
                                        {!loading && customers.map((c) => (
                                            <tr key={c.id} className={c.archived ? "text-muted" : ""}>
                                                <td className={c.archived ? "" : "fw-medium"}>
                                                    {c.name}
                                                    {c.archived && <span className="badge bg-light text-muted ms-2">Arquivado</span>}
                                                </td>
                                                <td>{c.nif || "—"}</td>
                                                <td>{c.phone || "—"}</td>
                                                <td>{c.email || "—"}</td>
                                                <td>{locationOf(c)}</td>
                                                <td className="text-end">
                                                    <Link to={`/customers/${c.id}`} className="btn btn-sm btn-soft-info me-1" title="Ver ficha"><i className="ri-user-line" /></Link>
                                                    <button className="btn btn-sm btn-soft-primary me-1" onClick={() => openEdit(c)} title="Editar"><i className="ri-pencil-line" /></button>
                                                    {c.archived ? (
                                                        <button className="btn btn-sm btn-soft-success me-1" onClick={() => setArchived(c, false)} title="Restaurar"><i className="ri-inbox-unarchive-line" /></button>
                                                    ) : (
                                                        <button className="btn btn-sm btn-soft-secondary me-1" onClick={() => setArchived(c, true)} title="Arquivar"><i className="ri-archive-line" /></button>
                                                    )}
                                                    <button className="btn btn-sm btn-soft-danger" onClick={() => handleDelete(c)} title="Eliminar"><i className="ri-delete-bin-line" /></button>
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
