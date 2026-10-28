import { useState } from "react";
import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getMailboxLinkContext } from "helpers/laravel_helper";
import { isImpersonating } from "helpers/impersonation";
import { sessionUser } from "helpers/roles";
import ComposeModal, { AttachmentLimits } from "./ComposeModal";

/**
 * E-mail 9 · "Enviar por email" ao lado de "Copiar link", nos cartões do financiamento e da retoma:
 * abre a janela de escrita (ComposeModal) já preenchida com o endereço do cliente, o assunto e um texto
 * curto com o link e o prazo (a assinatura junta-se no fim). A pessoa pode mudar o texto, mas não tirar
 * o link. Sem caixa configurada, leva a "Ligar a minha caixa". Sem consentimento de contacto, o aviso
 * e o envio desligado. Na cópia guardada na XPLENDOR o link fica escondido.
 */
type Context = {
    to: string | null; name: string | null; consent: boolean; subject: string; body: string; expires_at: string;
    link: { kind: "financing" | "trade_in"; id: number }; mailbox: { email: string; signature: string | null } | null;
    blocked: string | null; attachments?: AttachmentLimits;
};
export const NO_CONSENT = "O cliente não deu consentimento para contacto (RGPD): não se pode escrever-lhe.";

export default function SendLinkByEmail({ companyId, kind, linkId, variant = "button", onSent }: {
    companyId: number; kind: "financing" | "trade_in"; linkId: number; variant?: "button" | "link"; onSent?: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [context, setContext] = useState<Context | null>(null);
    const [noMailbox, setNoMailbox] = useState(false);
    if (isImpersonating()) return null; // a caixa é pessoal: nunca durante a impersonation

    const open = async () => {
        setBusy(true);
        try {
            const r: any = await getMailboxLinkContext(companyId, kind, linkId);
            if (!r?.data?.mailbox) setNoMailbox(true); else setContext(r.data);
        } catch (e: any) {
            toast.error(e?.message || "Não foi possível preparar a mensagem.");
        } finally {
            setBusy(false);
        }
    };
    const label = busy ? <Spinner size="sm" /> : <><i className="ri-mail-send-line me-1" />Enviar por email</>;

    return (
        <>
            {variant === "link"
                ? <button type="button" className="btn btn-link btn-sm p-0" onClick={open} disabled={busy} data-testid={`send-link-${linkId}`}>{label}</button>
                : <Button size="sm" color="outline-primary" onClick={open} disabled={busy} data-testid={`send-link-${linkId}`}>{label}</Button>}
            {context && (
                <ComposeModal isOpen toggle={() => setContext(null)} companyId={companyId}
                    title={kind === "financing" ? "Enviar o link do financiamento" : "Enviar o link da retoma"}
                    initial={{ to: context.to ?? "", subject: context.subject, body: context.body }} signature={context.mailbox?.signature ?? null}
                    blocked={!context.consent ? NO_CONSENT : context.blocked} limits={context.attachments} link={context.link} onSent={() => onSent?.()} />
            )}
            <Modal isOpen={noMailbox} toggle={() => setNoMailbox(false)} centered data-testid="send-link-no-mailbox">
                <ModalHeader toggle={() => setNoMailbox(false)}>Ligue a sua caixa de correio</ModalHeader>
                <ModalBody>
                    Para enviar o link por email a partir da XPLENDOR, ligue primeiro a sua caixa (Gmail ou o e-mail do domínio da empresa) no seu Perfil. Até lá, pode copiar o link e enviá-lo como costuma.
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setNoMailbox(false)}>Fechar</Button>
                    <a href={`/app/users/${sessionUser()?.id ?? ""}#mailbox`} className="btn btn-primary"><i className="ri-link me-1" />Ligar a minha caixa</a>
                </ModalFooter>
            </Modal>
        </>
    );
}
