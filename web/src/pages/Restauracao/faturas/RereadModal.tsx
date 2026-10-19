import { useEffect, useState } from "react";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { getOcrManualChanges } from "helpers/laravel_helper";
import { OcrManualChanges } from "common/models/ocr.model";
import Skeleton from "Components/Common/Skeleton";

/**
 * XPLENDOR — Faturas: "Reler do documento". Antes de voltar a correr o OCR, mostra o que foi mudado
 * à mão desde a leitura (gravado) e pergunta se fica ("Manter": volta a ser aplicado à nova leitura)
 * ou se é substituído pela nova leitura. Por omissão, manter.
 */

type Props = {
    isOpen: boolean;
    companyId: number;
    invoiceId: number;
    busy: boolean;
    dirty: boolean;                  // há alterações neste ecrã ainda não gravadas
    onClose: () => void;
    onConfirm: (keepManual: boolean) => void;
};

const TYPE_LABEL = { alterada: "Alterada", apagada: "Apagada", acrescentada: "Acrescentada" } as const;
const show = (v: any) => (v === null || v === undefined || v === "" ? "—" : typeof v === "number" ? v.toLocaleString("pt-PT", { maximumFractionDigits: 6 }) : String(v));

export default function RereadModal({ isOpen, companyId, invoiceId, busy, dirty, onClose, onConfirm }: Props) {
    const [mc, setMc] = useState<OcrManualChanges | null>(null);
    const [failed, setFailed] = useState(false);
    const [keep, setKeep] = useState(true);

    useEffect(() => {
        if (!isOpen) return;
        setMc(null);
        setFailed(false);
        setKeep(true);
        getOcrManualChanges(companyId, invoiceId)
            .then((r: any) => setMc(r?.data ?? null))
            .catch(() => setFailed(true));
    }, [isOpen, companyId, invoiceId]);

    const changes = mc?.changes ?? [];
    const canKeep = !!mc?.available && changes.length > 0;

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered size="lg" data-testid="reread-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Reler do documento?</ModalHeader>
            <ModalBody>
                <p className="mb-2">A fatura volta a ser lida (QR e linhas).</p>
                {dirty && <Alert color="warning" className="py-2 fs-13">Há alterações neste ecrã ainda não gravadas: não contam e perdem-se.</Alert>}
                {!mc && !failed && <Skeleton variant="lines" lines={3} />}
                {failed && <Alert color="danger" className="py-2 fs-13">Não foi possível saber o que foi mudado à mão. A nova leitura substitui a atual.</Alert>}
                {mc && !mc.available && (
                    <Alert color="secondary" className="py-2 fs-13" data-testid="reread-unknown">Esta fatura foi lida antes de se guardar a leitura original: não se sabe o que foi mudado à mão, por isso a nova leitura substitui a atual.</Alert>
                )}
                {mc?.available && changes.length === 0 && (
                    <p className="text-muted fs-13 mb-0" data-testid="reread-no-changes">Não há alterações manuais: a nova leitura substitui a atual.</p>
                )}
                {canKeep && (
                    <>
                        <div className="fs-13 fw-semibold mb-1">Alterações feitas à mão ({changes.length}):</div>
                        <ul className="fs-13 mb-3 ps-3" data-testid="reread-changes">
                            {changes.map((c) => (
                                <li key={`${c.type}-${c.line_id}`} className="mb-1">
                                    <span className="fw-medium">{TYPE_LABEL[c.type]}: {c.item ?? "—"}</span>
                                    {c.type === "alterada" && (
                                        <span className="text-muted"> · {c.fields.map((f) => f.field === "artigo" || f.field === "unidade" ? `${f.label} escolhido à mão` : `${f.label}: ${show(f.read)} → ${show(f.current)}`).join("; ")}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <div className="form-check mb-1">
                            <Input type="radio" className="form-check-input" id="reread-keep" name="reread" checked={keep} onChange={() => setKeep(true)} />
                            <Label className="form-check-label" for="reread-keep">Manter as alterações manuais <span className="text-muted fs-13">(voltam a ser aplicadas à nova leitura)</span></Label>
                        </div>
                        <div className="form-check">
                            <Input type="radio" className="form-check-input" id="reread-replace" name="reread" checked={!keep} onChange={() => setKeep(false)} />
                            <Label className="form-check-label" for="reread-replace">Substituir pela nova leitura <span className="text-muted fs-13">(as alterações manuais perdem-se)</span></Label>
                        </div>
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                <Button color="primary" onClick={() => onConfirm(canKeep && keep)} disabled={busy || (!mc && !failed)} data-testid="reread-confirm">
                    {busy ? <><Spinner size="sm" className="me-1" />A pedir…</> : <><i className="ri-refresh-line me-1" />Reler do documento</>}
                </Button>
            </ModalFooter>
        </Modal>
    );
}
