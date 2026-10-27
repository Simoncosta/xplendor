import { useState } from "react";
import { Form, Formik } from "formik";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XInputTextareaQuill from "Components/Common/XInputTextareaQuill";
import { sendMailboxMessage } from "helpers/laravel_helper";

/**
 * E-mail 5 · Escrever uma mensagem pela minha caixa: "Escrever" (na caixa), "Responder" (no mesmo
 * fio, com o original citado por baixo, que o servidor junta) e "Escrever ao cliente" (na ficha e na
 * venda: fica ligada ao cliente e à venda). O editor é o Quill da app, só com texto e ligações (sem
 * anexos nesta versão). A assinatura da caixa vai no fim, e mostra-se por baixo do editor. Se o
 * servidor recusar (consentimento, limites, endereço), o motivo aparece aqui e o texto fica.
 */
export type ComposeValues = { to: string; cc: string; subject: string; body: string };

const MODULES = { toolbar: [["bold", "italic", "underline"], [{ list: "ordered" }, { list: "bullet" }], ["link"], ["clean"]] };

export default function ComposeModal({ isOpen, toggle, companyId, title = "Nova mensagem", initial, replyTo, quoteHeader, customerId, carId, signature, blocked, onSent }: {
    isOpen: boolean; toggle: () => void; companyId: number; title?: string;
    initial?: Partial<ComposeValues>;
    replyTo?: { folder: string; uid: number } | null;
    /** "Em <data>, <nome> escreveu:" (a citação junta-se no servidor; aqui só se avisa). */
    quoteHeader?: string;
    customerId?: number; carId?: number;
    signature?: string | null;
    /** Um motivo que impede já o envio (sem consentimento, caixa por testar, limite): mostra-se e o botão fica desligado. */
    blocked?: string | null;
    onSent?: (message: any) => void;
}) {
    const [error, setError] = useState<string | null>(null);
    const [showCc, setShowCc] = useState(!!initial?.cc);

    const submit = async (values: ComposeValues, { setSubmitting }: { setSubmitting: (b: boolean) => void }) => {
        setError(null);
        try {
            const r: any = await sendMailboxMessage(companyId, { ...values, reply_to: replyTo ?? null, customer_id: customerId ?? null, car_id: carId ?? null });
            toast.success(r?.message || "Mensagem enviada.");
            onSent?.(r?.data?.message);
            toggle();
        } catch (e: any) {
            // O texto fica no formulário; só se mostra o motivo.
            const lists = e?.errors && typeof e.errors === "object" ? Object.values(e.errors).filter(Array.isArray) : [];
            setError((lists.flat()[0] as string | undefined) || e?.message || "Não foi possível enviar a mensagem.");
        } finally {
            setSubmitting(false);
        }
    };

    const empty = (html: string) => !html || !html.replace(/<[^>]*>/g, "").replace(/&nbsp;/g, " ").trim();

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered backdrop="static" data-testid="mail-compose">
            <Formik<ComposeValues> initialValues={{ to: "", cc: "", subject: "", body: "", ...initial }} onSubmit={submit}>
                {({ values, handleChange, isSubmitting }) => (
                    <Form>
                        <ModalHeader toggle={toggle}>{title}</ModalHeader>
                        <ModalBody>
                            {blocked && <Alert color="warning" className="py-2" data-testid="mail-compose-blocked">{blocked}</Alert>}
                            {error && <Alert color="danger" className="py-2" data-testid="mail-compose-error">{error}</Alert>}
                            <div className="mb-2 d-flex align-items-end gap-2">
                                <div className="flex-grow-1">
                                    <Label htmlFor="compose-to" className="form-label mb-1">Para</Label>
                                    <Input id="compose-to" name="to" value={values.to} onChange={handleChange} placeholder="nome@exemplo.pt (vários separados por vírgula)" autoComplete="off" />
                                </div>
                                {!showCc && <Button type="button" color="link" className="px-1 text-decoration-none" onClick={() => setShowCc(true)}>Cc</Button>}
                            </div>
                            {showCc && (
                                <div className="mb-2">
                                    <Label htmlFor="compose-cc" className="form-label mb-1">Cc</Label>
                                    <Input id="compose-cc" name="cc" value={values.cc} onChange={handleChange} autoComplete="off" />
                                </div>
                            )}
                            <div className="mb-3">
                                <Label htmlFor="compose-subject" className="form-label mb-1">Assunto</Label>
                                <Input id="compose-subject" name="subject" value={values.subject} onChange={handleChange} maxLength={500} />
                            </div>
                            <XInputTextareaQuill name="body" height={220} modules={MODULES} placeholder="Escreva a mensagem" />
                            {signature && (
                                <pre className="text-muted fs-13 mt-2 mb-0" style={{ whiteSpace: "pre-wrap", fontFamily: "inherit" }} data-testid="mail-compose-signature">{`-- \n${signature}`}</pre>
                            )}
                            {replyTo && (
                                <div className="text-muted fs-12 mt-2" data-testid="mail-compose-quote">
                                    <i className="ri-double-quotes-l me-1" />{quoteHeader ? `${quoteHeader} ` : ""}A mensagem original vai citada por baixo.
                                </div>
                            )}
                            <div className="text-muted fs-12 mt-2">Sem anexos nesta versão.</div>
                        </ModalBody>
                        <ModalFooter>
                            <Button type="button" color="light" onClick={toggle} disabled={isSubmitting}>Cancelar</Button>
                            <Button type="submit" color="primary" disabled={isSubmitting || !!blocked || !values.to.trim() || empty(values.body)}>
                                {isSubmitting ? <Spinner size="sm" /> : <><i className="ri-send-plane-2-line me-1" />Enviar</>}
                            </Button>
                        </ModalFooter>
                    </Form>
                )}
            </Formik>
        </Modal>
    );
}

/** "Re: " uma só vez (sem acumular "Re: Re:" nem duplicar um "RE:"/"RES:" de outro programa). */
export function replySubject(subject: string): string {
    const s = (subject || "").trim();
    return /^(re|res|aw|sv)\s*:/i.test(s) ? s : `Re: ${s || "(sem assunto)"}`;
}

/** "Em 27 de outubro de 2026, às 17:00, Nome escreveu:" (a mesma frase que o servidor põe na citação). */
export function quoteHeaderFor(date: string | null, from: { name: string | null; address: string | null }): string {
    const who = from.name || from.address || "";
    if (!date) return `Em data desconhecida, ${who} escreveu:`;
    const d = new Date(date);
    const day = d.toLocaleDateString("pt-PT", { day: "numeric", month: "long", year: "numeric", timeZone: "Europe/Lisbon" });
    const time = d.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Lisbon" });
    return `Em ${day}, às ${time}, ${who} escreveu:`;
}
