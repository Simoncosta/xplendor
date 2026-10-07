import { useCallback, useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Card, CardBody, Container, Spinner } from "reactstrap";
import { ToastContainer } from "react-toastify";
import { getCompanyManagement } from "helpers/laravel_helper";
import ManagementRequestsCard from "pages/Companies/CompanyProfile/components/ManagementRequestsCard";
import logoDark from "assets/images/logo-dark.png";
import logoLight from "assets/images/logo-light.png";

/**
 * A página do pedido de gestão (ligação dos avisos e do email). Fora do resto da app, para
 * funcionar também numa empresa SEM acesso à plataforma (sem nenhum pedido que falhe): só o
 * pedido, com a agência, a mensagem, o que pode e não pode fazer, Aceitar e Recusar. Com
 * acesso, abre o perfil da empresa no cartão dos pedidos.
 */
type State =
    | { kind: "loading" }
    | { kind: "forbidden" }
    | { kind: "ready"; name: string; pending: number }
    | { kind: "accepted"; agency: string }
    | { kind: "declined" };

const readAuth = (): any => {
    try { return JSON.parse(sessionStorage.getItem("authUser") || "null"); } catch { return null; }
};

export default function ManagementRequestPage() {
    document.title = "Pedido de gestão | Xplendor";
    const { companyId } = useParams();
    const id = Number(companyId);
    const navigate = useNavigate();
    const [state, setState] = useState<State>({ kind: "loading" });

    const load = useCallback(() => {
        getCompanyManagement(id).then((r: any) => {
            const d = r?.data ?? {};
            if (d.has_platform_access) {
                // Com acesso: a app normal, no cartão dos pedidos do perfil da empresa.
                window.location.replace(`${process.env.PUBLIC_URL ?? ""}/companies/${id}?gestao=1`);
                return;
            }
            setState((prev) => (prev.kind === "accepted" || prev.kind === "declined" ? prev : { kind: "ready", name: d.company_name ?? "", pending: Number(d.pending_requests ?? 0) }));
        }).catch(() => setState({ kind: "forbidden" }));
    }, [id]);

    useEffect(() => {
        if (!readAuth()?.token) {
            try { sessionStorage.setItem("xp-after-login", `/pedido-gestao/${id}`); } catch { /* ignore */ }
            navigate("/login", { replace: true });
            return;
        }
        load();
    }, [id, load, navigate]);

    const openApp = () => window.location.assign(`${process.env.PUBLIC_URL ?? ""}/dashboard`);

    return (
        <div className="auth-page-content py-5" style={{ minHeight: "100vh", background: "var(--vz-body-bg)" }}>
            <ToastContainer />
            <Container style={{ maxWidth: 720 }}>
                <div className="text-center mb-4">
                    <img src={logoDark} alt="XPLENDOR" height="22" className="logo-dark-img" />
                    <img src={logoLight} alt="" height="22" className="logo-light-img" />
                </div>
                {state.kind === "loading" && <div className="text-center py-5"><Spinner color="primary" /></div>}
                {state.kind === "forbidden" && (
                    <Card><CardBody className="text-center">
                        <h4 className="mb-2">Pedido de gestão</h4>
                        <p className="text-muted mb-0">Só os administradores da empresa podem ver e responder a este pedido.</p>
                    </CardBody></Card>
                )}
                {state.kind === "ready" && (
                    <>
                        <h4 className="mb-1" data-testid="page-title">Pedido de gestão{state.name ? `: ${state.name}` : ""}</h4>
                        <div className="alert alert-warning d-flex gap-2 align-items-start mt-3" data-testid="no-access-note">
                            <i className="ri-information-line fs-16" />
                            <span>A sua empresa está sem acesso à plataforma. Se aceitar, o acesso passa a ser assegurado pela agência.</span>
                        </div>
                        {state.pending > 0 ? (
                            <ManagementRequestsCard companyId={id} pending={state.pending} onChanged={() => undefined}
                                onAccepted={(agency) => setState({ kind: "accepted", agency })} onDeclined={() => setState({ kind: "declined" })} />
                        ) : (
                            <Card><CardBody className="text-muted">Não há pedidos de gestão por responder.</CardBody></Card>
                        )}
                    </>
                )}
                {state.kind === "accepted" && (
                    <Card data-testid="request-accepted"><CardBody className="text-center">
                        <i className="ri-checkbox-circle-line text-success fs-1" />
                        <h4 className="mt-2 mb-2">A agência {state.agency} passou a gerir a sua empresa</h4>
                        <p className="text-muted">O acesso à XPLENDOR está agora assegurado pela agência. Pode terminar a relação a qualquer momento, em Perfil da empresa.</p>
                        <button type="button" className="btn btn-primary" onClick={openApp}>Abrir a XPLENDOR</button>
                    </CardBody></Card>
                )}
                {state.kind === "declined" && (
                    <Card data-testid="request-declined"><CardBody className="text-center">
                        <h4 className="mb-2">Pedido recusado</h4>
                        <p className="text-muted mb-0">A agência foi avisada. A sua empresa continua sem acesso à plataforma.</p>
                    </CardBody></Card>
                )}
            </Container>
        </div>
    );
}
