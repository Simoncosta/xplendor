import { useState } from "react";
import { Badge, Input, Label, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { disconnectSocialPublishing, getSocialPublishAuthUrl, setSocialAutoPublishing } from "helpers/laravel_helper";
import { SocialPublishingState } from "common/models/socialConnection.model";

/**
 * F2.8 · "Ligar publicação" nas Redes sociais: uma autorização à parte, que pede as permissões de
 * publicar (instagram_content_publish e pages_manage_posts) sem mexer na ligação de leitura. Só se
 * liga com a leitura ligada e as contas escolhidas. Ao lado, o interruptor "Publicação automática"
 * da empresa. Ligar, desligar e o interruptor: quem tem integracoes.configurar (can_manage).
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

export default function SocialPublishingBlock({ companyId, state, canManage, readReady, onChanged }: {
    companyId: number; state: SocialPublishingState | undefined; canManage: boolean;
    /** A leitura está ligada e com as contas escolhidas (só então se pode "Ligar publicação"). */
    readReady: boolean;
    onChanged: (s: SocialPublishingState) => void;
}) {
    const [busy, setBusy] = useState(false);
    const connected = !!state?.connected;
    const enabled = !!state?.auto_publishing_enabled;

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

    const toggle = async (value: boolean) => {
        setBusy(true);
        try {
            const r: any = await setSocialAutoPublishing(companyId, value);
            toast.success(value ? "Publicação automática ligada." : "Publicação automática desligada.");
            if (r?.data?.publishing) onChanged(r.data.publishing);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível mudar a publicação automática."));
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

            <div className="form-check form-switch mb-1">
                <Input type="checkbox" role="switch" className="form-check-input" id={`auto-publishing-${companyId}`} disabled={!canManage || busy}
                    checked={enabled} onChange={(e) => void toggle(e.target.checked)} />
                <Label className="form-check-label fs-13" for={`auto-publishing-${companyId}`}>Publicar sozinha no Instagram e no Facebook</Label>
            </div>
            <p className="text-muted fs-12 mb-2">
                {enabled
                    ? "Ligada: sai sozinha a publicação Programada, com hora futura e a caixa \"Publicar automaticamente\" marcada no Planeamento."
                    : "Desligada: todas as publicações continuam manuais (marcar como publicada e colar o link)."}
                {enabled && !connected && " Falta \"Ligar publicação\": sem ela, as publicações agendadas falham à hora."}
            </p>

            {canManage ? (
                connected ? (
                    <button className="btn btn-outline-danger btn-sm" onClick={() => void disconnect()} disabled={busy}>
                        {busy ? <Spinner size="sm" className="me-1" /> : <i className="ri-link-unlink me-1" />}Desligar publicação
                    </button>
                ) : readReady ? (
                    <button className="btn btn-outline-primary btn-sm" onClick={() => void connect()} disabled={busy}>
                        {busy ? <Spinner size="sm" className="me-1" /> : <i className="ri-facebook-fill me-1" />}Ligar publicação
                    </button>
                ) : (
                    <p className="text-muted fs-12 mb-0">Para "Ligar publicação", primeiro ligue as redes sociais e escolha as contas (em cima).</p>
                )
            ) : (
                <p className="text-muted fs-12 mb-0">Só quem configura as integrações da empresa liga ou desliga a publicação e o interruptor.</p>
            )}
        </div>
    );
}
