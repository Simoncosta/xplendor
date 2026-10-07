import { useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { PROOF_ACCEPT, PROOF_MAX_MB, euro } from "common/models/charge.model";

/**
 * "Já paguei": nota e comprovativo opcionais (PDF ou imagem, até 10 MB). Usado dentro da
 * plataforma e na página pública do link. Quem chama envia o pedido.
 */
type Props = {
    isOpen: boolean;
    toggle: () => void;
    description: string;
    amount: number;
    onSubmit: (note: string, proof: File | null) => Promise<string | null>; // devolve a mensagem de erro, ou null
};

export default function ChargePaidModal({ isOpen, toggle, description, amount, onSubmit }: Props) {
    const [note, setNote] = useState("");
    const [proof, setProof] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const pick = (f: File | null) => {
        setError(null);
        if (f && f.size > PROOF_MAX_MB * 1024 * 1024) { setError(`O comprovativo não pode ter mais de ${PROOF_MAX_MB} MB.`); setProof(null); return; }
        setProof(f);
    };
    const send = async () => {
        setBusy(true);
        const err = await onSubmit(note.trim(), proof);
        setBusy(false);
        if (err) { setError(err); return; }
        setNote(""); setProof(null);
    };

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && toggle()} centered>
            <ModalHeader toggle={() => !busy && toggle()}>Já paguei</ModalHeader>
            <ModalBody>
                <p className="mb-3">{description}: <strong>{euro(amount)}</strong></p>
                <Label for="charge-proof" className="mb-1">Comprovativo <span className="text-muted fw-normal">(opcional, PDF ou imagem até {PROOF_MAX_MB} MB)</span></Label>
                <Input id="charge-proof" type="file" accept={PROOF_ACCEPT} className="mb-3" onChange={(e) => pick(e.target.files?.[0] ?? null)} />
                <Label for="charge-note" className="mb-1">Nota <span className="text-muted fw-normal">(opcional)</span></Label>
                <Input id="charge-note" type="textarea" rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Por exemplo, transferência feita a 12/10." />
                <p className="text-muted fs-12 mt-3 mb-0">Os lembretes param de imediato. A XPLENDOR confirma o pagamento.</p>
                {error && <div className="alert alert-danger py-2 fs-13 mt-3 mb-0" role="alert">{error}</div>}
            </ModalBody>
            <ModalFooter>
                <Button color="light" disabled={busy} onClick={toggle}>Cancelar</Button>
                <Button color="success" disabled={busy} onClick={send}>{busy ? <Spinner size="sm" /> : "Confirmar que paguei"}</Button>
            </ModalFooter>
        </Modal>
    );
}
