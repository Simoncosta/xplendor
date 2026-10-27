import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Button, Col, Modal, ModalBody, ModalHeader, Row } from "reactstrap";
import { toast } from "react-toastify";
import { mailboxAttachmentPath } from "helpers/laravel_helper";
import { downloadGet, fetchBlob } from "helpers/download_helper";
import MessageFrame from "./MessageFrame";
import { ThreadSkeleton } from "./skeletons";
import LinkCustomerModal from "./LinkCustomerModal";
import { MailAttachment, MailFull, fileSize, who } from "./types";

/**
 * E-mail 4 · A conversa aberta em página inteira, como no Gmail: o assunto em cima e as mensagens
 * umas por baixo das outras (da mais antiga para a mais recente). A mais recente e as por ler vêm
 * abertas; as outras mostram só quem enviou e o início do texto, e abrem com um clique. Em cada
 * mensagem: o HTML limpo num iframe isolado (as imagens externas só com "Mostrar imagens"), os
 * anexos ("Ver", ao vivo e sem guardar, e "Descarregar") e "Ligar a um cliente".
 */
export default function ThreadView({ companyId, myEmail, messages, loading, error, onBack, onUnread, onLinked }: {
    companyId: number; myEmail: string; messages: MailFull[]; loading: boolean; error: string | null;
    onBack: () => void; onUnread: () => void; onLinked: (uid: number, customer: { id: number; name: string }) => void;
}) {
    const ordered = [...messages].sort((a, b) => (a.date ?? "").localeCompare(b.date ?? ""));
    const latest = ordered[ordered.length - 1];
    const [open, setOpen] = useState<Set<number>>(new Set());
    const [preview, setPreview] = useState<{ name: string; mime: string; url: string } | null>(null);
    const [linking, setLinking] = useState<MailFull | null>(null);

    useEffect(() => {
        setOpen(new Set(ordered.filter((m, i) => i === ordered.length - 1 || !m.seen).map((m) => m.uid)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [messages.map((m) => m.uid).join(",")]);
    useEffect(() => () => { if (preview) URL.revokeObjectURL(preview.url); }, [preview]);

    const toggle = (uid: number) => setOpen((s) => { const n = new Set(s); if (n.has(uid)) n.delete(uid); else n.add(uid); return n; });
    const view = async (m: MailFull, a: MailAttachment) => {
        const r = await fetchBlob(mailboxAttachmentPath(companyId, m.uid, m.folder, a.index, true));
        if (!r.ok) { toast.error(r.body?.message || "Não foi possível abrir o anexo."); return; }
        setPreview({ name: a.name, mime: a.mime, url: URL.createObjectURL(new Blob([r.blob], { type: a.mime })) });
    };
    const download = async (m: MailFull, a: MailAttachment) => {
        const r = await downloadGet(mailboxAttachmentPath(companyId, m.uid, m.folder, a.index, false), a.name);
        if (!r.ok) toast.error(r.body?.message || "Não foi possível descarregar o anexo.");
    };
    const mine = (m: MailFull) => !!m.from.address && m.from.address.toLowerCase() === myEmail.toLowerCase();
    const recipients = (m: MailFull) => {
        const to = m.to.map((a) => (a.address && a.address.toLowerCase() === myEmail.toLowerCase() ? "mim" : a.name || a.address)).filter(Boolean);
        return to.length ? `para ${to.join(", ")}` : "";
    };

    return (
        <div className="email-content" data-testid="mail-detail">
            <div className="p-4">
                <div className="d-flex align-items-center gap-1 pb-3 border-bottom border-bottom-dashed email-topbar-link">
                    <button type="button" className="btn btn-ghost-secondary btn-icon btn-sm fs-18" onClick={onBack} aria-label="Voltar" title="Voltar"><i className="ri-arrow-left-line" /></button>
                    {latest && <button type="button" className="btn btn-ghost-secondary btn-sm" onClick={onUnread}><i className="ri-mail-unread-line me-1" />Marcar como por ler</button>}
                </div>

                {loading ? (
                    <ThreadSkeleton />
                ) : error ? (
                    <Alert color="danger" className="mt-3">{error}</Alert>
                ) : latest ? (
                    <>
                        <div className="d-flex align-items-center gap-2 mt-3 mb-3">
                            <h4 className="mb-0 flex-grow-1" data-testid="mail-subject">{latest.subject || "(sem assunto)"}</h4>
                            {ordered.length > 1 && <span className="badge bg-light text-muted border" data-testid="mail-thread-size">{ordered.length} mensagens</span>}
                        </div>
                        {ordered.map((m) => {
                            const expanded = open.has(m.uid);
                            return (
                                <div key={`${m.folder}:${m.uid}`} className="border-bottom py-3" data-testid="mail-message">
                                    <div className="d-flex align-items-start gap-3" role="button" onClick={() => toggle(m.uid)}>
                                        <div className="avatar-xs flex-shrink-0">
                                            <span className={`avatar-title rounded-circle ${mine(m) ? "bg-primary-subtle text-primary" : "bg-info-subtle text-info"} fs-14`}>
                                                {(who(m.from)[0] || "?").toUpperCase()}
                                            </span>
                                        </div>
                                        <div className="flex-grow-1 overflow-hidden">
                                            <div>
                                                <strong>{mine(m) ? "Eu" : who(m.from)}</strong>
                                                {expanded && m.from.address && <span className="text-muted fs-12 ms-1">&lt;{m.from.address}&gt;</span>}
                                            </div>
                                            <div className="text-muted fs-12 text-truncate">
                                                {expanded ? <>{recipients(m)}{m.cc.length > 0 && <> · cc: {m.cc.map((a) => a.address).join(", ")}</>}</> : snippet(m)}
                                            </div>
                                        </div>
                                        <div className="text-muted fs-12 text-end flex-shrink-0">
                                            {m.attachments.length > 0 && <i className="ri-attachment-2 me-1" title="Tem anexos" />}
                                            {m.date ? new Date(m.date).toLocaleString("pt-PT", { dateStyle: "medium", timeStyle: "short" }) : ""}
                                        </div>
                                    </div>
                                    {expanded && (
                                        <div className="mt-3" style={{ paddingLeft: 44 }}>
                                            <div className="d-flex gap-2 mb-2">
                                                {m.customer
                                                    ? <span className="badge bg-primary-subtle text-primary" data-testid="mail-customer"><i className="ri-user-line me-1" />Ligada a <Link to={`/customers/${m.customer.id}`}>{m.customer.name}</Link></span>
                                                    : <button type="button" className="btn btn-sm btn-ghost-primary px-1 py-0" onClick={() => setLinking(m)}><i className="ri-user-shared-line me-1" />Ligar a um cliente</button>}
                                            </div>
                                            <MessageBody message={m} />
                                            {m.attachments.length > 0 && (
                                                <div className="mt-3" data-testid="mail-attachments">
                                                    <div className="fs-13 text-muted mb-2">{m.attachments.length === 1 ? "1 anexo" : `${m.attachments.length} anexos`}</div>
                                                    <Row className="g-2">
                                                        {m.attachments.map((a) => (
                                                            <Col key={a.index} xs={12} sm={6} lg={4} xxl={3}>
                                                                <div className="border rounded p-2 h-100 d-flex flex-column">
                                                                    <div className="d-flex align-items-center gap-2 mb-2">
                                                                        <i className={`fs-24 ${a.mime === "application/pdf" ? "ri-file-pdf-2-line text-danger" : a.mime.startsWith("image/") ? "ri-image-line text-info" : "ri-file-line text-muted"}`} />
                                                                        <div className="overflow-hidden"><div className="text-truncate fs-13" title={a.name}>{a.name}</div><small className="text-muted">{fileSize(a.size)}</small></div>
                                                                    </div>
                                                                    <div className="d-flex gap-1 mt-auto">
                                                                        {a.previewable && <Button size="sm" color="outline-primary" onClick={() => view(m, a)}><i className="ri-eye-line me-1" />Ver</Button>}
                                                                        <Button size="sm" color="outline-secondary" onClick={() => download(m, a)}><i className="ri-download-2-line me-1" />Descarregar</Button>
                                                                    </div>
                                                                </div>
                                                            </Col>
                                                        ))}
                                                    </Row>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </>
                ) : null}
            </div>

            <Modal isOpen={!!preview} toggle={() => setPreview(null)} size="xl" centered>
                <ModalHeader toggle={() => setPreview(null)}>{preview?.name}</ModalHeader>
                <ModalBody className="p-0" style={{ height: "75vh" }} data-testid="mail-preview">
                    {preview?.mime === "application/pdf"
                        ? <iframe title={preview.name} src={preview.url} style={{ width: "100%", height: "100%", border: 0 }} referrerPolicy="no-referrer" />
                        : preview ? <div className="h-100 d-flex align-items-center justify-content-center bg-light"><img src={preview.url} alt={preview.name} style={{ maxWidth: "100%", maxHeight: "100%" }} /></div> : null}
                </ModalBody>
            </Modal>

            {linking && <LinkCustomerModal isOpen toggle={() => setLinking(null)} companyId={companyId} uid={linking.uid} folder={linking.folder}
                onLinked={(c) => { onLinked(linking.uid, c); setLinking(null); }} />}
        </div>
    );
}

/** O início do texto (para as mensagens fechadas da conversa), do texto ou, sem ele, do HTML. */
export function snippet(m: Pick<MailFull, "text" | "html">): string {
    const text = m.text || new DOMParser().parseFromString(m.html || "", "text/html").body.textContent || "";
    return text.replace(/\s+/g, " ").trim().slice(0, 140);
}

/** O corpo de uma mensagem: o HTML (com o aviso das imagens externas) ou o texto. */
function MessageBody({ message }: { message: MailFull }) {
    const [showImages, setShowImages] = useState(false);
    if (!message.html) {
        return <pre className="mb-0" style={{ whiteSpace: "pre-wrap", fontFamily: "inherit" }} data-testid="mail-text">{message.text || ""}</pre>;
    }
    return (
        <>
            {message.external_images > 0 && !showImages && (
                <Alert color="warning" className="py-2 d-flex align-items-center gap-2" data-testid="mail-images-blocked">
                    <span className="flex-grow-1">As imagens externas estão bloqueadas, para quem enviou não saber que abriu a mensagem.</span>
                    <Button size="sm" color="warning" onClick={() => setShowImages(true)}>Mostrar imagens</Button>
                </Alert>
            )}
            <MessageFrame html={message.html} showImages={showImages} height={300} autoHeight />
        </>
    );
}
