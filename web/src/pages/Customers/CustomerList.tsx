// React
import React, { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
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
    loading: s.loading.list,
}));

const CustomerList = () => {
    const dispatch: any = useDispatch();
    document.title = "Clientes | Xplendor";

    const { customers, loading } = useSelector(selectViewModel);

    const companyId = useWorkingCompanyId();

    const [search, setSearch] = useState("");
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ICustomer | null>(null);
    const [quickOpen, setQuickOpen] = useState(false);

    const fetchList = useCallback(() => {
        if (!companyId) return;
        // UI-2b: todos os clientes (a API ordena por nome mas não por coluna); o DataTable
        // ordena, pesquisa e pagina no browser.
        dispatch(getCustomers({ companyId }));
    }, [dispatch, companyId]);

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

    const cols = useDataColumns<ICustomer>("comercial.clientes", [
        {
            id: "name", header: "Nome", value: (c) => c.name, mobile: "title",
            cell: (c) => <span className={c.archived ? "" : "fw-medium"}>{c.name}{c.archived && <span className="badge bg-light text-muted ms-2">Arquivado</span>}</span>,
        },
        { id: "nif", header: "NIF", value: (c) => c.nif || "", cell: (c) => c.nif || "-" },
        { id: "phone", header: "Telefone", value: (c) => c.phone || "", cell: (c) => c.phone || "-", nowrap: true },
        { id: "email", header: "Email", value: (c) => c.email || "", cell: (c) => c.email || "-" },
        { id: "location", header: "Localidade", value: (c) => locationOf(c) },
    ] as DTColumn<ICustomer>[]);

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <PageHeader title="Clientes" breadcrumbs={[{ label: "Comercial" }]}
                        info="Os clientes da sua empresa (base para os documentos de venda)." />

                    <PageCard
                        title="Clientes"
                        status={!loading ? <>{customers.length} cliente{customers.length === 1 ? "" : "s"}</> : undefined}
                        loading={loading && customers.length > 0}
                        actions={<>
                            {cols.selector}
                            <Button size="sm" color="outline-primary" onClick={() => setQuickOpen(true)}><i className="ri-flashlight-line me-1" />Criação rápida</Button>
                            <Button size="sm" color="primary" onClick={openCreate}><i className="ri-add-line me-1" />Novo cliente</Button>
                        </>}
                        filters={<RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar (nome, NIF, telefone ou email)…"
                            activeCount={search ? 1 : 0} onClear={() => setSearch("")} />}
                    >
                        <DataTable
                            columns={cols}
                            data={customers}
                            rowKey={(c) => c.id}
                            loading={loading}
                            search={search}
                            pageSize={10}
                            caption="Clientes"
                            rowClassName={(c) => (c.archived ? "text-muted" : undefined)}
                            empty={{
                                message: "Sem clientes.",
                                action: <Button size="sm" color="outline-primary" onClick={openCreate}><i className="ri-add-line me-1" />Novo cliente</Button>,
                            }}
                            rowActions={(c) => (
                                <>
                                    <Link to={`/customers/${c.id}`} className="btn btn-sm btn-outline-primary" title="Ver ficha" aria-label={`Ver ficha: ${c.name}`}><i className="ri-user-line" /></Link>
                                    <Button size="sm" color="outline-primary" onClick={() => openEdit(c)} title="Editar" aria-label={`Editar: ${c.name}`}><i className="ri-pencil-line" /></Button>
                                    <ActionsMenu size="sm" label={`Mais ações: ${c.name}`} items={[
                                        c.archived
                                            ? { label: "Restaurar", icon: "ri-inbox-unarchive-line", onClick: () => void setArchived(c, false) }
                                            : { label: "Arquivar", icon: "ri-archive-line", onClick: () => void setArchived(c, true) },
                                        { label: "Eliminar", icon: "ri-delete-bin-line", danger: true, onClick: () => void handleDelete(c) },
                                    ]} />
                                </>
                            )}
                        />
                    </PageCard>
                </Container>
            </div>

            <CustomerFormModal isOpen={formOpen} toggle={() => setFormOpen(false)} customer={editing} companyId={companyId} onSaved={fetchList} />
            <QuickAddCustomerModal isOpen={quickOpen} toggle={() => setQuickOpen(false)} companyId={companyId} onCreated={fetchList} />
        </React.Fragment>
    );
};

export default CustomerList;
