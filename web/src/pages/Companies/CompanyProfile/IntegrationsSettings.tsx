import { useCallback, useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
import { Link, useSearchParams } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { useMetaOAuth } from "hooks/useMetaOAuth";
import { disconnectMetaAds, getCompanyIntegrations } from "slices/metaAds/thunk";
import { connectGoogleAnalytics, disconnectGoogleAnalytics, getGa4Traffic, getPingwin, syncPingwin, getCoverManager, connectCoverManager, disconnectCoverManager, getCoverManagerSettings, updateCoverManagerSettings, getMyModules } from "helpers/laravel_helper";
import { ICarmineApi } from "common/models/carmine-api.model";
import { PingwinStatus } from "common/models/pingwin.model";
import PingwinConnectModal from "./PingwinConnectModal";
import MarketingDataCard from "./MarketingDataCard";
import CarmineConnectModal from "./CarmineConnectModal";
import MetaDisconnectModal, { MetaDisconnectMode } from "./MetaDisconnectModal";
import SocialConnectionCard from "./SocialConnectionCard";
import MetaAccountPicker from "./MetaAccountPicker";
import SetupLinkCard from "./setupLink/SetupLinkCard";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import { confirmAction } from "helpers/swal";
import { getHomeCompanyId, getWorkingCompanyId } from "helpers/workingCompany";

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
    /** A empresa do perfil aberto (por omissão, a empresa em que se trabalha). */
    companyId?: number;
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
    if (!accountId) return "Por definir";
    return accountId.startsWith("act_") ? accountId : `act_${accountId}`;
};

const fmtDate = (d: string | null) =>
    d ? new Date(d).toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "Ainda não";

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
                <ReasonButton color="outline-primary" className="w-100" reason={note}>
                    <i className="ri-lock-2-line me-2" /> Indisponível
                </ReasonButton>
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

export default function IntegrationsSettings({ companyId: profileCompanyId, dataCarmine, onSubmitCarmine }: IntegrationsSettingsProps) {
    const dispatch: any = useDispatch();
    const [searchParams, setSearchParams] = useSearchParams();
    const [companyId, setCompanyId] = useState<number>(0);
    // Ligar e desligar os anúncios: admin da própria empresa (o root na sua), nunca em
    // impersonation. O backend decide; aqui só se escondem os botões.
    const [canManageMeta, setCanManageMeta] = useState(false);
    const [agencyMemberOnly, setAgencyMemberOnly] = useState(false);
    // Corrigir uma conta já guardada (ID mal escrito → sync falha sem outra saída).
    const [editingMetaAccount, setEditingMetaAccount] = useState(false);
    // Desligar a Meta (com escolha: manter histórico ou apagar) ou apagar o histórico guardado.
    const [metaModalMode, setMetaModalMode] = useState<MetaDisconnectMode | null>(null);
    const { loadingIntegrations, disconnectingIntegration } = useSelector(selectMetaAdsViewModel);
    const metaIntegration = useSelector(selectMetaIntegration);
    const googleIntegration = useSelector(selectGoogleIntegration);

    // Os módulos ATIVOS da empresa deste perfil decidem o que é usável e o que se pede ao
    // servidor (sem pedidos que dão 403). Enquanto não se sabem, nada se pede.
    const [companyModules, setCompanyModules] = useState<string[] | null>(null);
    const canUsePingwin = !!companyModules?.includes("pingwin");
    const canUseCarmine = !!companyModules?.includes("stock");

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
        const ok = await confirmAction({ title: "Desligar o CoverManager?", text: "O token da empresa é retirado e as lojas sem token próprio deixam de receber as reservas.", confirmText: "Desligar", icon: "warning", confirmVariant: "danger" });
        if (!ok) return;
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
        const { role, impersonating } = JSON.parse(authUser);
        const companyId = profileCompanyId || getWorkingCompanyId();
        setCompanyId(companyId);
        // O backend confirma: admin da empresa, root ou ADMIN da agência gestora (fora de impersonation).
        setCanManageMeta((role === "admin" || role === "root") && !impersonating);
        // Pela agência (empresa que não é a da pessoa), só os admins dela ligam integrações.
        setAgencyMemberOnly(role === "user" && companyId !== getHomeCompanyId());
        fetchIntegrations(companyId);
        fetchPingwin(companyId);
        fetchCover(companyId);
    }, [fetchIntegrations, fetchPingwin, fetchCover, profileCompanyId]);

    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        getMyModules(companyId).then((r: any) => { if (alive) setCompanyModules(r?.data?.modules ?? []); }).catch(() => { if (alive) setCompanyModules([]); });
        return () => { alive = false; };
    }, [companyId]);

    // Retorno do OAuth Meta (backend redireciona para cá com ?meta=...). Mostra
    // o resultado e limpa o parâmetro do URL para não repetir ao refrescar.
    useEffect(() => {
        const meta = searchParams.get("meta");
        if (!meta) return;

        if (meta === "connected") {
            toast.success("Meta Ads conectado com sucesso!");
        } else if (meta === "choose_account") {
            toast.info("Meta Ads ligado. Falta escolher a conta de anúncios.");
        } else if (meta === "error") {
            const reason = searchParams.get("reason");
            const msg = reason === "denied"
                ? "Autorização cancelada no Meta."
                : reason === "state"
                    ? "Sessão de ligação expirada. Tenta novamente."
                    : "Não foi possível ligar o Meta Ads. Tenta novamente.";
            toast.error(msg);
        }

        // Limpar ?meta (e ?reason) preservando qualquer outro parâmetro.
        const next = new URLSearchParams(searchParams);
        next.delete("meta");
        next.delete("reason");
        setSearchParams(next, { replace: true });
    }, [searchParams, setSearchParams]);

    const confirmMetaDisconnect = async ({ purge, confirmation }: { purge: boolean; confirmation?: string }) => {
        const mode = metaModalMode;
        try {
            const res: any = await dispatch(disconnectMetaAds({ companyId, platform: "meta", purge, confirmation })).unwrap();
            toast.success(purge ? "Dados da Meta apagados." : "Meta desligada. O histórico foi mantido.");
            if (mode === "disconnect" && !res?.data?.permissions_revoked) {
                toast.warning("Não foi possível retirar a permissão dos anúncios na Meta (a sessão pode ter expirado). Pode removê-la no Facebook, em Definições, Integrações empresariais.");
            }
            await fetchIntegrations(companyId);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível desligar a Meta.");
        } finally {
            setMetaModalMode(null);
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
        if (!/^\d{6,15}$/.test(pid)) { toast.error("Cole só o ID numérico da propriedade GA4 (ex.: 398765432)."); return; }
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
        const ok = await confirmAction({ title: "Desligar o Google Analytics?", text: "A XPLENDOR deixa de receber o tráfego do site.", confirmText: "Desligar", icon: "warning", confirmVariant: "danger" });
        if (!ok) return;
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
            {/* Os avisos usam o ToastContainer da página (CompanyProfileUpdate); um segundo duplicava-os. */}
            <MetaDisconnectModal
                isOpen={metaModalMode !== null}
                mode={metaModalMode ?? "disconnect"}
                loading={disconnectingIntegration}
                onCancel={() => setMetaModalMode(null)}
                onConfirm={(options) => {
                    void confirmMetaDisconnect(options);
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
                        <h5 className="fw-semibold mb-1">Integrações</h5>
                        <p className="text-muted fs-13 mb-0">
                            Ligue as suas plataformas externas para que os dados cheguem automaticamente à XPLENDOR.
                        </p>
                    </Col>
                </Row>

                <Row className="g-3">
                    {/* Link de configuração do cliente (só quem pode gerir o vê) e histórico das ligações. */}
                    {companyId > 0 && <SetupLinkCard companyId={companyId} />}

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
                                        <div className="d-flex align-items-start gap-2">
                                            <div className="text-end">
                                                <span className={`badge ${statusBadge(metaIntegration.status).class} fs-11`}>
                                                    {statusBadge(metaIntegration.status).label}
                                                </span>
                                                <div className="text-muted fs-11 mt-1">
                                                    {statusBadge(metaIntegration.status).helper}
                                                </div>
                                            </div>
                                            {canManageMeta && (
                                                <ActionsMenu size="sm" label="Mais ações: Meta Ads" items={[
                                                    { label: "Desligar a Meta", icon: "ri-unlink", danger: true, hidden: metaIntegration.status === "revoked", onClick: () => setMetaModalMode("disconnect") },
                                                    { label: "Apagar os dados da Meta guardados", icon: "ri-delete-bin-line", danger: true, hidden: metaIntegration.status !== "revoked", onClick: () => setMetaModalMode("purge") },
                                                ]} />
                                            )}
                                        </div>
                                    )}
                                </div>

                                <p className="text-muted fs-13 mb-3">
                                    Puxa automaticamente spend, impressions, clicks, CPM e CTR das suas campanhas todas as noites.
                                </p>

                                {metaIntegration ? (
                                    <div className="vstack gap-2">
                                        {infoRow("Conta", formatMetaAccountId(metaIntegration.account_id))}
                                        {infoRow("Campanhas ativas", metaIntegration.active_campaigns_count ?? 0)}
                                        {infoRow("Último sync", fmtDate(metaIntegration.last_synced_at))}
                                        {/* Sem data: a Meta não indicou expiração (não quer dizer expirado). */}
                                        {infoRow("Token expira", metaIntegration.token_expires_at ? fmtDate(metaIntegration.token_expires_at) : "Sem data de expiração")}

                                        {/* Corrigir a conta (ex.: ID mal escrito → sincronização falha). */}
                                        {canManageMeta && metaIntegration.account_id && !editingMetaAccount && (
                                            <button type="button" className="btn btn-link btn-sm p-0 text-start fs-12" onClick={() => setEditingMetaAccount(true)}>
                                                <i className="ri-edit-line me-1" />Alterar conta de anúncios
                                            </button>
                                        )}

                                        {/* Conta por definir (logo após o OAuth) ou a corrigir: o token já
                                            está guardado; falta escolher/corrigir a conta de anúncios. */}
                                        {canManageMeta && (!metaIntegration.account_id || editingMetaAccount) && metaIntegration.status !== "revoked" && (
                                            <MetaAccountPicker companyId={companyId} current={metaIntegration.account_id || null}
                                                onSaved={() => { setEditingMetaAccount(false); void fetchIntegrations(companyId); }} />
                                        )}

                                        {!canManageMeta ? (
                                            <p className="text-muted fs-12 mb-0 mt-1">Só o administrador da empresa pode ligar ou desligar os anúncios da Meta.</p>
                                        ) : metaIntegration.status === "active" ? null : (
                                            /* Token expirado ou com falha: volta a ligar-se aqui; desligar (e apagar)
                                               fica no menu "..." do cartão. Já desligada: o histórico guardado apaga-se no menu. */
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
                                ) : !canManageMeta ? (
                                    <p className="text-muted fs-12 mb-0">{agencyMemberOnly ? "Pela agência, só os administradores ligam integrações." : "Só o administrador da empresa pode ligar os anúncios da Meta."}</p>
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

                    {/* Redes sociais (Instagram e Facebook): ligação separada da dos anúncios. */}
                    {companyId > 0 && <SocialConnectionCard companyId={companyId} />}

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
                                    Traz para a XPLENDOR os visitantes, páginas mais vistas, origens e dispositivos do seu site.
                                </p>

                                {agencyMemberOnly ? (
                                    gaConnected
                                        ? <div className="vstack gap-2">{infoRow("Propriedade", googleIntegration?.property_id)}<Link to="/trafego-site" className="btn btn-outline-primary btn-sm mt-1"><i className="ri-line-chart-line me-1" /> Ver tráfego do site</Link></div>
                                        : <p className="text-muted fs-12 mb-0">Pela agência, só os administradores ligam integrações.</p>
                                ) : gaConnected ? (
                                    <div className="vstack gap-2">
                                        {infoRow("Propriedade", googleIntegration?.property_id)}
                                        {infoRow("Último sync", fmtDate(googleIntegration?.last_synced_at ?? null))}
                                        <div className="d-flex gap-2 mt-1">
                                            <Link to="/trafego-site" className="btn btn-outline-primary btn-sm flex-grow-1">
                                                <i className="ri-line-chart-line me-1" /> Ver tráfego do site
                                            </Link>
                                            <ActionsMenu size="sm" label="Mais ações: Google Analytics" items={[
                                                { label: "Desligar", icon: "ri-unlink", danger: true, onClick: () => void disconnectGa() },
                                            ]} />
                                        </div>
                                    </div>
                                ) : (
                                    <div className="vstack gap-2">
                                        <div className="p-2 rounded fs-12" style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)" }}>
                                            <div className="mb-1"><strong>1.</strong> No seu GA4, em <em>Admin → Gestão de acesso à propriedade</em>, adicione como <strong>Visualizador</strong> o email:</div>
                                            <div className="fw-medium text-break mb-2">{saEmail ?? "(email da Service Account da XPLENDOR)"}</div>
                                            <div><strong>2.</strong> Cole aqui o <strong>ID da propriedade</strong> (Admin → Detalhes da propriedade, só números).</div>
                                        </div>
                                        <input
                                            type="text"
                                            className="form-control"
                                            inputMode="numeric"
                                            placeholder="ex.: 398765432"
                                            value={gaProperty}
                                            onChange={(e) => setGaProperty(e.target.value)}
                                        />
                                        <button className="btn btn-outline-primary w-100" onClick={connectGa} disabled={gaSaving}>
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
                                        Sincroniza o stock de veículos a partir da sua conta Carmine.
                                    </p>
                                    {carmineConnected ? (
                                        <div className="vstack gap-2">
                                            {infoRow("Dealer", dataCarmine?.dealer_id || "Sem dados")}
{!agencyMemberOnly && (<button className="btn btn-outline-primary btn-sm mt-1" onClick={() => setCarmineModalOpen(true)}>
                                                <i className="ri-settings-3-line me-1" /> Reconfigurar
                                            </button>)}
                                        </div>
                                    ) : agencyMemberOnly ? <p className="text-muted fs-12 mb-0">Pela agência, só os administradores ligam integrações.</p> : (
                                        <button className="btn btn-outline-primary w-100" onClick={() => setCarmineModalOpen(true)}>
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
                                        Descobre as lojas e traz o resumo de vendas por loja do seu POS PingWin.
                                    </p>

                                    {pingwinValidating ? (
                                        <div className="vstack gap-2">
                                            <div className="p-2 rounded fs-12 d-flex align-items-center gap-2" style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)" }}>
                                                <Spinner size="sm" /> A validar a ligação. Será notificado no sino quando terminar.
                                            </div>
                                            {!agencyMemberOnly && (<button className="btn btn-outline-primary btn-sm" onClick={() => setPingwinModalOpen(true)}>
                                                <i className="ri-settings-3-line me-1" /> Reconfigurar
                                            </button>)}
                                        </div>
                                    ) : pingwinError ? (
                                        <div className="vstack gap-2">
                                            <div className="alert alert-danger py-2 px-3 fs-12 mb-0" role="alert">
                                                <i className="ri-error-warning-line me-1" />
                                                {pingwin?.error_message || "Não foi possível validar a ligação."}
                                            </div>
                                            {!agencyMemberOnly && (<button className="btn btn-outline-primary btn-sm" onClick={() => setPingwinModalOpen(true)}>
                                                <i className="ri-settings-3-line me-1" /> Corrigir credenciais
                                            </button>)}
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
                                            <button className="btn btn-outline-primary btn-sm mt-1" onClick={runPingwinSync} disabled={pingwinSyncing}>
                                                {pingwinSyncing ? <><Spinner size="sm" className="me-1" /> A sincronizar…</> : <><i className="ri-refresh-line me-1" /> Sincronizar agora</>}
                                            </button>
                                            <div className="text-muted fs-11">As lojas gerem-se em <strong>Restauração › Lojas</strong> (menu lateral).</div>
                                            {!agencyMemberOnly && (<button className="btn btn-outline-primary btn-sm" onClick={() => setPingwinModalOpen(true)}>
                                                <i className="ri-settings-3-line me-1" /> Reconfigurar
                                            </button>)}
                                        </div>
                                    ) : agencyMemberOnly ? <p className="text-muted fs-12 mb-0">Pela agência, só os administradores ligam integrações.</p> : (
                                        <button className="btn btn-outline-primary w-100" onClick={() => setPingwinModalOpen(true)}>
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

                    {/* F1-3: dados para o marketing (vendas por artigo), com o PingWin ligado. */}
                    {canUsePingwin && pingwinConnected && companyId ? <MarketingDataCard companyId={companyId} /> : null}

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
{!agencyMemberOnly && (<div className="mt-1"><ActionsMenu size="sm" label="Mais ações: CoverManager" items={[
                                                { label: "Desligar", icon: "ri-unlink", danger: true, onClick: () => void disconnectCover() },
                                            ]} /></div>)}
                                        </div>
                                    ) : agencyMemberOnly ? <p className="text-muted fs-12 mb-0">Pela agência, só os administradores ligam integrações.</p> : (
                                        <div className="vstack gap-2">
                                            <input type="password" className="form-control" placeholder="token CoverManager (apikey)"
                                                value={coverToken} onChange={(e) => setCoverToken(e.target.value)} autoComplete="new-password" disabled={coverSaving} />
                                            <button className="btn btn-outline-primary w-100" onClick={connectCover} disabled={coverSaving}>
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
                                <ReasonButton color="outline-primary" className="w-100" reason="Integração em desenvolvimento.">
                                    <i className="ri-google-fill me-2" />
                                    Google Ads: em breve
                                </ReasonButton>
                            </CardBody>
                        </Card>
                    </Col>

                </Row>
            </Container>
        </Row>
    );
}
