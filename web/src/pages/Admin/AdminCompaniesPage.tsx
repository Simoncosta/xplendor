import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Container, Alert, Badge } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import { getAdminCompanies } from "helpers/laravel_helper";
import { isRootRole, sessionUser } from "helpers/roles";

/**
 * XPLENDOR — ROOT: lista de TODAS as empresas (área /admin, root-only). Passo 1 da
 * impersonation: escolher a empresa → ver os seus utilizadores → "entrar como". A segurança
 * real é o backend (ensure_super_admin); aqui só mostramos/escondemos.
 */

type Company = {
    id: number; name: string; plan: string | null;
    subscription_status: string; has_access: boolean; trial_ends_at: string | null; users_count: number;
};

const STATUS_META: Record<string, { label: string; color: string }> = {
    active: { label: "Ativa", color: "success" },
    trial: { label: "Trial", color: "info" },
    expired: { label: "Expirada", color: "danger" },
    cancelled: { label: "Inativa", color: "secondary" },
};

export default function AdminCompaniesPage() {
    document.title = "Empresas | Administração | Xplendor";
    const navigate = useNavigate();

    const isRoot = useMemo(() => {
        return isRootRole(sessionUser()?.role);
    }, []);

    const [loading, setLoading] = useState(true);
    const [companies, setCompanies] = useState<Company[]>([]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getAdminCompanies();
            setCompanies(r?.data?.companies ?? []);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar as empresas.");
        } finally { setLoading(false); }
    }, []);

    useEffect(() => { if (isRoot) load(); else setLoading(false); }, [isRoot, load]);

    const [search, setSearch] = useState("");
    const statusMeta = (c: Company) => STATUS_META[c.subscription_status] ?? { label: c.subscription_status, color: "secondary" };
    // Os hooks ficam antes do "acesso restrito" (regra dos hooks).
    const cols = useDataColumns<Company>("administracao.root-empresas", [
        { id: "name", header: "Empresa", value: (c) => c.name, hideable: false, mobile: "title", cell: (c) => <span className="fw-semibold">{c.name}</span> },
        { id: "plan", header: "Plano", value: (c) => c.plan ?? "", cell: (c) => c.plan ?? <span className="text-muted">Sem plano</span> },
        { id: "status", header: "Estado", value: (c) => statusMeta(c).label, cell: (c) => { const m = statusMeta(c); return <Badge color={m.color} className={`bg-${m.color}-subtle text-${m.color}`}>{m.label}</Badge>; } },
        { id: "users", header: "Utilizadores", value: (c) => c.users_count, align: "end" },
    ] as DTColumn<Company>[]);

    if (!isRoot) {
        return (
            <div className="page-content"><Container fluid>
                <Alert color="danger" className="mb-0">Acesso restrito ao administrador da plataforma.</Alert>
            </Container></div>
        );
    }

    const open = (c: Company) => navigate(`/root/companies/${c.id}/users`, { state: { name: c.name } });

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Empresas" breadcrumbs={[{ label: "Administração", to: "/admin" }]}
                    info="Escolha uma empresa para ver os utilizadores e, se precisar, entrar como um deles." />
                <PageCard
                    title="Empresas"
                    status={!loading ? <>{companies.length} empresa{companies.length === 1 ? "" : "s"}</> : undefined}
                    actions={cols.selector}
                    filters={<RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar empresa…" activeCount={search ? 1 : 0} onClear={() => setSearch("")} />}
                >
                    <DataTable
                        columns={cols}
                        data={companies}
                        rowKey={(c) => c.id}
                        loading={loading}
                        search={search}
                        caption="Empresas da plataforma"
                        onRowClick={open}
                        empty={{ message: "Sem empresas." }}
                        rowActions={(c) => (
                            <button type="button" className="btn btn-outline-primary btn-sm" title="Ver utilizadores" aria-label={`Ver utilizadores: ${c.name}`} onClick={(e) => { e.stopPropagation(); open(c); }}>
                                <i className="ri-arrow-right-s-line" />
                            </button>
                        )}
                    />
                </PageCard>
            </Container>
        </div>
    );
}
