import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useLocation } from "react-router-dom";
import { Card, CardBody, Container, Spinner, Table, Alert, Badge, Button } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import BreadCrumb from "Components/Common/BreadCrumb";
import { getAdminCompanyUsers } from "helpers/laravel_helper";
import { startImpersonationFlow } from "helpers/impersonation";

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
    const isRoot = me?.role === "root" && !me?.impersonating;

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

    const roleBadge = (role: string) => {
        const m = ROLE_META[role] ?? { label: role, color: "secondary" };
        return <Badge color={m.color} className={`bg-${m.color}-subtle text-${m.color}`}>{m.label}</Badge>;
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <BreadCrumb title={companyName || "Utilizadores"} pageTitle="Empresas" pageLink="/root/companies" />

                <Card>
                    <CardBody>
                        <div className="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 className="mb-0">{companyName || `Empresa #${cid}`}</h5>
                                <small className="text-muted">Utilizadores desta empresa</small>
                            </div>
                            <Button color="light" size="sm" onClick={() => navigate("/root/companies")}>
                                <i className="ri-arrow-left-line me-1" />Voltar
                            </Button>
                        </div>

                        {loading ? (
                            <div className="text-center py-5"><Spinner color="primary" /></div>
                        ) : (
                            <div className="table-responsive">
                                <Table className="align-middle table-hover mb-0">
                                    <thead>
                                        <tr className="text-muted fs-12 text-uppercase">
                                            <th>Nome</th><th>Email</th><th>Perfil</th><th className="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {users.length === 0 ? (
                                            <tr><td colSpan={4} className="text-center text-muted py-4">Sem utilizadores.</td></tr>
                                        ) : users.map((u) => {
                                            const canImpersonate = u.role !== "root" && u.id !== me?.id;
                                            return (
                                                <tr key={u.id}>
                                                    <td className="fw-semibold">{u.name}</td>
                                                    <td>{u.email}</td>
                                                    <td>{roleBadge(u.role)}</td>
                                                    <td className="text-end">
                                                        {canImpersonate && (
                                                            <Button color="soft-primary" size="sm" disabled={busy} onClick={() => enterAs(u.id)}>
                                                                <i className="ri-spy-line me-1" />Entrar como
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </Table>
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>
        </div>
    );
}
