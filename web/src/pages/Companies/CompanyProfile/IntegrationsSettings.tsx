import { useCallback, useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Card, CardBody, Col, Container, Row } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import { useMetaOAuth } from "hooks/useMetaOAuth";
import { disconnectMetaAds, getCompanyIntegrations } from "slices/metaAds/thunk";

interface Integration {
    id: number;
    platform: string;
    account_id: string;
    status: "active" | "expired" | "revoked" | "error";
    last_synced_at: string | null;
    token_expires_at: string | null;
    active_campaigns_count: number;
}

const statusBadge = (status: Integration["status"]) => {
    const map = {
        active: { label: "OK", class: "badge-soft-success", helper: "Sincronização operacional" },
        expired: { label: "Token expirado", class: "badge-soft-warning", helper: "Reconexão necessária" },
        revoked: { label: "Desconectado", class: "badge-soft-secondary", helper: "Integração desligada" },
        error: { label: "Erro", class: "badge-soft-danger", helper: "Verificar integração" },
    };
    return map[status] ?? { label: "Erro", class: "badge-soft-danger", helper: "Verificar integração" };
};

const formatMetaAccountId = (accountId: string | null | undefined) => {
    if (!accountId) return "—";
    return accountId.startsWith("act_") ? accountId : `act_${accountId}`;
};

const fmtDate = (d: string | null) =>
    d ? new Date(d).toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

const selectMetaAdsState = (state: any) => state.MetaAds;

const selectMetaAdsViewModel = createSelector(
    [selectMetaAdsState],
    (metaAdsState) => ({
        integrations: metaAdsState.data.integrations as Integration[],
        loadingIntegrations: metaAdsState.loading.list,
    })
);

const selectMetaIntegration = createSelector(
    [selectMetaAdsViewModel],
    ({ integrations }) => integrations.find((integration) => integration.platform === "meta")
);

export default function IntegrationsSettings() {
    const dispatch: any = useDispatch();
    const [companyId, setCompanyId] = useState<number>(0);
    const { loadingIntegrations } = useSelector(selectMetaAdsViewModel);
    const metaIntegration = useSelector(selectMetaIntegration);

    const fetchIntegrations = useCallback(async (cId: number) => {
        try {
            await dispatch(getCompanyIntegrations({ companyId: cId })).unwrap();
        } catch {
            toast.error("Erro ao carregar integrações.");
        }
    }, [dispatch]);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { company_id } = JSON.parse(authUser);
        setCompanyId(Number(company_id));
        fetchIntegrations(Number(company_id));
    }, [fetchIntegrations]);

    const handleDisconnect = async (platform: string) => {
        if (!window.confirm(`Tens a certeza que queres desconectar o ${platform}?`)) return;
        try {
            await dispatch(disconnectMetaAds({ companyId, platform })).unwrap();
            toast.success("Integração desconectada.");
            await fetchIntegrations(companyId);
        } catch {
            toast.error("Erro ao desconectar integração.");
        }
    };

    const { connect: connectMeta } = useMetaOAuth({
        companyId,
        onSuccess: () => {
            toast.success("Meta Ads conectado com sucesso!");
            fetchIntegrations(companyId);
        },
        onError: (msg) => toast.error(msg),
    });

    if (loadingIntegrations) return null;

    return (
        <Row>
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3">
                    <Col>
                        <h4 className="fw-semibold mb-1">Integrações</h4>
                        <p className="text-muted fs-13 mb-0">
                            Conecta as tuas plataformas de anúncios para que os dados cheguem automaticamente todas as noites.
                        </p>
                    </Col>
                </Row>

                <Row className="g-3">

                    {/* ── Meta Ads ─────────────────────────────────────────── */}
                    <Col md={6} xl={4}>
                        <Card className="h-100 mb-0">
                            <CardBody>
                                <div className="d-flex align-items-start justify-content-between mb-3">
                                    <div className="d-flex align-items-center gap-3">
                                        <div
                                            className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                            style={{ width: 44, height: 44, background: "#1877F2" }}
                                        >
                                            <i className="ri-facebook-fill text-white fs-20" />
                                        </div>
                                        <div>
                                            <h6 className="fw-semibold mb-0">Meta Ads</h6>
                                            <p className="text-muted fs-12 mb-0">Facebook & Instagram</p>
                                        </div>
                                    </div>
                                    {metaIntegration && (
                                        <div className="text-end">
                                            <span className={`badge ${statusBadge(metaIntegration.status).class} fs-11`}>
                                                {statusBadge(metaIntegration.status).label}
                                            </span>
                                            <div className="text-muted fs-11 mt-1">
                                                {statusBadge(metaIntegration.status).helper}
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <p className="text-muted fs-13 mb-3">
                                    Puxa automaticamente spend, impressions, clicks, CPM e CTR das tuas campanhas todas as noites.
                                </p>

                                {metaIntegration ? (
                                    <div className="vstack gap-2">
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "#f8f9fa", border: "1px dashed #e9ebec", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Conta</span>
                                            <span className="fw-medium">{formatMetaAccountId(metaIntegration.account_id)}</span>
                                        </div>
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "#f8f9fa", border: "1px dashed #e9ebec", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Campanhas ativas</span>
                                            <span className="fw-medium">{metaIntegration.active_campaigns_count ?? 0}</span>
                                        </div>
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "#f8f9fa", border: "1px dashed #e9ebec", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Último sync</span>
                                            <span className="fw-medium">{fmtDate(metaIntegration.last_synced_at)}</span>
                                        </div>
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "#f8f9fa", border: "1px dashed #e9ebec", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Token expira</span>
                                            <span className="fw-medium">{fmtDate(metaIntegration.token_expires_at)}</span>
                                        </div>
                                        {metaIntegration.status === "active" ? (
                                            <button
                                                className="btn btn-soft-danger btn-sm mt-1"
                                                onClick={() => handleDisconnect("meta")}
                                            >
                                                <i className="ri-unlink me-1" /> Desconectar
                                            </button>
                                        ) : (
                                            <button
                                                className="btn btn-primary w-100 mt-1"
                                                onClick={connectMeta}
                                                style={{ background: "#1877F2", borderColor: "#1877F2" }}
                                            >
                                                <i className="ri-facebook-fill me-2" />
                                                Reconectar com Facebook
                                            </button>
                                        )}
                                    </div>
                                ) : (
                                    <button
                                        className="btn btn-primary w-100"
                                        onClick={connectMeta}
                                        style={{ background: "#1877F2", borderColor: "#1877F2" }}
                                    >
                                        <i className="ri-facebook-fill me-2" />
                                        Conectar com Facebook
                                    </button>
                                )}
                            </CardBody>
                        </Card>
                    </Col>

                    {/* ── Google Ads (placeholder XPLDR-31) ────────────────── */}
                    <Col md={6} xl={4}>
                        <Card className="h-100 mb-0" style={{ opacity: 0.6 }}>
                            <CardBody>
                                <div className="d-flex align-items-start justify-content-between mb-3">
                                    <div className="d-flex align-items-center gap-3">
                                        <div
                                            className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                            style={{ width: 44, height: 44, background: "#fff", border: "1px solid #e9ebec" }}
                                        >
                                            <i className="ri-google-fill fs-20" style={{ color: "#4285F4" }} />
                                        </div>
                                        <div>
                                            <h6 className="fw-semibold mb-0">Google Ads</h6>
                                            <p className="text-muted fs-12 mb-0">Search & Display</p>
                                        </div>
                                    </div>
                                    <span className="badge badge-soft-secondary fs-11">Em breve</span>
                                </div>
                                <p className="text-muted fs-13 mb-3">
                                    Integração com Google Ads em desenvolvimento. Disponível em breve.
                                </p>
                                <button className="btn btn-outline-secondary w-100" disabled>
                                    <i className="ri-google-fill me-2" />
                                    Google Ads — Em breve
                                </button>
                            </CardBody>
                        </Card>
                    </Col>

                </Row>
            </Container>
        </Row>
    );
}
