import { useCallback, useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import ConfirmModal from "Components/Common/ConfirmModal";
import { useMetaOAuth } from "hooks/useMetaOAuth";
import { disconnectMetaAds, getCompanyIntegrations } from "slices/metaAds/thunk";
import { connectGoogleAnalytics, disconnectGoogleAnalytics, getGa4Traffic } from "helpers/laravel_helper";

interface Integration {
    id: number;
    platform: string;
    account_id: string;
    property_id?: string | null; // GA4 (google)
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
        disconnectingIntegration: metaAdsState.loading.disconnect,
    })
);

const selectMetaIntegration = createSelector(
    [selectMetaAdsViewModel],
    ({ integrations }) => integrations.find((integration) => integration.platform === "meta")
);

const selectGoogleIntegration = createSelector(
    [selectMetaAdsViewModel],
    ({ integrations }) => integrations.find((integration) => integration.platform === "google")
);

export default function IntegrationsSettings() {
    const dispatch: any = useDispatch();
    const [companyId, setCompanyId] = useState<number>(0);
    const [confirmDisconnectOpen, setConfirmDisconnectOpen] = useState(false);
    const [pendingPlatform, setPendingPlatform] = useState<string | null>(null);
    const { loadingIntegrations, disconnectingIntegration } = useSelector(selectMetaAdsViewModel);
    const metaIntegration = useSelector(selectMetaIntegration);
    const googleIntegration = useSelector(selectGoogleIntegration);

    // GA4 — estado local do cartão (input do property_id + email da Service Account).
    const [gaProperty, setGaProperty] = useState("");
    const [gaSaving, setGaSaving] = useState(false);
    const [saEmail, setSaEmail] = useState<string | null>(null);
    const gaConnected = !!googleIntegration && googleIntegration.status !== "revoked" && !!googleIntegration.property_id;

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
        setPendingPlatform(platform);
        setConfirmDisconnectOpen(true);
    };

    const confirmDisconnect = async () => {
        if (!pendingPlatform) return;

        try {
            await dispatch(disconnectMetaAds({ companyId, platform: pendingPlatform })).unwrap();
            toast.success("Integração desconectada.");
            await fetchIntegrations(companyId);
        } catch {
            toast.error("Erro ao desconectar integração.");
        } finally {
            setConfirmDisconnectOpen(false);
            setPendingPlatform(null);
        }
    };

    // Quando o GA4 NÃO está ligado, buscar o email da Service Account para as
    // instruções (o endpoint devolve-o de forma leve quando não há propriedade).
    useEffect(() => {
        if (!companyId || gaConnected) return;
        getGa4Traffic(companyId)
            .then((r: any) => setSaEmail(r?.data?.sa_email ?? null))
            .catch(() => setSaEmail(null));
    }, [companyId, gaConnected]);

    const connectGa = async () => {
        const pid = gaProperty.trim();
        if (!/^\d{6,15}$/.test(pid)) { toast.error("Cola só o ID numérico da propriedade GA4 (ex.: 398765432)."); return; }
        setGaSaving(true);
        try {
            await connectGoogleAnalytics(companyId, pid);
            toast.success("Google Analytics ligado.");
            setGaProperty("");
            await fetchIntegrations(companyId);
        } catch {
            toast.error("Não foi possível ligar o Google Analytics.");
        } finally {
            setGaSaving(false);
        }
    };

    const disconnectGa = async () => {
        try {
            await disconnectGoogleAnalytics(companyId);
            toast.success("Google Analytics desligado.");
            await fetchIntegrations(companyId);
        } catch {
            toast.error("Erro ao desligar o Google Analytics.");
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
            <ConfirmModal
                isOpen={confirmDisconnectOpen}
                title="Desconectar integração"
                message={`Vais perder ligação com ${pendingPlatform ?? "esta integração"}.`}
                confirmText="Desconectar"
                cancelText="Cancelar"
                variant="danger"
                loading={disconnectingIntegration}
                onCancel={() => {
                    setConfirmDisconnectOpen(false);
                    setPendingPlatform(null);
                }}
                onConfirm={() => {
                    void confirmDisconnect();
                }}
            />
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
                                            style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Conta</span>
                                            <span className="fw-medium">{formatMetaAccountId(metaIntegration.account_id)}</span>
                                        </div>
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Campanhas ativas</span>
                                            <span className="fw-medium">{metaIntegration.active_campaigns_count ?? 0}</span>
                                        </div>
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Último sync</span>
                                            <span className="fw-medium">{fmtDate(metaIntegration.last_synced_at)}</span>
                                        </div>
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}
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

                    {/* ── Google Analytics (GA4) — tráfego do site do cliente ── */}
                    <Col md={6} xl={4}>
                        <Card className="h-100 mb-0">
                            <CardBody>
                                <div className="d-flex align-items-start justify-content-between mb-3">
                                    <div className="d-flex align-items-center gap-3">
                                        <div
                                            className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                            style={{ width: 44, height: 44, background: "#E37400" }}
                                        >
                                            <i className="ri-bar-chart-box-line text-white fs-20" />
                                        </div>
                                        <div>
                                            <h6 className="fw-semibold mb-0">Google Analytics</h6>
                                            <p className="text-muted fs-12 mb-0">Tráfego do site (GA4)</p>
                                        </div>
                                    </div>
                                    {googleIntegration && (
                                        <div className="text-end">
                                            <span className={`badge ${statusBadge(googleIntegration.status).class} fs-11`}>
                                                {statusBadge(googleIntegration.status).label}
                                            </span>
                                        </div>
                                    )}
                                </div>

                                <p className="text-muted fs-13 mb-3">
                                    Traz para a XPLENDOR os visitantes, páginas mais vistas, origens e dispositivos do teu site.
                                </p>

                                {gaConnected ? (
                                    <div className="vstack gap-2">
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Propriedade</span>
                                            <span className="fw-medium">{googleIntegration?.property_id}</span>
                                        </div>
                                        <div
                                            className="d-flex align-items-center justify-content-between p-2 rounded"
                                            style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}
                                        >
                                            <span className="text-muted">Último sync</span>
                                            <span className="fw-medium">{fmtDate(googleIntegration?.last_synced_at ?? null)}</span>
                                        </div>
                                        <Link to="/trafego-site" className="btn btn-primary btn-sm mt-1" style={{ background: "#E37400", borderColor: "#E37400" }}>
                                            <i className="ri-line-chart-line me-1" /> Ver tráfego do site
                                        </Link>
                                        <button className="btn btn-soft-danger btn-sm" onClick={disconnectGa}>
                                            <i className="ri-unlink me-1" /> Desligar
                                        </button>
                                    </div>
                                ) : (
                                    <div className="vstack gap-2">
                                        <div className="p-2 rounded fs-12" style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)" }}>
                                            <div className="mb-1"><strong>1.</strong> No teu GA4, em <em>Admin → Gestão de acesso à propriedade</em>, adiciona como <strong>Visualizador</strong> o email:</div>
                                            <div className="fw-medium text-break mb-2">{saEmail ?? "(email da Service Account da XPLENDOR)"}</div>
                                            <div><strong>2.</strong> Cola aqui o <strong>ID da propriedade</strong> (Admin → Detalhes da propriedade — só números).</div>
                                        </div>
                                        <input
                                            type="text"
                                            className="form-control"
                                            inputMode="numeric"
                                            placeholder="ex.: 398765432"
                                            value={gaProperty}
                                            onChange={(e) => setGaProperty(e.target.value)}
                                        />
                                        <button className="btn btn-primary w-100" onClick={connectGa} disabled={gaSaving} style={{ background: "#E37400", borderColor: "#E37400" }}>
                                            {gaSaving ? <><Spinner size="sm" className="me-1" /> A ligar…</> : <><i className="ri-links-line me-2" />Ligar Google Analytics</>}
                                        </button>
                                    </div>
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
                                            style={{ width: 44, height: 44, background: "var(--vz-card-bg)", border: "1px solid var(--vz-border-color)" }}
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
