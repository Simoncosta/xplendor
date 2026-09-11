// React
import React, { useCallback, useEffect, useMemo, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Card, CardBody, Col, Container, Row, Table } from "reactstrap";
import { toast } from "react-toastify";
import { ToastContainer } from "react-toastify";
// Components
import Pagination from "Components/Common/Pagination";
import XButton from "Components/Common/XButton";
import SupplierFormModal from "./components/SupplierFormModal";
import QuickAddSupplierModal from "./components/QuickAddSupplierModal";
// Redux
import { getSuppliers, deleteSupplier, updateSupplier } from "slices/suppliers/thunk";
// Helpers
import { confirmDelete, alertMessage } from "helpers/swal";
// Models
import { ISupplier } from "common/models/supplier.model";

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

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        return Number(JSON.parse(authUser).company_id || 0);
    }, []);

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
            `Vais eliminar o fornecedor "${supplier.name}". Esta ação não pode ser anulada.`
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
        [s.parish_name, s.municipality_name, s.district_name].filter(Boolean).join(", ") || "—";

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer />
                <Container fluid>
                    <Row className="g-2 mb-3 align-items-center">
                        <Col>
                            <h5 className="mb-0">Fornecedores</h5>
                            <small className="text-muted">Gere os fornecedores da sua empresa (base para as despesas).</small>
                        </Col>
                        <Col xs="auto" className="d-flex gap-2">
                            <XButton variant="secondary" outline type="button" icon={<i className="ri-flashlight-line" />} onClick={() => setQuickOpen(true)}>
                                Criação rápida
                            </XButton>
                            <XButton variant="success" type="button" icon={<i className="ri-add-line" />} onClick={openCreate}>
                                Adicionar fornecedor
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
                                        {loading && (
                                            <tr>
                                                <td colSpan={6} className="text-center text-muted py-4">A carregar…</td>
                                            </tr>
                                        )}
                                        {!loading && suppliers.length === 0 && (
                                            <tr>
                                                <td colSpan={6} className="text-center text-muted py-4">
                                                    Sem fornecedores. Clica em "Adicionar fornecedor" para criar o primeiro.
                                                </td>
                                            </tr>
                                        )}
                                        {!loading && suppliers.map((s) => (
                                            <tr key={s.id} className={s.archived ? "text-muted" : ""}>
                                                <td className={s.archived ? "" : "fw-medium"}>
                                                    {s.name}
                                                    {s.archived && <span className="badge bg-light text-muted ms-2">Arquivado</span>}
                                                </td>
                                                <td>{s.nif || "—"}</td>
                                                <td>{s.phone || "—"}</td>
                                                <td>{s.email || "—"}</td>
                                                <td>{locationOf(s)}</td>
                                                <td className="text-end">
                                                    <button className="btn btn-sm btn-soft-primary me-1" onClick={() => openEdit(s)} title="Editar">
                                                        <i className="ri-pencil-line" />
                                                    </button>
                                                    {s.archived ? (
                                                        <button className="btn btn-sm btn-soft-success me-1" onClick={() => setArchived(s, false)} title="Restaurar">
                                                            <i className="ri-inbox-unarchive-line" />
                                                        </button>
                                                    ) : (
                                                        <button className="btn btn-sm btn-soft-secondary me-1" onClick={() => setArchived(s, true)} title="Arquivar">
                                                            <i className="ri-archive-line" />
                                                        </button>
                                                    )}
                                                    <button className="btn btn-sm btn-soft-danger" onClick={() => handleDelete(s)} title="Eliminar">
                                                        <i className="ri-delete-bin-line" />
                                                    </button>
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
