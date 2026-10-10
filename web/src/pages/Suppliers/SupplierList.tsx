// React
import React, { useCallback, useEffect, useState } from "react";
import { useDispatch } from "react-redux";
import { Button, Container } from "reactstrap";
import { toast } from "react-toastify";
import { ToastContainer } from "react-toastify";
// Components
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import ActionsMenu from "Components/Common/ActionsMenu";
import SupplierFormModal from "./components/SupplierFormModal";
import QuickAddSupplierModal from "./components/QuickAddSupplierModal";
// Redux
import { deleteSupplier, updateSupplier } from "slices/suppliers/thunk";
import { getSuppliers as getSuppliersApi } from "helpers/laravel_helper";
// Helpers
import { confirmDelete, alertMessage } from "helpers/swal";
// Models
import { ISupplier } from "common/models/supplier.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";


const SupplierList = () => {
    const dispatch: any = useDispatch();
    document.title = "Fornecedores | Xplendor";

    // UI-2c: todos os fornecedores (sem perPage, a API devolve-os todos, por nome); o DataTable
    // ordena, pesquisa e pagina no browser.
    const [suppliers, setSuppliers] = useState<ISupplier[]>([]);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");

    const companyId = useWorkingCompanyId();


    // Modais
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ISupplier | null>(null);
    const [quickOpen, setQuickOpen] = useState(false);

    const fetchList = useCallback(() => {
        if (!companyId) return;
        setLoading(true);
        getSuppliersApi(companyId)
            .then((r: any) => setSuppliers((r?.data ?? []) as ISupplier[]))
            .catch(() => setSuppliers([]))
            .finally(() => setLoading(false));
    }, [companyId]);

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

    const cols = useDataColumns<ISupplier>("financas.fornecedores", [
        {
            id: "name", header: "Nome", value: (x) => x.name, mobile: "title",
            cell: (x) => <span className={x.archived ? "" : "fw-medium"}>{x.name}{x.archived && <span className="badge bg-light text-muted ms-2">Arquivado</span>}</span>,
        },
        { id: "nif", header: "NIF", value: (x) => x.nif || "", cell: (x) => x.nif || "-" },
        { id: "phone", header: "Telefone", value: (x) => x.phone || "", cell: (x) => x.phone || "-", nowrap: true },
        { id: "email", header: "Email", value: (x) => x.email || "", cell: (x) => x.email || "-" },
        { id: "location", header: "Localidade", value: (x) => locationOf(x) },
    ] as DTColumn<ISupplier>[]);

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <PageHeader title="Fornecedores" breadcrumbs={[{ label: "Finanças" }]}
                        info="Os fornecedores da sua empresa (base para as despesas)." />

                    <PageCard
                        title="Fornecedores"
                        status={!loading ? <>{suppliers.length} fornecedor{suppliers.length === 1 ? "" : "es"}</> : undefined}
                        loading={loading && suppliers.length > 0}
                        actions={<>
                            {cols.selector}
                            <Button size="sm" color="outline-primary" onClick={() => setQuickOpen(true)}><i className="ri-flashlight-line me-1" />Criação rápida</Button>
                            <Button size="sm" color="primary" onClick={openCreate}><i className="ri-add-line me-1" />Novo fornecedor</Button>
                        </>}
                        filters={<RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar (nome, NIF, telefone ou email)…"
                            activeCount={search ? 1 : 0} onClear={() => setSearch("")} />}
                    >
                        <DataTable
                            columns={cols}
                            data={suppliers}
                            rowKey={(x) => x.id}
                            loading={loading}
                            search={search}
                            pageSize={10}
                            caption="Fornecedores"
                            rowClassName={(x) => (x.archived ? "text-muted" : undefined)}
                            empty={{
                                message: "Sem fornecedores.",
                                action: <Button size="sm" color="outline-primary" onClick={openCreate}><i className="ri-add-line me-1" />Novo fornecedor</Button>,
                            }}
                            rowActions={(x) => (
                                <>
                                    <Button size="sm" color="outline-primary" onClick={() => openEdit(x)} title="Editar" aria-label={`Editar: ${x.name}`}>
                                        <i className="ri-pencil-line" />
                                    </Button>
                                    <ActionsMenu size="sm" label={`Mais ações: ${x.name}`} items={[
                                        x.archived
                                            ? { label: "Restaurar", icon: "ri-inbox-unarchive-line", onClick: () => void setArchived(x, false) }
                                            : { label: "Arquivar", icon: "ri-archive-line", onClick: () => void setArchived(x, true) },
                                        { label: "Eliminar", icon: "ri-delete-bin-line", danger: true, onClick: () => void handleDelete(x) },
                                    ]} />
                                </>
                            )}
                        />
                    </PageCard>
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
