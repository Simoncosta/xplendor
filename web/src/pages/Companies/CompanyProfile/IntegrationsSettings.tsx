import { useCallback, useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import ConfirmModal from "Components/Common/ConfirmModal";
import { useMetaOAuth } from "hooks/useMetaOAuth";
import { disconnectMetaAds, getCompanyIntegrations } from "slices/metaAds/thunk";
import { connectGoogleAnalytics, disconnectGoogleAnalytics, getGa4Traffic, getPingwin, syncPingwin, getCoverManager, connectCoverManager, disconnectCoverManager, getCoverManagerSettings, updateCoverManagerSettings } from "helpers/laravel_helper";
import { useModules } from "contexts/ModulesContext";
import { ICarmineApi } from "common/models/carmine-api.model";
import { PingwinStatus } from "common/models/pingwin.model";
import PingwinConnectModal from "./PingwinConnectModal";
import CarmineConnectModal from "./CarmineConnectModal";

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

type IntegrationsSettingsProps = {
    dataCarmine?: ICarmineApi;
    onSubmitCarmine?: (data: ICarmineApi) => void;
};

const statusBadge = (status: string | null | undefined) => {
    const map: Record<string, { label: string; class: string; helper: string }> = {
        active: { label: "OK", class: "badge-soft-success", helper: "Sincronização operacional" },
        validating: { label: "A validar", class: "badge-soft-info", helper: "A validar a ligação…" },
        expired: { label: "Token expirado", class: "badge-soft-warning", helper: "Reconexão necessária" },
        revoked: { label: "Desconectado", class: "badge-soft-secondary", helper: "Integração desligada" },
        error: { label: "Falha", class: "badge-soft-danger", helper: "Verificar credenciais" },
    };
    return map[status ?? ""] ?? { label: "Erro", class: "badge-soft-danger", helper: "Verificar integração" };
};

const formatMetaAccountId = (accountId: string | null | undefined) => {
    if (!accountId) return "—";
    return accountId.startsWith("act_") ? accountId : `act_${accountId}`;
};

const fmtDate = (d: string | null) =>
    d ? new Date(d).toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

const infoRow = (label: string, value: React.ReactNode) => (
    <div
        className="d-flex align-items-center justify-content-between p-2 rounded"
        style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}
    >
        <span className="text-muted">{label}</span>
        <span className="fw-medium">{value}</span>
    </div>
);

/** Cartão de integração mostrado mas BLOQUEADO (o módulo respetivo está inativo). */
const BlockedCard = ({ icon, iconColor, title, subtitle, note }: {
    icon: string; iconColor: string; title: string; subtitle: string; note: string;
}) => (
    <Col md={6} xl={4}>
        <Card className="h-100 mb-0" style={{ opacity: 0.65 }}>
            <CardBody>
                <div className="d-flex align-items-start justify-content-between mb-3">
                    <div className="d-flex align-items-center gap-3">
                        <div className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                            style={{ width: 44, height: 44, background: iconColor }}>
                            <i className={`${icon} text-white fs-20`} />
                        </div>
                        <div>
                            <h6 className="fw-semibold mb-0">{title}</h6>
                            <p className="text-muted fs-12 mb-0">{subtitle}</p>
                        </div>
                    </div>
                    <span className="badge badge-soft-secondary fs-11"><i className="ri-lock-2-line me-1" />Bloqueado</span>
                </div>
                <p className="text-muted fs-13 mb-3">{note}</p>
                <button className="btn btn-outline-secondary w-100" disabled>
                    <i className="ri-lock-2-line me-2" /> Indisponível
                </button>
            </CardBody>
        </Card>
    </Col>
);

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

export default function IntegrationsSettings({ dataCarmine, onSubmitCarmine }: IntegrationsSettingsProps) {
    const dispatch: any = useDispatch();
    const { has } = useModules();
    const [companyId, setCompanyId] = useState<number>(0);
    const [confirmDisconnectOpen, setConfirmDisconnectOpen] = useState(false);
    const [pendingPlatform, setPendingPlatform] = useState<string | null>(null);
    const { loadingIntegrations, disconnectingIntegration } = useSelector(selectMetaAdsViewModel);
    const metaIntegration = useSelector(selectMetaIntegration);
    const googleIntegration = useSelector(selectGoogleIntegration);

    // Que módulos decidem o que é usável vs bloqueado (fail-open: root/loading → usável).
    const canUsePingwin = has("pingwin");
    const canUseCarmine = has("stock");

    // GA4 — estado local do cartão (input do property_id + email da Service Account).
    const [gaProperty, setGaProperty] = useState("");
    const [gaSaving, setGaSaving] = useState(false);
    const [saEmail, setSaEmail] = useState<string | null>(null);
    const gaConnected = !!googleIntegration && googleIntegration.status !== "revoked" && !!googleIntegration.property_id;

    // PingWin — estado local (via endpoints próprios, gated no backend).
    const [pingwin, setPingwin] = useState<PingwinStatus | null>(null);
    const [pingwinModalOpen, setPingwinModalOpen] = useState(false);
    const [pingwinSyncing, setPingwinSyncing] = useState(false);
    const pingwinConnected = !!pingwin && pingwin.status === "active";
    const pingwinValidating = pingwin?.status === "validating";
    const pingwinError = pingwin?.status === "error";
    const pingwinConfigured = pingwinConnected || pingwinValidating || pingwinError;

    // CoverManager — token AO NÍVEL DA EMPRESA (fallback das lojas).
    const [coverConnected, setCoverConnected] = useState(false);
    const [coverToken, setCoverToken] = useState("");
    const [coverSaving, setCoverSaving] = useState(false);
    // Flag do ticket médio (companies.cm_avg_ticket_enabled) — vive aqui (Integrações).
    const [avgTicketEnabled, setAvgTicketEnabled] = useState(true);

    const fetchCover = useCallback(async (cId: number) => {
        if (!cId || !canUsePingwin) return;
        try {
            const r: any = await getCoverManager(cId);
            setCoverConnected(!!r?.data?.connected);
        } catch {
            setCoverConnected(false);
        }
        try {
            const s: any = await getCoverManagerSettings(cId);
            setAvgTicketEnabled(s?.data?.avg_ticket_enabled ?? true);
        } catch { /* mantém default */ }
    }, [canUsePingwin]);

    const toggleAvgTicket = async (enabled: boolean) => {
        setAvgTicketEnabled(enabled);
        try {
            await updateCoverManagerSettings(companyId, enabled);
            toast.success(enabled ? "Ticket médio com CoverManager ligado." : "Ticket médio com CoverManager desligado.");
        } catch (e: any) {
            setAvgTicketEnabled(!enabled);
            toast.error(e?.message ?? "Não foi possível atualizar a definição.");
        }
    };

    const connectCover = async () => {
        if (!coverToken.trim()) { toast.error("Cola o token do CoverManager."); return; }
        setCoverSaving(true);
        try {
            await connectCoverManager(companyId, coverToken.trim());
            toast.success("CoverManager ligado à empresa.");
            setCoverToken("");
            await fetchCover(companyId);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível ligar o CoverManager.");
        } finally {
            setCoverSaving(false);
        }
    };

    const disconnectCover = async () => {
        try {
            await disconnectCoverManager(companyId);
            toast.success("CoverManager desligado.");
            await fetchCover(companyId);
        } catch {
            toast.error("Erro ao desligar o CoverManager.");
        }
    };

    // Carmine — dados vêm por props (fluxo Redux existente no editor/pai).
    const [carmineModalOpen, setCarmineModalOpen] = useState(false);
    const carmineConnected = !!dataCarmine?.id;

    const fetchIntegrations = useCallback(async (cId: number) => {
        try {
            await dispatch(getCompanyIntegrations({ companyId: cId })).unwrap();
        } catch {
            toast.error("Erro ao carregar integrações.");
        }
    }, [dispatch]);

    const fetchPingwin = useCallback(async (cId: number) => {
        // Só chama o endpoint (gated) se o módulo estiver ativo — senão dá 403.
        if (!cId || !canUsePingwin) return;
        try {
            const r: any = await getPingwin(cId);
            setPingwin(r?.data ?? null);
        } catch {
            setPingwin(null);
        }
    }, [canUsePingwin]);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { company_id } = JSON.parse(authUser);
        setCompanyId(Number(company_id));
        fetchIntegrations(Number(company_id));
        fetchPingwin(Number(company_id));
        fetchCover(Number(company_id));
    }, [fetchIntegrations, fetchPingwin, fetchCover]);

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

    const runPingwinSync = async () => {
        setPingwinSyncing(true);
        try {
            await syncPingwin(companyId);
            toast.success("PingWin sincronizado.");
            await fetchPingwin(companyId);
        } catch (e: any) {
            toast.error(e?.message ?? "Falha ao sincronizar o PingWin.");
        } finally {
            setPingwinSyncing(false);
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

            <PingwinConnectModal
                isOpen={pingwinModalOpen}
                companyId={companyId}
                initialConfig={pingwin?.config ?? null}
                isReconfigure={pingwinConfigured}
                onClose={() => setPingwinModalOpen(false)}
                onSaved={() => fetchPingwin(companyId)}
            />

            {dataCarmine && onSubmitCarmine && (
                <CarmineConnectModal
                    isOpen={carmineModalOpen}
                    data={dataCarmine}
                    onClose={() => setCarmineModalOpen(false)}
                    onSubmit={onSubmitCarmine}
                />
            )}

            <Container fluid>
                <Row className="mb-3">
                    <Col>
                        <h4 className="fw-semibold mb-1">Integrações</h4>
                        <p className="text-muted fs-13 mb-0">
                            Liga as tuas plataformas externas para que os dados cheguem automaticamente à XPLENDOR.
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
                                        {infoRow("Conta", formatMetaAccountId(metaIntegration.account_id))}
                                        {infoRow("Campanhas ativas", metaIntegration.active_campaigns_count ?? 0)}
                                        {infoRow("Último sync", fmtDate(metaIntegration.last_synced_at))}
                                        {infoRow("Token expira", fmtDate(metaIntegration.token_expires_at))}
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
                                        {infoRow("Propriedade", googleIntegration?.property_id)}
                                        {infoRow("Último sync", fmtDate(googleIntegration?.last_synced_at ?? null))}
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

                    {/* ── Carmine (stock automóvel) ────────────────────────── */}
                    {canUseCarmine ? (
                        <Col md={6} xl={4}>
                            <Card className="h-100 mb-0">
                                <CardBody>
                                    <div className="d-flex align-items-start justify-content-between mb-3">
                                        <div className="d-flex align-items-center gap-3">
                                            <div className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                                style={{ width: 44, height: 44, background: "#DF3E23" }}>
                                                <i className="ri-car-line text-white fs-20" />
                                            </div>
                                            <div>
                                                <h6 className="fw-semibold mb-0">Carmine</h6>
                                                <p className="text-muted fs-12 mb-0">Stock de veículos</p>
                                            </div>
                                        </div>
                                        {carmineConnected && (
                                            <span className="badge badge-soft-success fs-11">Ligado</span>
                                        )}
                                    </div>
                                    <p className="text-muted fs-13 mb-3">
                                        Sincroniza o stock de veículos a partir da tua conta Carmine.
                                    </p>
                                    {carmineConnected ? (
                                        <div className="vstack gap-2">
                                            {infoRow("Dealer", dataCarmine?.dealer_id || "—")}
                                            <button className="btn btn-soft-primary btn-sm mt-1" onClick={() => setCarmineModalOpen(true)}>
                                                <i className="ri-settings-3-line me-1" /> Reconfigurar
                                            </button>
                                        </div>
                                    ) : (
                                        <button className="btn btn-primary w-100" onClick={() => setCarmineModalOpen(true)}
                                            style={{ background: "#DF3E23", borderColor: "#DF3E23" }}>
                                            <i className="ri-links-line me-2" /> Ligar Carmine
                                        </button>
                                    )}
                                </CardBody>
                            </Card>
                        </Col>
                    ) : (
                        <BlockedCard
                            icon="ri-car-line"
                            iconColor="#DF3E23"
                            title="Carmine"
                            subtitle="Stock de veículos"
                            note="Disponível para o ramo automotivo (requer o módulo Stock)."
                        />
                    )}

                    {/* ── PingWin (POS restauração) ────────────────────────── */}
                    {canUsePingwin ? (
                        <Col md={6} xl={4}>
                            <Card className="h-100 mb-0">
                                <CardBody>
                                    <div className="d-flex align-items-start justify-content-between mb-3">
                                        <div className="d-flex align-items-center gap-3">
                                            <div className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                                style={{ width: 44, height: 44, background: "#0AB39C" }}>
                                                <i className="ri-restaurant-2-line text-white fs-20" />
                                            </div>
                                            <div>
                                                <h6 className="fw-semibold mb-0">PingWin</h6>
                                                <p className="text-muted fs-12 mb-0">POS restauração (GrupoPIE)</p>
                                            </div>
                                        </div>
                                        {pingwinConfigured && (
                                            <span className={`badge ${statusBadge(pingwin!.status).class} fs-11`}>
                                                {statusBadge(pingwin!.status).label}
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-muted fs-13 mb-3">
                                        Descobre as lojas e traz o resumo de vendas por loja do teu POS PingWin.
                                    </p>

                                    {pingwinValidating ? (
                                        <div className="vstack gap-2">
                                            <div className="p-2 rounded fs-12 d-flex align-items-center gap-2" style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)" }}>
                                                <Spinner size="sm" /> A validar a ligação… serás notificado no sino quando terminar.
                                            </div>
                                            <button className="btn btn-soft-primary btn-sm" onClick={() => setPingwinModalOpen(true)}>
                                                <i className="ri-settings-3-line me-1" /> Reconfigurar
                                            </button>
                                        </div>
                                    ) : pingwinError ? (
                                        <div className="vstack gap-2">
                                            <div className="alert alert-danger py-2 px-3 fs-12 mb-0" role="alert">
                                                <i className="ri-error-warning-line me-1" />
                                                {pingwin?.error_message || "Não foi possível validar a ligação."}
                                            </div>
                                            <button className="btn btn-primary btn-sm" onClick={() => setPingwinModalOpen(true)}
                                                style={{ background: "#0AB39C", borderColor: "#0AB39C" }}>
                                                <i className="ri-settings-3-line me-1" /> Corrigir credenciais
                                            </button>
                                        </div>
                                    ) : pingwinConnected ? (
                                        <div className="vstack gap-2">
                                            {infoRow("Lojas", pingwin?.stores?.length ?? 0)}
                                            {infoRow("Último sync", fmtDate(pingwin?.last_synced_at ?? null))}
                                            {(pingwin?.stores?.length ?? 0) > 0 && (
                                                <div className="p-2 rounded fs-12" style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)" }}>
                                                    {pingwin!.stores.slice(0, 4).map((s) => (
                                                        <div key={s.id} className="d-flex justify-content-between">
                                                            <span className="text-truncate me-2">{s.description || s.code || s.external_id}</span>
                                                            {s.last_summary?.total != null && (
                                                                <span className="fw-medium">{s.last_summary.total}</span>
                                                            )}
                                                        </div>
                                                    ))}
                                                    {pingwin!.stores.length > 4 && (
                                                        <div className="text-muted mt-1">+{pingwin!.stores.length - 4} lojas</div>
                                                    )}
                                                </div>
                                            )}
                                            <button className="btn btn-primary btn-sm mt-1" onClick={runPingwinSync} disabled={pingwinSyncing}
                                                style={{ background: "#0AB39C", borderColor: "#0AB39C" }}>
                                                {pingwinSyncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar agora</>}
                                            </button>
                                            <div className="text-muted fs-11">As lojas gerem-se em <strong>Restauração › Lojas</strong> (menu lateral).</div>
                                            <button className="btn btn-soft-primary btn-sm" onClick={() => setPingwinModalOpen(true)}>
                                                <i className="ri-settings-3-line me-1" /> Reconfigurar
                                            </button>
                                        </div>
                                    ) : (
                                        <button className="btn btn-primary w-100" onClick={() => setPingwinModalOpen(true)}
                                            style={{ background: "#0AB39C", borderColor: "#0AB39C" }}>
                                            <i className="ri-links-line me-2" /> Ligar PingWin
                                        </button>
                                    )}
                                </CardBody>
                            </Card>
                        </Col>
                    ) : (
                        <BlockedCard
                            icon="ri-restaurant-2-line"
                            iconColor="#0AB39C"
                            title="PingWin"
                            subtitle="POS restauração (GrupoPIE)"
                            note="Disponível para o ramo restauração (requer o módulo PingWin)."
                        />
                    )}

                    {/* ── CoverManager (reservas) — token AO NÍVEL DA EMPRESA ─── */}
                    {canUsePingwin ? (
                        <Col md={6} xl={4}>
                            <Card className="h-100 mb-0">
                                <CardBody>
                                    <div className="d-flex align-items-start justify-content-between mb-3">
                                        <div className="d-flex align-items-center gap-3">
                                            <div className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                                style={{ width: 44, height: 44, background: "#6259CA" }}>
                                                <i className="ri-calendar-check-line text-white fs-20" />
                                            </div>
                                            <div>
                                                <h6 className="fw-semibold mb-0">CoverManager</h6>
                                                <p className="text-muted fs-12 mb-0">Reservas (por empresa)</p>
                                            </div>
                                        </div>
                                        {coverConnected && <span className="badge badge-soft-success fs-11">Ligado</span>}
                                    </div>
                                    <p className="text-muted fs-13 mb-3">
                                        Token da empresa (fallback de todas as lojas). O slug de cada loja gere-se em <strong>Restauração › Lojas</strong>.
                                    </p>
                                    {coverConnected ? (
                                        <div className="vstack gap-2">
                                            {infoRow("Token", "•••••••• (guardado)")}
                                            {/* Flag do ticket médio (movida das Lojas para aqui). */}
                                            <div className="form-check form-switch mt-1">
                                                <input className="form-check-input" type="checkbox" role="switch" id="cm-avg-ticket"
                                                    checked={avgTicketEnabled} onChange={(e) => toggleAvgTicket(e.target.checked)} />
                                                <label className="form-check-label fs-13" htmlFor="cm-avg-ticket">Ticket médio com CoverManager</label>
                                            </div>
                                            <button className="btn btn-soft-danger btn-sm mt-1" onClick={disconnectCover}>
                                                <i className="ri-unlink me-1" /> Desligar
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="vstack gap-2">
                                            <input type="password" className="form-control" placeholder="token CoverManager (apikey)"
                                                value={coverToken} onChange={(e) => setCoverToken(e.target.value)} autoComplete="new-password" disabled={coverSaving} />
                                            <button className="btn btn-primary w-100" onClick={connectCover} disabled={coverSaving}
                                                style={{ background: "#6259CA", borderColor: "#6259CA" }}>
                                                {coverSaving ? <><Spinner size="sm" className="me-1" /> A ligar…</> : <><i className="ri-links-line me-2" /> Ligar CoverManager</>}
                                            </button>
                                        </div>
                                    )}
                                </CardBody>
                            </Card>
                        </Col>
                    ) : (
                        <BlockedCard
                            icon="ri-calendar-check-line"
                            iconColor="#6259CA"
                            title="CoverManager"
                            subtitle="Reservas"
                            note="Disponível para o ramo restauração (requer o módulo PingWin)."
                        />
                    )}

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
