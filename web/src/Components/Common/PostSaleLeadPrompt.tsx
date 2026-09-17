import React, { useEffect, useState } from "react";
import { Modal, ModalHeader, ModalBody, ModalFooter, Input, Label, Spinner } from "reactstrap";
import { LEAD_STATUS_META, LeadStatus } from "common/models/lead.model";

/**
 * XPLENDOR — Fase 2 (CRM). Após registar uma venda, se o cliente tiver leads
 * ABERTAS no funil, pergunta ao Simon se as move para "Venda". NUNCA move sozinho
 * — só com confirmação. Se houver várias, o Simon escolhe qual.
 */
export interface LeadCandidate {
    id: number;
    name: string;
    status: LeadStatus;
    phone?: string | null;
    email?: string | null;
}

interface Props {
    isOpen: boolean;
    candidates: LeadCandidate[];
    saving?: boolean;
    onConfirm: (leadId: number) => void;
    onCancel: () => void;
}

const PostSaleLeadPrompt: React.FC<Props> = ({ isOpen, candidates, saving = false, onConfirm, onCancel }) => {
    const [selected, setSelected] = useState<number | null>(null);

    useEffect(() => {
        if (isOpen && candidates.length) setSelected(candidates[0].id); // pré-seleciona a mais provável (Negociação)
    }, [isOpen, candidates]);

    if (!candidates.length) return null;
    const top = candidates[0];
    const many = candidates.length > 1;
    const label = (s: LeadStatus) => LEAD_STATUS_META[s]?.label ?? s;

    return (
        <Modal isOpen={isOpen} toggle={onCancel} centered>
            <ModalHeader toggle={onCancel}>Mover a lead para "Venda"?</ModalHeader>
            <ModalBody>
                {many ? (
                    <>
                        <p className="text-muted fs-13 mb-2">Este cliente tem {candidates.length} leads abertas no funil. Escolhe a que corresponde a esta venda:</p>
                        {candidates.map((c) => (
                            <Label key={c.id} className="d-flex align-items-center gap-2 border rounded p-2 mb-2" style={{ cursor: "pointer" }}>
                                <Input type="radio" name="lead-candidate" checked={selected === c.id} onChange={() => setSelected(c.id)} className="mt-0" />
                                <span className="flex-grow-1">
                                    <span className="fw-medium">{c.name}</span>
                                    <span className="text-muted fs-12 d-block">{c.phone || c.email}</span>
                                </span>
                                <span className={`badge bg-${LEAD_STATUS_META[c.status]?.color}-subtle text-${LEAD_STATUS_META[c.status]?.color}`}>{label(c.status)}</span>
                            </Label>
                        ))}
                    </>
                ) : (
                    <p className="mb-0">
                        Este cliente tem uma lead em <span className={`badge bg-${LEAD_STATUS_META[top.status]?.color}-subtle text-${LEAD_STATUS_META[top.status]?.color}`}>{label(top.status)}</span> no funil
                        {" "}(<span className="fw-medium">{top.name}</span>). Queres movê-la para <strong>"Venda"</strong>?
                    </p>
                )}
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={onCancel} disabled={saving}>Não</button>
                <button type="button" className="btn btn-success" onClick={() => selected && onConfirm(selected)} disabled={!selected || saving}>
                    {saving ? <><Spinner size="sm" className="me-1" /> A mover…</> : <><i className="ri-check-line me-1" />Sim, mover para Venda</>}
                </button>
            </ModalFooter>
        </Modal>
    );
};

export default PostSaleLeadPrompt;
