import { useCallback, useEffect, useState } from "react";
import { Badge, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import ReasonButton from "Components/Common/ReasonButton";
import Skeleton from "Components/Common/Skeleton";
import { confirmAction } from "helpers/swal";
import { disconnectAgencyMeta, getAgencyMeta, getAgencyMetaAuthUrl } from "helpers/laravel_helper";

/**
 * Parceiro 2: a ligação da Meta da agência (uma por agência). Quem é da agência vê o estado; só o
 * administrador da agência liga e desliga. Desligar é a única ação que retira permissões na Meta.
 */
export type AgencyMetaState = {
    connected: boolean; status: "active" | "expired" | "permission" | null; meta_user_id: string | null;
    scopes: string[]; missing_scopes: string[]; connected_at: string | null; connected_by: string | null;
    expires_at: string | null; days_left: number | null; renew_soon: boolean;
};

const SCOPE_LABELS: Record<string, string> = {
    business_management: "Portfólios da Meta (business_management)",
    pages_show_list: "Lista das Páginas (pages_show_list)",
    pages_read_engagement: "Leitura das Páginas (pages_read_engagement)",
    instagram_basic: "Instagram (instagram_basic)",
    ads_read: "Leitura dos anúncios (ads_read)",
};
const CALLBACK_ERRORS: Record<string, string> = {
    denied: "A autorização foi cancelada na Meta.",
    scopes: "A Meta não deu todas as permissões pedidas. Nada foi guardado; volte a ligar e aceite todas.",
    token: "A Meta não devolveu o acesso. Tente novamente dentro de momentos.",
};
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
const errorText = (e: any, fallback: string) => String((e?.errors ? Object.values(e.errors).flat()[0] : null) ?? e?.message ?? fallback);

export default function AgencyMetaTab({ agencyId, canManage, manageReason }: { agencyId: number; canManage: boolean; manageReason: string }) {
    const [state, setState] = useState<AgencyMetaState | null>(null);
    const [busy, setBusy] = useState(false);
    const load = useCallback(async () => {
        try {
            const res: any = await getAgencyMeta(agencyId);
            setState(res?.data ?? null);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível carregar a ligação da Meta."));
        }
    }, [agencyId]);
    useEffect(() => { void load(); }, [load]);

    // Volta da Meta: /agency?tab=meta&meta=ok|error&reason=…
    useEffect(() => {
        const q = new URLSearchParams(window.location.search);
        const meta = q.get("meta");
        if (!meta) return;
        if (meta === "ok") toast.success("Ligação da Meta da agência guardada.");
        else toast.error(CALLBACK_ERRORS[q.get("reason") ?? ""] ?? "Não foi possível ligar a Meta.");
        q.delete("meta");
        q.delete("reason");
        window.history.replaceState(null, "", `${window.location.pathname}?${q.toString()}`);
    }, []);

    const connect = async () => {
        setBusy(true);
        try {
            const res: any = await getAgencyMetaAuthUrl(agencyId);
            if (res?.data?.url) window.location.assign(res.data.url);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível abrir a Meta."));
            setBusy(false);
        }
    };
    const disconnect = async () => {
        const ok = await confirmAction({
            title: "Desligar a Meta da agência?",
            text: "A agência deixa de ler os portfólios e os ativos partilhados. As permissões são retiradas na Meta, salvo as que outras ligações do mesmo utilizador usam.",
            confirmText: "Desligar", icon: "warning", confirmVariant: "danger",
        });
        if (!ok) return;
        setBusy(true);
        try {
            const res: any = await disconnectAgencyMeta(agencyId);
            toast.success(res?.message ?? "Ligação da Meta desligada.");
            setState(res?.data?.meta ?? null);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível desligar."));
        } finally {
            setBusy(false);
        }
    };

    if (!state) return <Skeleton variant="lines" lines={4} className="py-2" />;
    const statusBadge = !state.connected ? <Badge color="light" className="text-muted">Por ligar</Badge>
        : state.status === "expired" ? <Badge color="danger-subtle" className="text-danger">Expirada</Badge>
            : <Badge color="success-subtle" className="text-success">Ligada</Badge>;

    return (
        <PageCard title="Ligação da Meta da agência" data-testid="agency-meta" status={statusBadge}
            info="Uma ligação por agência, feita por um administrador da agência com o seu login da Meta. Serve para ver os portfólios e os ativos que os clientes partilharam com a agência. Desligar uma empresa nunca retira permissões na Meta; só desligar esta ligação o faz.">
            <div className="p-3">
                {state.connected ? (
                    <>
                        {state.status === "expired" && (
                            <div className="alert alert-danger fs-13" role="alert" data-testid="agency-meta-expired">O acesso à Meta expirou. Volte a ligar para continuar a ver os portfólios.</div>
                        )}
                        {state.status !== "expired" && state.renew_soon && (
                            <div className="alert alert-warning fs-13" role="status" data-testid="agency-meta-renew">
                                A ligação acaba dentro de {state.days_left} {state.days_left === 1 ? "dia" : "dias"} ({fmtDate(state.expires_at)}). Volte a ligar para a renovar.
                            </div>
                        )}
                        <dl className="row fs-13 mb-3">
                            <dt className="col-sm-4 text-muted fw-normal">Utilizador da Meta</dt><dd className="col-sm-8">{state.meta_user_id ?? "Desconhecido"}</dd>
                            <dt className="col-sm-4 text-muted fw-normal">Ligada por</dt><dd className="col-sm-8">{state.connected_by ?? "Desconhecido"}{state.connected_at ? `, a ${fmtDate(state.connected_at)}` : ""}</dd>
                            <dt className="col-sm-4 text-muted fw-normal">Válida até</dt><dd className="col-sm-8">{state.expires_at ? fmtDate(state.expires_at) : "Sem data de fim indicada pela Meta"}</dd>
                        </dl>
                        <ul className="list-unstyled fs-13 mb-3" data-testid="agency-meta-scopes">
                            {state.scopes.map((s) => <li key={s}><i className="ri-check-line text-success me-1" aria-hidden />{SCOPE_LABELS[s] ?? s}</li>)}
                            {state.missing_scopes.map((s) => <li key={s} className="text-danger"><i className="ri-close-line me-1" aria-hidden />{SCOPE_LABELS[s] ?? s}</li>)}
                        </ul>
                    </>
                ) : (
                    <p className="text-muted fs-13">A agência ainda não ligou a Meta. Um administrador da agência liga com o seu login; a Meta pede as permissões dos portfólios, das Páginas, do Instagram e da leitura dos anúncios.</p>
                )}
                <div className="d-flex flex-wrap gap-2">
                    <ReasonButton color="primary" reason={canManage ? null : manageReason} disabled={busy} onClick={connect} data-testid="agency-meta-connect">
                        {busy ? <Spinner size="sm" /> : state.connected ? "Voltar a ligar" : "Ligar com a Meta"}
                    </ReasonButton>
                    {state.connected && (
                        <ReasonButton color="danger" outline reason={canManage ? null : manageReason} disabled={busy} onClick={disconnect} data-testid="agency-meta-disconnect">Desligar</ReasonButton>
                    )}
                </div>
            </div>
        </PageCard>
    );
}
