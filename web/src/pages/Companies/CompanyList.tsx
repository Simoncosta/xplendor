// React
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
// Components
import { Badge, Button, Card, CardBody, Col, Container, Row, Spinner } from 'reactstrap';
import { ToastContainer, toast } from 'react-toastify';
import XTanStackTable from 'Components/Common/XTanStackTable';
import PageHeader from 'Components/Common/PageHeader';
import ActionsMenu from 'Components/Common/ActionsMenu';
import CompanyUsersModal from './components/CompanyUsersModal';
import CompanyEditModal from './components/CompanyEditModal';
import CompanyRequestsModal from './components/CompanyRequestsModal';
import { createSelector } from 'reselect';
// Slices
import { getCompaniesPaginate } from 'slices/companies/thunk';
import { getAdminCompanyRequests, setAdminCompanyStatus } from 'helpers/laravel_helper';
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
    const [usersFor, setUsersFor] = useState<{ id: number; name: string } | null>(null);
    // O modal da empresa: undefined fechado, null criar, número editar.
    const [editing, setEditing] = useState<number | null | undefined>(undefined);
    // Pedidos de nova empresa gerida (?pedidos=1 vem do sino e do email).
    const [searchParams, setSearchParams] = useSearchParams();
    const [requestsOpen, setRequestsOpen] = useState(searchParams.get('pedidos') === '1');
    const [pendingRequests, setPendingRequests] = useState(0);
    const loadPending = useCallback(() => {
        getAdminCompanyRequests('pending').then((r: any) => setPendingRequests(Number(r?.data?.pending_count ?? 0))).catch(() => setPendingRequests(0));
    }, []);
    useEffect(() => { loadPending(); }, [loadPending]);
    const closeRequests = () => {
        setRequestsOpen(false);
        if (searchParams.has('pedidos')) setSearchParams((prev) => { const n = new URLSearchParams(prev); n.delete('pedidos'); return n; }, { replace: true });
    };

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
                // Gestão por agências: agência, ou a agência gestora.
                header: "Agência",
                enableColumnFilter: false,
                cell: (cellProps: any) => {
                    const c = cellProps.row.original;
                    const managedBy = c.active_management?.agency;
                    if (c.agency_enabled_at) return <Badge color="info" className="fw-normal"><i className="ri-team-line me-1" />Agência</Badge>;
                    if (managedBy) return <span className="fs-13">Gerida por <strong>{managedBy.trade_name || managedBy.fiscal_name}</strong></span>;
                    return <span className="text-muted">-</span>;
                },
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
                    const name = c.fiscal_name || `Empresa #${c.id}`;
                    return (
                        <div className="d-flex align-items-center gap-2">
                            <Button size="sm" color="outline-primary" className="text-nowrap" onClick={() => setEditing(c.id)}>
                                <i className="ri-pencil-line align-bottom me-1" />Editar
                            </Button>
                            <ActionsMenu size="sm" label={`Mais ações: ${name}`} disabled={busy} items={[
                                { label: "Utilizadores", icon: "ri-team-line", onClick: () => setUsersFor({ id: c.id, name }) },
                                { label: "Ver perfil", icon: "ri-eye-line", to: `/companies/${c.id}` },
                                { label: "Ativar", icon: "ri-check-line", onClick: () => toggleStatus(c), hidden: active },
                                { label: "Inativar", icon: "ri-forbid-2-line", danger: true, onClick: () => toggleStatus(c), hidden: !active },
                            ]} />
                            {busy && <Spinner size="sm" />}
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
                    <PageHeader title="Empresas" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                        description="As empresas da plataforma, as agências e os pedidos de nova empresa gerida."
                        actions={<>
                            <Button color="outline-primary" onClick={() => setRequestsOpen(true)} data-testid="company-requests-button">
                                <i className="ri-inbox-line align-bottom me-1" />Pedidos{pendingRequests > 0 ? ` (${pendingRequests})` : ""}
                            </Button>
                            <Button color="primary" onClick={() => setEditing(null)}>
                                <i className="ri-add-line align-bottom me-1" />Nova empresa
                            </Button>
                        </>} />
                    <Row>
                        <Col lg={12}>
                            <Card id="companyList">
                                <CardBody>
                                    <div>
                                        <XTanStackTable
                                            columns={(columns || [])}
                                            data={(companies || [])}
                                            loading={loading}
                                            pagination={pagination}
                                            onPaginationChange={setPagination}
                                            pageCount={meta?.last_page ?? 0}
                                            total={meta?.total}
                                            SearchPlaceholder='Pesquisar'
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

            <CompanyUsersModal
                isOpen={usersFor !== null}
                companyId={usersFor?.id ?? null}
                companyName={usersFor?.name}
                onClose={() => setUsersFor(null)}
            />

            <CompanyEditModal
                isOpen={editing !== undefined}
                companyId={editing ?? null}
                onClose={() => setEditing(undefined)}
                onSaved={refetch}
            />

            <CompanyRequestsModal
                isOpen={requestsOpen}
                onClose={closeRequests}
                onDecided={() => { loadPending(); refetch(); }}
            />
        </React.Fragment >
    )
};

export default CompanyList;
