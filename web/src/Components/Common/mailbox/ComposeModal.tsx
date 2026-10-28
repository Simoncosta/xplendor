import { useEffect, useRef, useState } from "react";
import { Form, Formik } from "formik";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Progress, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XInputTextareaQuill from "Components/Common/XInputTextareaQuill";
import FilePickerModal from "pages/Files/FilePickerModal";
import { useAccess } from "contexts/ModulesContext";
import { attachMailboxFile, deleteMailboxAttachment, getMailboxComposeContext, sendMailboxMessage, uploadMailboxAttachment } from "helpers/laravel_helper";

/**
 * E-mail 5 · Escrever uma mensagem pela minha caixa: "Escrever" (na caixa), "Responder" (no mesmo
 * fio, com o original citado por baixo, que o servidor junta) e "Escrever ao cliente" (na ficha e na
 * venda: fica ligada ao cliente e à venda). O editor é o Quill da app, só com texto e ligações. A
 * assinatura da caixa vai no fim, e mostra-se por baixo do editor. Se o servidor recusar
 * (consentimento, limites, endereço), o motivo aparece aqui e o texto fica.
 *
 * E-mail 5b · Os anexos, como no Gmail: do computador ("Anexar" ou arrastar para a janela) e dos
 * Ficheiros (o seletor, só com o que a pessoa pode ver). Cada um carrega logo (com o progresso) e
 * passa pelo antivírus; até 10 ficheiros e 20 MB por mensagem; os executáveis não se anexam. Ao
 * cancelar, os anexos carregados apagam-se.
 */
export type ComposeValues = { to: string; cc: string; subject: string; body: string };
export type AttachmentLimits = { max_files: number; max_bytes: number; max_file_bytes: number };
type Attachment = { key: string; name: string; size: number; progress: number; id?: string; error?: string };

const MODULES = { toolbar: [["bold", "italic", "underline"], [{ list: "ordered" }, { list: "bullet" }], ["link"], ["clean"]] };
const DEFAULT_LIMITS: AttachmentLimits = { max_files: 10, max_bytes: 20 * 1024 * 1024, max_file_bytes: 20 * 1024 * 1024 };
/** As mesmas extensões que o servidor recusa (o servidor verifica também o conteúdo). */
export const BLOCKED_EXTENSIONS = ["exe", "bat", "cmd", "com", "scr", "msi", "msp", "msix", "appx", "js", "jse", "vbs", "vbe", "wsf", "wsh", "ps1", "psm1",
    "jar", "apk", "dmg", "pkg", "app", "sh", "bash", "zsh", "csh", "command", "run", "cpl", "hta", "lnk", "reg", "pif", "gadget", "dll", "sys", "deb", "rpm",
    "appimage", "scpt", "xbap", "application", "ipa"];

export const fmtSize = (bytes: number) => (bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1048576).toFixed(1)} MB`);
const mb = (bytes: number) => `${Math.round(bytes / 1048576)} MB`;
const errorOf = (e: any, fallback: string) => {
    const lists = e?.errors && typeof e.errors === "object" ? Object.values(e.errors).filter(Array.isArray) : [];
    return (lists.flat()[0] as string | undefined) || e?.message || fallback;
};

export default function ComposeModal({ isOpen, toggle, companyId, title = "Nova mensagem", initial, replyTo, quoteHeader, customerId, carId, signature, blocked, limits, link, onSent }: {
    isOpen: boolean; toggle: () => void; companyId: number; title?: string;
    initial?: Partial<ComposeValues>;
    replyTo?: { folder: string; uid: number } | null;
    /** "Em <data>, <nome> escreveu:" (a citação junta-se no servidor; aqui só se avisa). */
    quoteHeader?: string;
    customerId?: number; carId?: number;
    signature?: string | null;
    /** Um motivo que impede já o envio (sem consentimento, caixa por testar, limite): mostra-se e o botão fica desligado. */
    blocked?: string | null;
    /** Os limites dos anexos (do compose-context); sem eles, pede-os ao abrir. */
    limits?: AttachmentLimits;
    /** E-mail 9: o link de financiamento ou de retoma que a mensagem leva. */
    link?: { kind: "financing" | "trade_in"; id: number } | null;
    onSent?: (message: any) => void;
}) {
    const [error, setError] = useState<string | null>(null);
    const [showCc, setShowCc] = useState(!!initial?.cc);
    const [files, setFiles] = useState<Attachment[]>([]);
    const [limit, setLimit] = useState<AttachmentLimits>(limits ?? DEFAULT_LIMITS);
    const [dragging, setDragging] = useState(false);
    const [picking, setPicking] = useState(false);
    const [pickBusy, setPickBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const filesRef = useRef<Attachment[]>([]);
    const sentRef = useRef(false);
    const seq = useRef(0);
    const access = useAccess();
    const canFiles = access.has("ficheiros") && access.can("ficheiros.ver");

    filesRef.current = files;
    useEffect(() => {
        if (limits || !isOpen) return;
        let alive = true;
        getMailboxComposeContext(companyId).then((r: any) => { if (alive && r?.data?.attachments) setLimit(r.data.attachments); }).catch(() => undefined);
        return () => { alive = false; };
    }, [limits, isOpen, companyId]);
    // Ao fechar sem enviar, os anexos já carregados apagam-se (senão, a limpeza das 24 horas apanha-os).
    useEffect(() => () => {
        if (sentRef.current) return;
        filesRef.current.filter((f) => f.id).forEach((f) => { deleteMailboxAttachment(companyId, f.id as string).catch(() => undefined); });
    }, [companyId]);

    const patch = (key: string, p: Partial<Attachment>) => setFiles((fs) => fs.map((f) => (f.key === key ? { ...f, ...p } : f)));
    const counted = () => filesRef.current.filter((f) => !f.error);

    /** Verifica no ecrã (número, total, tamanho, executáveis) e carrega cada ficheiro logo, com o progresso. */
    const addLocal = (list: FileList | File[]) => {
        setError(null);
        let current = counted();
        for (const file of Array.from(list)) {
            const key = `a${++seq.current}`;
            const ext = (file.name.split(".").pop() || "").toLowerCase();
            const total = current.reduce((s, f) => s + f.size, 0) + file.size;
            const refusal = current.length >= limit.max_files ? `No máximo ${limit.max_files} anexos por mensagem.`
                : file.name.includes(".") && BLOCKED_EXTENSIONS.includes(ext) ? `Os ficheiros .${ext} não se podem anexar (são programas ou scripts).`
                : file.size > limit.max_file_bytes ? `O ficheiro tem mais de ${mb(limit.max_file_bytes)}.`
                : total > limit.max_bytes ? `Os anexos passam de ${mb(limit.max_bytes)} no total.` : null;
            const item: Attachment = { key, name: file.name, size: file.size, progress: 0, error: refusal ?? undefined };
            setFiles((fs) => [...fs, item]);
            if (refusal) continue;
            current = [...current, item];
            uploadMailboxAttachment(companyId, file, (p) => patch(key, { progress: p }))
                .then((r: any) => patch(key, { id: r?.data?.attachment?.id, progress: 100 }))
                .catch((e: any) => patch(key, { error: errorOf(e, "Não foi possível carregar o anexo.") }));
        }
    };

    const addFromFiles = async (file: { id: number; name: string; size_bytes: number | null }) => {
        const current = counted();
        if (current.length >= limit.max_files) { setError(`No máximo ${limit.max_files} anexos por mensagem.`); setPicking(false); return; }
        if (current.reduce((s, f) => s + f.size, 0) + (file.size_bytes ?? 0) > limit.max_bytes) { setError(`Os anexos passam de ${mb(limit.max_bytes)} no total.`); setPicking(false); return; }
        setPickBusy(true);
        try {
            const r: any = await attachMailboxFile(companyId, file.id);
            const a = r?.data?.attachment;
            setFiles((fs) => [...fs, { key: `a${++seq.current}`, name: a?.name ?? file.name, size: a?.size ?? file.size_bytes ?? 0, progress: 100, id: a?.id }]);
            setPicking(false);
        } catch (e: any) {
            toast.error(errorOf(e, "Não foi possível anexar o ficheiro."));
        } finally {
            setPickBusy(false);
        }
    };

    const remove = (f: Attachment) => {
        setFiles((fs) => fs.filter((x) => x.key !== f.key));
        if (f.id) deleteMailboxAttachment(companyId, f.id).catch(() => undefined);
    };

    const submit = async (values: ComposeValues, { setSubmitting }: { setSubmitting: (b: boolean) => void }) => {
        setError(null);
        try {
            const ids = files.filter((f) => f.id && !f.error).map((f) => f.id as string);
            const r: any = await sendMailboxMessage(companyId, { ...values, reply_to: replyTo ?? null, customer_id: customerId ?? null, car_id: carId ?? null, attachments: ids, ...(link ? { link } : {}) });
            sentRef.current = true; // os anexos foram com a mensagem (o servidor apaga-os depois do envio)
            toast.success(r?.message || "Mensagem enviada.");
            onSent?.(r?.data?.message);
            window.dispatchEvent(new Event("xp:mail-sent")); // as "Conversas" abertas recarregam
            toggle();
        } catch (e: any) {
            // O texto e os anexos ficam; só se mostra o motivo.
            setError(errorOf(e, "Não foi possível enviar a mensagem."));
        } finally {
            setSubmitting(false);
        }
    };

    const empty = (html: string) => !html || !html.replace(/<[^>]*>/g, "").replace(/&nbsp;/g, " ").trim();
    const uploading = files.some((f) => !f.id && !f.error);
    const ready = files.filter((f) => f.id && !f.error);
    const totalReady = ready.reduce((s, f) => s + f.size, 0);
    const onDrop = (e: React.DragEvent) => { e.preventDefault(); setDragging(false); if (e.dataTransfer?.files?.length) addLocal(e.dataTransfer.files); };

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered backdrop="static" data-testid="mail-compose">
            <Formik<ComposeValues> initialValues={{ to: "", cc: "", subject: "", body: "", ...initial }} onSubmit={submit}>
                {({ values, handleChange, isSubmitting }) => (
                    <Form>
                        <ModalHeader toggle={toggle}>{title}</ModalHeader>
                        <ModalBody className="position-relative" data-testid="mail-compose-drop"
                            onDragOver={(e) => { e.preventDefault(); if (!dragging) setDragging(true); }}
                            onDragLeave={(e) => { if (e.currentTarget === e.target) setDragging(false); }} onDrop={onDrop}>
                            {dragging && (
                                <div className="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center rounded border border-2 border-primary border-dashed bg-primary-subtle"
                                    style={{ zIndex: 5, pointerEvents: "none" }} data-testid="mail-compose-dropzone">
                                    <span className="fs-16 text-primary"><i className="ri-upload-cloud-2-line me-1" />Largue aqui para anexar</span>
                                </div>
                            )}
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

                            {files.length > 0 && (
                                <ul className="list-unstyled vstack gap-2 mt-3 mb-0" data-testid="mail-compose-attachments">
                                    {files.map((f) => (
                                        <li key={f.key} className={`border rounded px-2 py-1 ${f.error ? "border-danger" : ""}`} data-testid="mail-compose-attachment">
                                            <div className="d-flex align-items-center gap-2">
                                                <i className={`ri-attachment-2 ${f.error ? "text-danger" : "text-muted"}`} />
                                                <span className="text-truncate flex-grow-1 fs-13" title={f.name}>{f.name}</span>
                                                <small className="text-muted flex-shrink-0">{fmtSize(f.size)}</small>
                                                <button type="button" className="btn btn-sm btn-ghost-danger btn-icon" onClick={() => remove(f)} aria-label={`Remover ${f.name}`} title="Remover"><i className="ri-close-line" /></button>
                                            </div>
                                            {!f.id && !f.error && <Progress value={f.progress} className="progress-sm mt-1" aria-label={`A carregar ${f.name}`} />}
                                            {f.error && <div className="text-danger fs-12">{f.error}</div>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="d-flex flex-wrap align-items-center gap-2 mt-3">
                                <Button type="button" size="sm" color="light" onClick={() => input.current?.click()}><i className="ri-attachment-2 me-1" />Anexar</Button>
                                {canFiles && <Button type="button" size="sm" color="light" onClick={() => setPicking(true)}><i className="ri-folder-open-line me-1" />Dos Ficheiros</Button>}
                                <span className="text-muted fs-12">
                                    {ready.length > 0 ? `${ready.length} de ${limit.max_files} anexos · ${fmtSize(totalReady)} de ${mb(limit.max_bytes)}` : `Até ${limit.max_files} ficheiros e ${mb(limit.max_bytes)} por mensagem. Pode arrastar os ficheiros para aqui.`}
                                </span>
                                <input ref={input} type="file" multiple hidden data-testid="mail-compose-file" onChange={(e) => { if (e.target.files) addLocal(e.target.files); e.target.value = ""; }} />
                            </div>
                        </ModalBody>
                        <ModalFooter>
                            <Button type="button" color="light" onClick={toggle} disabled={isSubmitting}>Cancelar</Button>
                            <Button type="submit" color="primary" disabled={isSubmitting || uploading || !!blocked || !values.to.trim() || empty(values.body)}
                                title={uploading ? "Espere que os anexos acabem de carregar." : undefined}>
                                {isSubmitting ? <Spinner size="sm" /> : <><i className="ri-send-plane-2-line me-1" />Enviar</>}
                            </Button>
                        </ModalFooter>
                    </Form>
                )}
            </Formik>
            {picking && <FilePickerModal isOpen companyId={companyId} busy={pickBusy} mode="attachment" onClose={() => setPicking(false)} onPick={(f) => addFromFiles(f)} />}
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
