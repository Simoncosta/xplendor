import React, { useEffect, useState } from "react";
import { Modal, ModalHeader, ModalBody, ModalFooter, Input, Label, Badge, Spinner } from "reactstrap";
import { ILead, LEAD_STATUS_META, lossReasonLabel } from "common/models/lead.model";

/**
 * XPLENDOR — Detalhe de uma lead (modal). Mostra os dados que a entidade JÁ tem
 * (contacto, veículo, origem/campanha, datas, mensagem) e permite editar as notas.
 * NÃO há timeline de atividades (a entidade não a tem — fica para o futuro).
 */
interface Props {
    lead: ILead | null;
    saving?: boolean;
    onSaveNotes: (notes: string) => void;
    onClose: () => void;
}

const fmtDate = (s?: string | null) => (s ? new Date(s).toLocaleString("pt-PT", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");

const LeadDetailModal: React.FC<Props> = ({ lead, saving = false, onSaveNotes, onClose }) => {
    const [notes, setNotes] = useState("");
    useEffect(() => { setNotes(lead?.notes ?? ""); }, [lead]);

    if (!lead) return null;

    const meta = LEAD_STATUS_META[lead.status];
    const car = lead.car;
    const phone = lead.phone?.replace(/\D/g, "");
    const carName = car ? `${car.brand?.name ?? ""} ${car.model?.name ?? ""} ${car.version ?? ""}`.trim() : "—";

    return (
        <Modal isOpen={!!lead} toggle={onClose} centered scrollable>
            <ModalHeader toggle={onClose}>
                <span className="me-2">{lead.name}</span>
                <Badge color={meta.color}>{meta.label}</Badge>
            </ModalHeader>
            <ModalBody>
                <div className="table-card mb-3">
                    <table className="table table-sm mb-0">
                        <tbody>
                            <tr><td className="fw-medium" style={{ width: 130 }}>Contacto</td><td>{lead.phone || "—"}{lead.email ? ` · ${lead.email}` : ""}</td></tr>
                            <tr><td className="fw-medium">Veículo</td><td>{carName}</td></tr>
                            <tr><td className="fw-medium">Origem</td><td>{[lead.channel, lead.utm_source].filter(Boolean).join(" · ") || "—"}</td></tr>
                            {lead.utm_campaign && <tr><td className="fw-medium">Campanha</td><td>{lead.utm_campaign}</td></tr>}
                            <tr><td className="fw-medium">Recebida</td><td>{fmtDate(lead.created_at)}</td></tr>
                            {lead.status === "lost" && <tr><td className="fw-medium">Motivo perda</td><td>{lossReasonLabel(lead.lost_reason)}</td></tr>}
                        </tbody>
                    </table>
                </div>

                {lead.message && (
                    <div className="mb-3">
                        <Label className="form-label text-uppercase fs-12">Mensagem</Label>
                        <p className="text-muted mb-0" style={{ whiteSpace: "pre-wrap" }}>{lead.message}</p>
                    </div>
                )}

                <div className="mb-1">
                    <Label className="form-label text-uppercase fs-12">Notas internas</Label>
                    <Input type="textarea" rows={4} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Notas sobre esta lead…" />
                </div>

                {(phone || lead.email) && (
                    <div className="d-flex gap-2 mt-3">
                        {phone && <a href={`tel:${phone}`} className="btn btn-sm btn-soft-primary"><i className="ri-phone-line me-1" />Ligar</a>}
                        {phone && <a href={`https://wa.me/${phone}`} target="_blank" rel="noreferrer" className="btn btn-sm btn-soft-success"><i className="ri-whatsapp-line me-1" />WhatsApp</a>}
                        {lead.email && <a href={`mailto:${lead.email}`} className="btn btn-sm btn-soft-secondary"><i className="ri-mail-line me-1" />Email</a>}
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <button type="button" className="btn btn-light" onClick={onClose}>Fechar</button>
                <button type="button" className="btn btn-primary" onClick={() => onSaveNotes(notes)} disabled={saving}>
                    {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : "Guardar notas"}
                </button>
            </ModalFooter>
        </Modal>
    );
};

export default LeadDetailModal;
