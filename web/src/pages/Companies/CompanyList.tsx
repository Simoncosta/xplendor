// React
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
// Components
import { Badge, Card, CardBody, CardHeader, Col, Container, Row, Spinner } from 'reactstrap';
import { ToastContainer, toast } from 'react-toastify';
import XTanStackTable from 'Components/Common/XTanStackTable';
import { createSelector } from 'reselect';
// Slices
import { getCompaniesPaginate } from 'slices/companies/thunk';
import { setAdminCompanyStatus } from 'helpers/laravel_helper';
import { confirmAction } from 'helpers/swal';

const selectCompanyState = (state: any) => state.Company;

const selectCompanyListViewModel = createSelector(
    [selectCompanyState],
    (companyState: any) => ({
        companies: companyState.data.companies,
        meta: companyState.data.meta,
        loading: companyState.loading.list,
    })
);

const CompanyList = () => {
    const dispatch: any = useDispatch();
    document.title = "Empresas | Xplendor";

    // Redux direto, sem reselect desnecessário
    const { companies, meta, loading } = useSelector(selectCompanyListViewModel);

    // Paginação controlada no pai (server-side)
    const [pagination, setPagination] = useState({
        pageIndex: 0,
        pageSize: 10,
    });

    const [busyId, setBusyId] = useState<number | null>(null);

    const refetch = useCallback(() => {
        dispatch(
            getCompaniesPaginate({
                page: pagination.pageIndex + 1,
                perPage: pagination.pageSize,
            })
        );
    }, [dispatch, pagination.pageIndex, pagination.pageSize]);

    // Fetch sempre que mudar página ou tamanho
    useEffect(() => { refetch(); }, [refetch]);

    // Ativar/inativar — SEMPRE com confirmação (inativar tira acesso a utilizadores reais).
    const toggleStatus = useCallback(async (company: any) => {
        const active = !!company.is_active;
        const name = company.fiscal_name || `Empresa #${company.id}`;
        const ok = await confirmAction(
            active
                ? {
                    title: `Inativar ${name}?`,
                    text: 'Os utilizadores desta empresa deixam de aceder à plataforma e ela sai das vistas de administração.',
                    confirmText: 'Inativar',
                    icon: 'warning',
                    confirmVariant: 'danger' as const,
                }
                : {
                    title: `Ativar ${name}?`,
                    text: 'A empresa volta a ter acesso à plataforma e reaparece nas vistas de administração.',
                    confirmText: 'Ativar',
                    icon: 'question',
                }
        );
        if (!ok) return;

        setBusyId(company.id);
        try {
            await setAdminCompanyStatus(company.id, !active);
            toast.success(active ? 'Empresa inativada.' : 'Empresa ativada.');
            refetch();
        } catch {
            toast.error('Não foi possível mudar o estado da empresa.');
        } finally {
            setBusyId(null);
        }
    }, [refetch]);

    const columns = useMemo(
        () => [
            {
                header: "Nome",
                cell: (cellProps: any) => {
                    return (
                        <div className="d-flex align-items-center">
                            {
                                cellProps.row.original.logo_path && (
                                    <div className="flex-shrink-0 me-2">
                                        <img src={process.env.REACT_APP_PUBLIC_URL + cellProps.row.original.logo_path} alt="" className="avatar-xs rounded-circle" />
                                    </div>
                                )
                            }
                            {cellProps.row.original.fiscal_name}
                        </div>
                    )
                },
                accessorKey: "fiscal_name",
                enableColumnFilter: false,
            },
            {
                header: "NIF",
                accessorKey: "nipc",
                enableColumnFilter: false,
            },
            {
                header: "Estado",
                enableColumnFilter: false,
                cell: (cellProps: any) => {
                    const active = !!cellProps.row.original.is_active;
                    return (
                        <Badge color={active ? "success" : "danger"}>
                            {active ? "Ativa" : "Inativa"}
                        </Badge>
                    );
                },
            },
            {
                header: "Ação",
                cell: (cellProps: any) => {
                    const c = cellProps.row.original;
                    const active = !!c.is_active;
                    const busy = busyId === c.id;
                    return (
                        <div className="d-flex align-items-center gap-2">
                            <Link to={`/companies/${c.id}`} title="Ver">
                                <i className="ri-eye-line align-bottom"></i>
                            </Link>
                            <button
                                type="button"
                                className={"btn btn-sm " + (active ? "btn-soft-danger" : "btn-soft-success")}
                                disabled={busy}
                                onClick={() => toggleStatus(c)}
                                title={active ? "Inativar empresa" : "Ativar empresa"}
                            >
                                {busy
                                    ? <Spinner size="sm" />
                                    : <><i className={(active ? "ri-forbid-2-line" : "ri-check-line") + " align-bottom me-1"} />{active ? "Inativar" : "Ativar"}</>}
                            </button>
                        </div>
                    );
                }
            },
        ],
        [busyId, toggleStatus]
    );

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <Row>
                        <Col lg={12}>
                            <Card id="companyList">
                                <CardHeader className="border-0">
                                    <div className="d-flex align-items-center">
                                        <h5 className="card-title mb-0 flex-grow-1">Empresas</h5>
                                        <div className="flex-shrink-0">
                                            <div className="d-flex gap-2 flex-wrap">
                                                <Link to="/companies/create" className="btn btn-outline-success">
                                                    <i className="ri-add-line align-bottom"></i>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardBody className="pt-0">
                                    <div>
                                        <XTanStackTable
                                            columns={(columns || [])}
                                            data={(companies || [])}
                                            loading={loading}
                                            pagination={pagination}
                                            onPaginationChange={setPagination}
                                            pageCount={meta?.last_page ?? 0}
                                            total={meta?.total}
                                            SearchPlaceholder='Pesquisar...'
                                            isBordered={true}
                                            theadClass="text-muted table-light"
                                        />
                                        <ToastContainer closeButton={false} limit={1} />
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>
                    </Row>
                </Container>
            </div>
        </React.Fragment >
    )
};

export default CompanyList;
