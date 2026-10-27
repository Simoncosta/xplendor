import { useEffect, useState } from "react";
import { Button, Card, CardBody, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getCarMailMessages, getCustomerMailMessages, getMailboxComposeContext } from "helpers/laravel_helper";
import ComposeModal, { AttachmentLimits, fmtSize } from "./ComposeModal";
import MessageFrame from "pages/Email/inbox/MessageFrame";
import { ConversationSkeleton } from "pages/Email/inbox/skeletons";

/**
 * E-mail 4 · A secção "Conversa": as mensagens guardadas porque se ligam ao cliente ou à venda (as
 * enviadas pela XPLENDOR, as respostas ligadas pelo fio e as ligadas à mão). A equipa XPLENDOR fora
 * da própria empresa e a impersonation não a veem (o backend recusa: a secção fica escondida).
 * E-mail 5: "Escrever ao cliente", pela minha caixa; a mensagem fica ligada ao cliente (e à venda).
 * Sem consentimento de contacto, a janela abre com o aviso e não deixa enviar.
 */
type Context = { to: string | null; name: string | null; consent: boolean; blocked: string | null; mailbox: { email: string; signature: string | null } | null; attachments?: AttachmentLimits };
export const NO_CONSENT = "O cliente não deu consentimento para contacto (RGPD): não se pode escrever-lhe.";

type Msg = { id: number; direction: "in" | "out"; from: string; to: string; subject: string; date: string | null; text: string | null; html: string | null; status: string;
    /** E-mail 5b: só o nome e o tamanho (o ficheiro vive na pasta Enviados da caixa). */
    attachments?: { name: string; size: number }[] };

export default function MailConversation({ companyId, customerId, carId }: { companyId: number; customerId?: number; carId?: number }) {
    const [messages, setMessages] = useState<Msg[] | null>(null);
    const [hidden, setHidden] = useState(false);
    const [open, setOpen] = useState<number | null>(null);
    const [reload, setReload] = useState(0);
    const [context, setContext] = useState<Context | null>(null);
    const [opening, setOpening] = useState(false);

    const write = async () => {
        setOpening(true);
        try {
            const r: any = await getMailboxComposeContext(companyId, { customerId, carId });
            setContext(r?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message || "Não foi possível preparar a mensagem.");
        } finally {
            setOpening(false);
        }
    };

    useEffect(() => {
        let alive = true;
        const req = customerId ? getCustomerMailMessages(companyId, customerId) : carId ? getCarMailMessages(companyId, carId) : null;
        if (!req) return;
        req.then((r: any) => { if (alive) setMessages(r?.data?.messages ?? []); })
            .catch(() => { if (alive) setHidden(true); });
        return () => { alive = false; };
    }, [companyId, customerId, carId, reload]);

    if (hidden) return null;

    return (
        <Card className="mb-3" data-testid="mail-conversation">
            <CardBody>
                <div className="d-flex align-items-center mb-3">
                    <h5 className="card-title mb-0 flex-grow-1"><i className="ri-mail-line me-1" />Conversa</h5>
                    {messages !== null && (
                        <Button size="sm" color="outline-primary" onClick={write} disabled={opening} data-testid="mail-write-customer">
                            {opening ? <Spinner size="sm" /> : <><i className="ri-mail-send-line me-1" />Escrever ao cliente</>}
                        </Button>
                    )}
                </div>
                {messages === null ? (
                    <ConversationSkeleton />
                ) : messages.length === 0 ? (
                    <div className="text-muted fs-13">Ainda não há e-mails ligados. As mensagens enviadas pela XPLENDOR, as respostas e as que ligar na caixa de entrada aparecem aqui.</div>
                ) : (
                    <ul className="list-unstyled vstack gap-2 mb-0">
                        {messages.map((m) => (
                            <li key={m.id} className={`border rounded p-2 ${m.direction === "in" ? "bg-light-subtle" : ""}`}>
                                <button type="button" className="btn btn-link p-0 text-start w-100 text-decoration-none text-body" onClick={() => setOpen(open === m.id ? null : m.id)} aria-expanded={open === m.id}>
                                    <div className="d-flex align-items-center gap-2">
                                        <i className={m.direction === "in" ? "ri-mail-download-line text-success" : "ri-mail-send-line text-primary"} />
                                        <span className="fw-medium text-truncate flex-grow-1">{m.subject || "(sem assunto)"}</span>
                                        <small className="text-muted flex-shrink-0">{m.date ? new Date(m.date).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : ""}</small>
                                    </div>
                                    <small className="text-muted d-block">{m.direction === "in" ? `De ${m.from}` : `Para ${m.to}`}{m.status === "erro" ? " · não enviada" : ""}</small>
                                    {!!m.attachments?.length && (
                                        <small className="text-muted d-block text-truncate" data-testid="mail-conversation-attachments">
                                            <i className="ri-attachment-2 me-1" />{m.attachments.map((a) => `${a.name} (${fmtSize(a.size)})`).join(", ")}
                                        </small>
                                    )}
                                </button>
                                {open === m.id && (
                                    <div className="mt-2">
                                        {m.html ? <MessageFrame html={m.html} showImages={false} height={320} />
                                            : <pre className="mb-0 fs-13" style={{ whiteSpace: "pre-wrap", fontFamily: "inherit" }}>{m.text || ""}</pre>}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardBody>
            {context && (
                <ComposeModal isOpen toggle={() => setContext(null)} companyId={companyId} title={context.name ? `Escrever a ${context.name}` : "Escrever ao cliente"}
                    initial={{ to: context.to ?? "" }} customerId={customerId} carId={carId} signature={context.mailbox?.signature ?? null}
                    blocked={!context.consent ? NO_CONSENT : context.blocked} limits={context.attachments} onSent={() => setReload((n) => n + 1)} />
            )}
        </Card>
    );
}
