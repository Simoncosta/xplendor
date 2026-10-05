import { useCallback, useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Badge, Card, CardBody, Col, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { disconnectSocial, getSocialAuthUrl, getSocialConnection } from "helpers/laravel_helper";
import { SOCIAL_NOT_APPROVED_TEXT, SocialConnectionState, socialStateView } from "common/models/socialConnection.model";
import SocialAccountsModal from "./SocialAccountsModal";
import SocialDisconnectModal, { SocialDisconnectMode } from "./SocialDisconnectModal";

/**
 * Redes sociais (Instagram e Facebook) nas Integrações: ligação separada da dos
 * anúncios, só para ler os seguidores da Página e do Instagram. Ligar, escolher as
 * contas e desligar: só o administrador da empresa (o backend decide em can_manage,
 * também falso em impersonation).
 */

const RETURN_MESSAGES: Record<string, { kind: "error" | "info"; text: string }> = {
    denied: { kind: "error", text: "Autorização cancelada no Facebook." },
    state: { kind: "error", text: "A sessão de ligação expirou. Tente novamente." },
    scopes: { kind: "error", text: "É preciso aceitar as três permissões pedidas (Páginas, interação das Páginas e Instagram). Volte a ligar e aceite todas." },
    not_approved: { kind: "info", text: SOCIAL_NOT_APPROVED_TEXT },
};

const int = (v: number) => new Intl.NumberFormat("pt-PT").format(v);

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

export default function SocialConnectionCard({ companyId }: { companyId: number }) {
    const [searchParams, setSearchParams] = useSearchParams();
    const [state, setState] = useState<SocialConnectionState | null>(null);
    const [loading, setLoading] = useState(true);
    const [connecting, setConnecting] = useState(false);
    const [choosing, setChoosing] = useState(false);
    const [modalMode, setModalMode] = useState<SocialDisconnectMode | null>(null);
    const [disconnecting, setDisconnecting] = useState(false);

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const r: any = await getSocialConnection(companyId);
            setState(r?.data ?? null);
        } catch {
            setState(null);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { void load(); }, [load]);

    // Retorno da autorização (o backend redireciona para cá com ?social=...). Tratado
    // uma só vez, mesmo que o efeito corra duas vezes.
    const handledReturn = useRef(false);
    useEffect(() => {
        const social = searchParams.get("social");
        if (!social || handledReturn.current) return;
        handledReturn.current = true;
        if (social === "choose") {
            // toastId fixo: o cartão pode montar duas vezes enquanto a página carrega.
            toast.success("Redes sociais autorizadas. Falta escolher as Páginas e as contas de Instagram.", { toastId: "social-return" });
            setChoosing(true);
        } else {
            const m = RETURN_MESSAGES[searchParams.get("reason") ?? ""] ?? { kind: "error", text: "Não foi possível ligar as redes sociais. Tente novamente." };
            m.kind === "info" ? toast.info(m.text, { autoClose: 10000, toastId: "social-return" }) : toast.error(m.text, { toastId: "social-return" });
        }
        const next = new URLSearchParams(searchParams);
        next.delete("social");
        next.delete("reason");
        setSearchParams(next, { replace: true });
    }, [searchParams, setSearchParams]);

    const connect = async () => {
        setConnecting(true);
        try {
            const r: any = await getSocialAuthUrl(companyId);
            if (!r?.data?.url) throw new Error();
            window.location.assign(r.data.url);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível iniciar a ligação às redes sociais."));
            setConnecting(false);
        }
    };

    const confirmDisconnect = async (options: { purge: boolean; confirmation?: string }) => {
        const mode = modalMode;
        setDisconnecting(true);
        try {
            const r: any = await disconnectSocial(companyId, options);
            toast.success(options.purge ? "Histórico automático de seguidores apagado." : "Redes sociais desligadas. O histórico foi mantido.");
            if (mode === "disconnect" && !r?.data?.permissions_revoked) {
                toast.warning("Não foi possível retirar as permissões na Meta (a sessão pode ter expirado). Pode removê-las no Facebook, em Definições, Integrações empresariais.");
            }
            setState(r?.data?.state ?? null);
            void load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível desligar as redes sociais."));
        } finally {
            setDisconnecting(false);
            setModalMode(null);
        }
    };

    const connected = !!state?.status && state.status !== "revoked";
    const view = connected ? socialStateView(state!.status, state!.last_read_at, state!.last_error_at, state!.last_error_kind) : null;
    const canManage = !!state?.can_manage;

    return (
        <Col md={6} xl={4}>
            <SocialAccountsModal
                isOpen={choosing}
                companyId={companyId}
                onClose={() => setChoosing(false)}
                onSaved={(s) => { setState(s); setChoosing(false); setTimeout(() => void load(), 4000); }}
            />
            <SocialDisconnectModal
                isOpen={modalMode !== null}
                mode={modalMode ?? "disconnect"}
                loading={disconnecting}
                onCancel={() => setModalMode(null)}
                onConfirm={(o) => void confirmDisconnect(o)}
            />
            <Card className="h-100 mb-0">
                <CardBody>
                    <div className="d-flex align-items-start justify-content-between gap-2 mb-3">
                        <div className="d-flex align-items-center gap-3">
                            <div className="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                style={{ width: 44, height: 44, background: "linear-gradient(135deg, #F58529, #DD2A7B 55%, #515BD4)" }}>
                                <i className="ri-instagram-line text-white fs-20" />
                            </div>
                            <div>
                                <h6 className="fw-semibold mb-0">Redes sociais (Instagram e Facebook)</h6>
                                <p className="text-muted fs-12 mb-0">Seguidores da Página e do Instagram</p>
                            </div>
                        </div>
                        {view && <Badge color={`${view.tone}-subtle`} className={`text-${view.tone} fs-11 text-wrap text-end`}>{view.badge}</Badge>}
                    </div>

                    <p className="text-muted fs-13 mb-3">
                        Lê todas as madrugadas o número de seguidores da Página de Facebook e da conta de Instagram profissional.
                        É uma ligação separada da dos anúncios e só lê: não publica nem altera nada.
                    </p>

                    {loading ? (
                        <div className="text-center py-2"><Spinner size="sm" /></div>
                    ) : connected ? (
                        <div className="vstack gap-2">
                            {view && (
                                <div className={`alert alert-${view.tone} fs-12 py-2 px-3 mb-0`} role="status">{view.text}</div>
                            )}
                            {state!.accounts.map((a) => (
                                <div key={a.id} className="d-flex align-items-center justify-content-between gap-2 p-2 rounded"
                                    style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}>
                                    <span className="text-truncate">
                                        <i className={`${a.platform === "instagram" ? "ri-instagram-line text-danger" : "ri-facebook-circle-line text-primary"} me-1`} />
                                        {a.username ? `@${a.username}` : a.name}
                                        {a.is_primary && <Badge color="light" className="text-body fw-normal ms-1">Principal</Badge>}
                                    </span>
                                    <span className="fw-medium text-nowrap">
                                        {a.last_followers_count !== null ? `${int(a.last_followers_count)} seguidores` : <span className="text-muted">Sem leitura</span>}
                                    </span>
                                </div>
                            ))}
                            {canManage ? (
                                <>
                                    {view?.reconnect ? (
                                        <button className="btn btn-primary w-100 mt-1" onClick={connect} disabled={connecting}>
                                            {connecting ? <Spinner size="sm" className="me-1" /> : <i className="ri-refresh-line me-1" />}Voltar a ligar
                                        </button>
                                    ) : (
                                        <button className="btn btn-soft-primary btn-sm mt-1" onClick={() => setChoosing(true)}>
                                            <i className="ri-list-check-2 me-1" />{state!.status === "pending_selection" ? "Escolher as contas" : "Alterar as contas"}
                                        </button>
                                    )}
                                    <button type="button" className="btn btn-soft-danger btn-sm" onClick={() => setModalMode("disconnect")}>
                                        <i className="ri-unlink me-1" />Desligar
                                    </button>
                                </>
                            ) : (
                                <p className="text-muted fs-12 mb-0">Só o administrador da empresa pode alterar ou desligar as redes sociais.</p>
                            )}
                        </div>
                    ) : canManage ? (
                        <div className="vstack gap-2">
                            <button className="btn btn-primary w-100" onClick={connect} disabled={connecting} style={{ background: "#1877F2", borderColor: "#1877F2" }}>
                                {connecting ? <Spinner size="sm" className="me-2" /> : <i className="ri-facebook-fill me-2" />}Ligar com Facebook
                            </button>
                            {state?.has_automatic_history && (
                                <button type="button" className="btn btn-link btn-sm p-0 text-start fs-12 text-danger" onClick={() => setModalMode("purge")}>
                                    <i className="ri-delete-bin-line me-1" />Apagar o histórico de seguidores lido automaticamente
                                </button>
                            )}
                        </div>
                    ) : (
                        <p className="text-muted fs-12 mb-0">Só o administrador da empresa pode ligar as redes sociais.</p>
                    )}
                </CardBody>
            </Card>
        </Col>
    );
}
