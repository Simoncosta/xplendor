import React, { useState, useEffect } from "react";
import { Modal, ModalHeader, ModalBody, ModalFooter, Input, Label, Spinner } from "reactstrap";
import { LOSS_REASONS } from "common/models/lead.model";

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
                    {leadName ? <>Vais marcar <strong>{leadName}</strong> como perdida.</> : "Vais marcar esta lead como perdida."}{" "}
                    Indica o motivo (obrigatório).
                </p>
                <Label className="form-label">Motivo</Label>
                <Input type="select" value={reason} onChange={(e) => setReason(e.target.value)}>
                    <option value="">Escolhe um motivo…</option>
                    {LOSS_REASONS.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
                </Input>
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={onCancel} disabled={saving}>Cancelar</button>
                <button type="button" className="btn btn-danger" onClick={() => reason && onConfirm(reason)} disabled={!reason || saving}>
                    {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : "Marcar como perdida"}
                </button>
            </ModalFooter>
        </Modal>
    );
};

export default LossReasonModal;
