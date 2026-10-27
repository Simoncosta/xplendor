import { useEffect, useState } from "react";
import { Card, CardBody, Spinner } from "reactstrap";
import { getCarMailMessages, getCustomerMailMessages } from "helpers/laravel_helper";
import MessageFrame from "pages/Email/inbox/MessageFrame";

/**
 * E-mail 4 · A secção "Conversa": as mensagens guardadas porque se ligam ao cliente ou à venda (as
 * enviadas pela XPLENDOR, as respostas ligadas pelo fio e as ligadas à mão). A equipa XPLENDOR fora
 * da própria empresa e a impersonation não a veem (o backend recusa: a secção fica escondida).
 */
type Msg = { id: number; direction: "in" | "out"; from: string; to: string; subject: string; date: string | null; text: string | null; html: string | null; status: string };

export default function MailConversation({ companyId, customerId, carId }: { companyId: number; customerId?: number; carId?: number }) {
    const [messages, setMessages] = useState<Msg[] | null>(null);
    const [hidden, setHidden] = useState(false);
    const [open, setOpen] = useState<number | null>(null);

    useEffect(() => {
        let alive = true;
        const req = customerId ? getCustomerMailMessages(companyId, customerId) : carId ? getCarMailMessages(companyId, carId) : null;
        if (!req) return;
        req.then((r: any) => { if (alive) setMessages(r?.data?.messages ?? []); })
            .catch(() => { if (alive) setHidden(true); });
        return () => { alive = false; };
    }, [companyId, customerId, carId]);

    if (hidden) return null;

    return (
        <Card className="mb-3" data-testid="mail-conversation">
            <CardBody>
                <h5 className="card-title mb-3"><i className="ri-mail-line me-1" />Conversa</h5>
                {messages === null ? (
                    <div className="text-muted fs-13"><Spinner size="sm" className="me-2" />A carregar…</div>
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
        </Card>
    );
}
