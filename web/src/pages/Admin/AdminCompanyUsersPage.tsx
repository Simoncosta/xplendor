import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useLocation } from "react-router-dom";
import { Container, Alert, Badge, Button } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { getAdminCompanyUsers } from "helpers/laravel_helper";
import { startImpersonationFlow } from "helpers/impersonation";
import { isPlatformRoot, isRootRole } from "helpers/roles";

/**
 * XPLENDOR — ROOT: utilizadores de UMA empresa (área /admin, root-only). Passo 2: escolher o
 * utilizador e "Entrar como" (impersonation). SÓ em users não-root e não o próprio. O
 * startImpersonationFlow trata do resto (backend seguro + troca de contexto + reload + banner).
 */

type AdminUser = { id: number; name: string; email: string; role: string; avatar: string | null };

const ROLE_META: Record<string, { label: string; color: string }> = {
    root: { label: "Root", color: "danger" },
    user: { label: "Utilizador", color: "secondary" },
    admin: { label: "Admin", color: "primary" },
};

export default function AdminCompanyUsersPage() {
    const navigate = useNavigate();
    const location = useLocation();
    const { companyId } = useParams<{ companyId: string }>();
    const cid = Number(companyId);

    document.title = "Utilizadores | Administração | Xplendor";

    const me = useMemo(() => { try { return JSON.parse(sessionStorage.getItem("authUser") || "null"); } catch { return null; } }, []);
    const isRoot = isPlatformRoot(me);

    const [loading, setLoading] = useState(true);
    const [companyName, setCompanyName] = useState<string>((location.state as any)?.name ?? "");
    const [users, setUsers] = useState<AdminUser[]>([]);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getAdminCompanyUsers(cid);
            setUsers(r?.data?.users ?? []);
            if (r?.data?.company?.name) setCompanyName(r.data.company.name);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar os utilizadores.");
        } finally { setLoading(false); }
    }, [cid]);

    useEffect(() => { if (isRoot && cid) load(); else setLoading(false); }, [isRoot, cid, load]);

    const roleMeta = (role: string) => ROLE_META[role] ?? { label: role, color: "secondary" };
    // Os hooks ficam antes do "acesso restrito" (regra dos hooks).
    const cols = useDataColumns<AdminUser>("administracao.root-utilizadores", [
        { id: "name", header: "Nome", value: (u) => u.name, hideable: false, mobile: "title", cell: (u) => <span className="fw-semibold">{u.name}</span> },
        { id: "email", header: "Email", value: (u) => u.email },
        { id: "role", header: "Perfil", value: (u) => roleMeta(u.role).label, cell: (u) => { const m = roleMeta(u.role); return <Badge color={m.color} className={`bg-${m.color}-subtle text-${m.color}`}>{m.label}</Badge>; } },
    ] as DTColumn<AdminUser>[]);

    const enterAs = async (id: number) => {
        setBusy(true);
        try { await startImpersonationFlow(id); } // recarrega a app na identidade do alvo
        catch (e: any) { toast.error(e?.message ?? "Não foi possível iniciar a impersonation."); setBusy(false); }
    };

    if (!isRoot) {
        return (
            <div className="page-content"><Container fluid>
                <Alert color="danger" className="mb-0">Acesso restrito ao administrador da plataforma.</Alert>
            </Container></div>
        );
    }

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title={companyName || `Empresa #${cid}`} crumbLabel="Utilizadores"
                    breadcrumbs={[{ label: "Administração", to: "/admin" }, { label: "Empresas", to: "/root/companies" }]}
                    info="Utilizadores desta empresa." />

                <PageCard
                    title="Utilizadores"
                    status={!loading ? <>{users.length} utilizador{users.length === 1 ? "" : "es"}</> : undefined}
                    actions={<>
                        {cols.selector}
                        <Button size="sm" color="outline-primary" onClick={() => navigate("/root/companies")}><i className="ri-arrow-left-line me-1" />Voltar</Button>
                    </>}
                >
                    <DataTable
                        columns={cols}
                        data={users}
                        rowKey={(u) => u.id}
                        loading={loading}
                        caption="Utilizadores da empresa"
                        empty={{ message: "Sem utilizadores." }}
                        rowActions={(u) => (!isRootRole(u.role) && u.id !== me?.id) ? (
                            <Button color="outline-primary" size="sm" disabled={busy} onClick={() => enterAs(u.id)}>
                                <i className="ri-spy-line me-1" />Entrar como
                            </Button>
                        ) : null}
                    />
                </PageCard>
            </Container>
        </div>
    );
}
