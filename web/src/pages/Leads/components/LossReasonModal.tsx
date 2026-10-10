import React, { useState, useEffect } from "react";
import { Modal, ModalHeader, ModalBody, ModalFooter, Label, Spinner } from "reactstrap";
import { LOSS_REASONS } from "common/models/lead.model";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";

/**
 * XPLENDOR — Motivo de perda obrigatório ao marcar uma lead como "Perdida".
 * Reutilizado pelo funil (drag → Perdida) e pela lista. Não deixa perder sem motivo.
 */
interface Props {
    isOpen: boolean;
    leadName?: string;
    saving?: boolean;
    onConfirm: (reason: string) => void;
    onCancel: () => void;
}

const LossReasonModal: React.FC<Props> = ({ isOpen, leadName, saving = false, onConfirm, onCancel }) => {
    const [reason, setReason] = useState("");

    useEffect(() => { if (isOpen) setReason(""); }, [isOpen]);

    return (
        <Modal isOpen={isOpen} toggle={onCancel} centered>
            <ModalHeader toggle={onCancel}>Motivo da perda</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13 mb-3">
                    {leadName ? <>Vai marcar <strong>{leadName}</strong> como perdida.</> : "Vai marcar esta lead como perdida."}{" "}
                    Indique o motivo (obrigatório).
                </p>
                <Label className="form-label" htmlFor="loss-reason">Motivo</Label>
                <XSelect<string>
                    id="loss-reason"
                    options={LOSS_REASONS.map((r) => ({ value: r.key, label: r.label }))}
                    value={reason || null}
                    onChange={setReason}
                    placeholder="Escolha um motivo…"
                />
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={onCancel} disabled={saving}>Cancelar</button>
                <ReasonButton type="button" color="danger" onClick={() => reason && onConfirm(reason)} disabled={saving} reason={!reason ? "Escolha o motivo da perda." : null}>
                    {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : "Marcar como perdida"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
};

export default LossReasonModal;
