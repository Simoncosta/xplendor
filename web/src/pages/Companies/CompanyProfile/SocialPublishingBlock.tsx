import { useState } from "react";
import { Badge, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { disconnectSocialPublishing, getSocialPublishAuthUrl } from "helpers/laravel_helper";
import { SocialPublishingState } from "common/models/socialConnection.model";

/**
 * F2.8 · "Ligar publicação" nas Redes sociais: uma autorização à parte, que pede as permissões de
 * publicar (instagram_content_publish e pages_manage_posts) sem mexer na ligação de leitura. Só
 * aparece com a leitura ligada; só o administrador liga ou desliga.
 */

export const PUBLISH_RETURN_MESSAGES: Record<string, string> = {
    publish_scopes: "É preciso aceitar as permissões de publicar no Instagram e na Página. Volte a ligar a publicação e aceite todas.",
    publish_pages: "A autorização não inclui as Páginas escolhidas nas Redes sociais. Volte a ligar a publicação e escolha as mesmas Páginas.",
};

const LABEL: Record<string, string> = { instagram: "Instagram", facebook: "Facebook" };

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

export default function SocialPublishingBlock({ companyId, state, canManage, onChanged }: {
    companyId: number; state: SocialPublishingState | undefined; canManage: boolean; onChanged: (s: SocialPublishingState) => void;
}) {
    const [busy, setBusy] = useState(false);
    const connected = !!state?.connected;

    const connect = async () => {
        setBusy(true);
        try {
            const r: any = await getSocialPublishAuthUrl(companyId);
            if (!r?.data?.url) throw new Error();
            window.location.assign(r.data.url);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível iniciar a ligação da publicação."));
            setBusy(false);
        }
    };

    const disconnect = async () => {
        setBusy(true);
        try {
            const r: any = await disconnectSocialPublishing(companyId);
            toast.success("Publicação desligada. A leitura das redes sociais continua.");
            if (r?.data?.permission_kept_shared) {
                toast.info("As permissões de publicar foram mantidas na Meta: o mesmo utilizador do Facebook publica noutra empresa da XPLENDOR.");
            }
            if (r?.data?.publishing) onChanged(r.data.publishing);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível desligar a publicação."));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="border rounded p-2 mt-2" data-testid="social-publishing">
            <div className="d-flex align-items-center justify-content-between gap-2">
                <span className="fw-medium fs-13"><i className="ri-send-plane-line me-1" />Publicação automática</span>
                <Badge color={connected ? "success-subtle" : "light"} className={connected ? "text-success" : "text-body"}>
                    {connected ? "Ligada" : "Por ligar"}
                </Badge>
            </div>
            <p className="text-muted fs-12 mb-2 mt-1">
                {connected
                    ? `A XPLENDOR pode publicar no ${(state?.platforms ?? []).map((p) => LABEL[p] ?? p).join(" e ")} as publicações programadas com a caixa "Publicar automaticamente".`
                    : "Uma ligação à parte, só para publicar as publicações programadas. A leitura dos seguidores continua igual."}
            </p>
            {canManage ? (
                connected ? (
                    <button className="btn btn-outline-danger btn-sm" onClick={() => void disconnect()} disabled={busy}>
                        {busy ? <Spinner size="sm" className="me-1" /> : <i className="ri-link-unlink me-1" />}Desligar publicação
                    </button>
                ) : (
                    <button className="btn btn-outline-primary btn-sm" onClick={() => void connect()} disabled={busy}>
                        {busy ? <Spinner size="sm" className="me-1" /> : <i className="ri-facebook-fill me-1" />}Ligar publicação
                    </button>
                )
            ) : (
                <p className="text-muted fs-12 mb-0">Só o administrador da empresa pode ligar ou desligar a publicação.</p>
            )}
        </div>
    );
}
