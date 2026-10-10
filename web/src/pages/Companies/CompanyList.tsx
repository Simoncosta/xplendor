// React
import React, { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
// Components
import { Badge, Button, Container, Spinner } from 'reactstrap';
import { ToastContainer, toast } from 'react-toastify';
import PageHeader from 'Components/Common/PageHeader';
import PageCard from 'Components/Common/PageCard';
import DataTable, { DTColumn, useDataColumns } from 'Components/Common/DataTable';
import RestFilterBar from 'Components/Common/RestFilterBar';
import ActionsMenu from 'Components/Common/ActionsMenu';
import CompanyUsersModal from './components/CompanyUsersModal';
import CompanyEditModal from './components/CompanyEditModal';
import CompanyRequestsModal from './components/CompanyRequestsModal';
import ManagementRequestsAdminModal from './components/ManagementRequestsAdminModal';
import { fetchAllPages } from 'helpers/fetchAllPages';
import { getCompaniesPaginate, getAdminAgencies, getAdminCompanyRequests, setAdminCompanyStatus } from 'helpers/laravel_helper';
import { confirmAction } from 'helpers/swal';

const CompanyList = () => {
    document.title = "Empresas | Xplendor";

    // UI-2d: todas as empresas (a API pagina mas não ordena); o DataTable ordena, pesquisa e
    // pagina no browser. Ordenar passou a funcionar (antes as setas não faziam nada).
    const [companies, setCompanies] = useState<any[]>([]);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState("");

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
    // Paga quem dá o acesso: quantas empresas contam para cada agência (mês atual e seguinte).
    const [agencyBilling, setAgencyBilling] = useState<Record<number, { current: number; next: number }>>({});
    useEffect(() => {
        getAdminAgencies().then((r: any) => setAgencyBilling(Object.fromEntries((r?.data?.agencies ?? [])
            .map((a: any) => [a.id, { current: Number(a.billing?.current?.count ?? 0), next: Number(a.billing?.next?.count ?? 0) }])))).catch(() => setAgencyBilling({}));
    }, []);
    // Pedidos de gestão aceites, com a situação de faturação (?gestao=1 vem do sino e do email).
    const [mgmtOpen, setMgmtOpen] = useState(searchParams.get('gestao') === '1');
    const closeMgmt = () => {
        setMgmtOpen(false);
        if (searchParams.has('gestao')) setSearchParams((prev) => { const n = new URLSearchParams(prev); n.delete('gestao'); return n; }, { replace: true });
    };
    const closeRequests = () => {
        setRequestsOpen(false);
        if (searchParams.has('pedidos')) setSearchParams((prev) => { const n = new URLSearchParams(prev); n.delete('pedidos'); return n; }, { replace: true });
    };

    const refetch = useCallback(() => {
        setLoading(true);
        fetchAllPages<any>((page) => getCompaniesPaginate({ page, perPage: 100 }), (r) => r?.data)
            .then(({ rows }) => setCompanies(rows))
            .catch(() => setCompanies([]))
            .finally(() => setLoading(false));
    }, []);

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

    const cols = useDataColumns<any>("administracao.empresas", [
        {
            id: "name", header: "Nome", value: (c) => c.fiscal_name, hideable: false, mobile: "title",
            cell: (c) => (
                <div className="d-flex align-items-center">
                    {c.logo_path && (
                        <div className="flex-shrink-0 me-2">
                            <img src={process.env.REACT_APP_PUBLIC_URL + c.logo_path} alt="" className="avatar-xs rounded-circle" />
                        </div>
                    )}
                    {c.fiscal_name}
                </div>
            ),
        },
        { id: "nif", header: "NIF", value: (c) => c.nipc ?? "" },
        {
            // Gestão por agências: agência, ou a agência gestora.
            id: "agency", header: "Agência",
            value: (c) => (c.agency_enabled_at ? "Agência" : c.active_management?.agency ? (c.active_management.agency.trade_name || c.active_management.agency.fiscal_name) : ""),
            cell: (c) => {
                const managedBy = c.active_management?.agency;
                if (c.agency_enabled_at) {
                    const b = agencyBilling[c.id];
                    return (
                        <span className="d-inline-flex flex-wrap align-items-center gap-2">
                            <Badge color="info" className="fw-normal"><i className="ri-team-line me-1" />Agência</Badge>
                            {b && <span className="fs-12 text-muted" data-testid="agency-billing-count" title="Empresas que contam para a agência (sem subscrição própria)">Contam {b.current} este mês, {b.next} no próximo</span>}
                        </span>
                    );
                }
                if (managedBy) return <span className="fs-13">Gerida por <strong>{managedBy.trade_name || managedBy.fiscal_name}</strong></span>;
                return <span className="text-muted">-</span>;
            },
        },
        {
            id: "status", header: "Estado", value: (c) => (c.is_active ? "Ativa" : "Inativa"),
            cell: (c) => <Badge color={c.is_active ? "success" : "danger"}>{c.is_active ? "Ativa" : "Inativa"}</Badge>,
        },
    ] as DTColumn<any>[]);

    const rowActions = (c: any) => {
        const active = !!c.is_active;
        const busy = busyId === c.id;
        const name = c.fiscal_name || `Empresa #${c.id}`;
        return (
            <>
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
            </>
        );
    };

    return (
        <React.Fragment>
            <div className="page-content">
                <ToastContainer closeButton={false} limit={1} />
                <Container fluid>
                    <PageHeader title="Empresas" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                        info="As empresas da plataforma, as agências, os pedidos de nova empresa gerida e os pedidos de gestão." />
                    <PageCard
                        title="Empresas"
                        status={!loading ? <>{companies.length} empresa{companies.length === 1 ? "" : "s"}</> : undefined}
                        loading={loading && companies.length > 0}
                        actions={<>
                            {cols.selector}
                            <Button size="sm" color="outline-primary" onClick={() => setRequestsOpen(true)} data-testid="company-requests-button">
                                <i className="ri-inbox-line align-bottom me-1" />Pedidos de empresa nova{pendingRequests > 0 ? ` (${pendingRequests})` : ""}
                            </Button>
                            <Button size="sm" color="outline-primary" onClick={() => setMgmtOpen(true)} data-testid="management-requests-button">
                                <i className="ri-links-line align-bottom me-1" />Pedidos de gestão
                            </Button>
                            <Button size="sm" color="primary" onClick={() => setEditing(null)}>
                                <i className="ri-add-line align-bottom me-1" />Nova empresa
                            </Button>
                        </>}
                        filters={<RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar (nome, NIF ou agência)…"
                            activeCount={search ? 1 : 0} onClear={() => setSearch("")} />}
                    >
                        <DataTable
                            columns={cols}
                            data={companies}
                            rowKey={(c) => c.id}
                            loading={loading}
                            search={search}
                            pageSize={10}
                            caption="Empresas"
                            empty={{ message: "Sem empresas." }}
                            rowActions={rowActions}
                        />
                    </PageCard>
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

            <ManagementRequestsAdminModal isOpen={mgmtOpen} onClose={closeMgmt} onChanged={refetch} />

            <CompanyRequestsModal
                isOpen={requestsOpen}
                onClose={closeRequests}
                onDecided={() => { loadPending(); refetch(); }}
            />
        </React.Fragment >
    )
};

export default CompanyList;
