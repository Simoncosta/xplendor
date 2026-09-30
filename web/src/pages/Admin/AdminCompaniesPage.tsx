import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Card, CardBody, Container, Spinner, Table, Alert, Badge } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import BreadCrumb from "Components/Common/BreadCrumb";
import { getAdminCompanies } from "helpers/laravel_helper";

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
        try { return JSON.parse(sessionStorage.getItem("authUser") || "null")?.role === "root"; } catch { return false; }
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

    if (!isRoot) {
        return (
            <div className="page-content"><Container fluid>
                <Alert color="danger" className="mb-0">Acesso restrito ao administrador da plataforma.</Alert>
            </Container></div>
        );
    }

    const statusBadge = (c: Company) => {
        const m = STATUS_META[c.subscription_status] ?? { label: c.subscription_status, color: "secondary" };
        return <Badge color={m.color} className={`bg-${m.color}-subtle text-${m.color}`}>{m.label}</Badge>;
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <BreadCrumb title="Empresas" pageTitle="Administração" />
                <Card>
                    <CardBody>
                        <p className="text-muted fs-13 mb-3">Escolhe uma empresa para ver os utilizadores e, se precisares, entrar como um deles.</p>
                        {loading ? (
                            <div className="text-center py-5"><Spinner color="primary" /></div>
                        ) : (
                            <div className="table-responsive">
                                <Table className="align-middle table-hover mb-0">
                                    <thead>
                                        <tr className="text-muted fs-12 text-uppercase">
                                            <th>Empresa</th><th>Plano</th><th>Estado</th><th className="text-end">Utilizadores</th><th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {companies.length === 0 ? (
                                            <tr><td colSpan={5} className="text-center text-muted py-4">Sem empresas.</td></tr>
                                        ) : companies.map((c) => (
                                            <tr key={c.id} style={{ cursor: "pointer" }} onClick={() => navigate(`/root/companies/${c.id}/users`, { state: { name: c.name } })}>
                                                <td className="fw-semibold">{c.name}</td>
                                                <td>{c.plan ?? "—"}</td>
                                                <td>{statusBadge(c)}</td>
                                                <td className="text-end">{c.users_count}</td>
                                                <td className="text-end"><i className="ri-arrow-right-s-line fs-4 text-muted" /></td>
                                            </tr>
                                        ))}
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
