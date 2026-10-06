import { useEffect, useState } from "react";
import { Button, FormFeedback, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { markPostPublished } from "helpers/laravel_helper";
import type { PostChannel } from "common/models/editorialPost.model";
import type { PostWorkflow } from "common/models/editorialWorkflow.model";

/**
 * Marcar como publicada (F3d): o link da publicação (instagram.com ou facebook.com,
 * conforme a rede) e a hora real. Também corrige o link ou a hora depois de publicada.
 */

/** "AAAA-MM-DDTHH:MM" na hora local do browser (para o campo datetime-local). */
const localInput = (d: Date) => {
    const p = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
};

type Props = {
    isOpen: boolean;
    toggle: () => void;
    companyId: number;
    post: { id: number; title: string; channel: PostChannel } | null;
    initialUrl?: string | null;
    initialAt?: string | null;
    onDone: (detail: PostWorkflow) => void;
};

export default function MarkPublishedModal({ isOpen, toggle, companyId, post, initialUrl, initialAt, onDone }: Props) {
    const [url, setUrl] = useState("");
    const [at, setAt] = useState("");
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!isOpen) return;
        setUrl(initialUrl ?? "");
        setAt(localInput(initialAt ? new Date(initialAt) : new Date()));
        setErrors({});
    }, [isOpen, initialUrl, initialAt]);

    if (!post) return null;
    const fb = post.channel === "facebook";

    const submit = async () => {
        setBusy(true);
        setErrors({});
        try {
            const r: any = await markPostPublished(companyId, post.id, { url: url.trim(), published_at: at.replace("T", " ") });
            toast.success(initialUrl ? "Publicação corrigida." : "Marcada como publicada.");
            onDone(r.data);
            toggle();
        } catch (e: any) {
            if (e?.errors) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, String((v as string[])[0])])));
            else toast.error(e?.message ?? "Não foi possível marcar como publicada.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={toggle} centered>
            <ModalHeader toggle={toggle}>{initialUrl ? "Corrigir a publicação" : "Marcar como publicada"}</ModalHeader>
            <ModalBody>
                <p className="fs-13 text-muted mb-3">{post.title}</p>
                <Label for="mp-url" className="mb-1">Link da publicação {fb ? "no Facebook" : "no Instagram"}</Label>
                <Input id="mp-url" type="url" inputMode="url" value={url} invalid={!!errors.url} onChange={(e) => setUrl(e.target.value)}
                    placeholder={fb ? "https://www.facebook.com/…" : "https://www.instagram.com/p/…"} />
                <FormFeedback>{errors.url}</FormFeedback>
                <div className="form-text">Abra a publicação na rede e copie o endereço (Partilhar, Copiar link).</div>
                <Label for="mp-at" className="mb-1 mt-3">Data e hora em que foi publicada</Label>
                <Input id="mp-at" type="datetime-local" value={at} invalid={!!errors.published_at} onChange={(e) => setAt(e.target.value)} />
                <FormFeedback>{errors.published_at}</FormFeedback>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={toggle}>Cancelar</Button>
                <Button color="success" disabled={busy || !url.trim() || !at} onClick={() => void submit()}>
                    {busy ? <Spinner size="sm" /> : <><i className="ri-checkbox-circle-line me-1" />{initialUrl ? "Guardar" : "Marcar como publicada"}</>}
                </Button>
            </ModalFooter>
        </Modal>
    );
}
