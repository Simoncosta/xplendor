import React, { useEffect, useState } from "react";
import { Modal, ModalHeader, ModalBody, ModalFooter, Input, Label, Badge, Spinner } from "reactstrap";
import { ILead, LEAD_STATUS_META, leadOrigin, lossReasonLabel } from "common/models/lead.model";
import FinancingDocumentsCard from "pages/Cars/Car/components/FinancingDocumentsCard";
import TradeInCard from "./TradeInCard";
import { getWorkingCompanyId } from "helpers/workingCompany";

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
                {lead.type === "proposta" && <Badge color="primary" className="ms-1">{lead.type_label ?? "Pedido de proposta"}</Badge>}
            </ModalHeader>
            <ModalBody>
                <div className="table-card mb-3">
                    <table className="table table-sm mb-0">
                        <tbody>
                            <tr><td className="fw-medium" style={{ width: 130 }}>Contacto</td><td>{lead.phone || "—"}{lead.email ? ` · ${lead.email}` : ""}</td></tr>
                            <tr><td className="fw-medium">Veículo</td><td>{carName}</td></tr>
                            <tr><td className="fw-medium">Origem</td><td>{leadOrigin(lead)}</td></tr>
                            {lead.type === "proposta" && (
                                <>
                                    <tr><td className="fw-medium">Financiamento</td><td>{lead.wants_financing ? "Quer financiamento" : "Não"}</td></tr>
                                    <tr><td className="fw-medium">Retoma</td><td>{lead.trade_in
                                        ? [lead.trade_in.brand, lead.trade_in.model, lead.trade_in.year, lead.trade_in.km != null ? `${lead.trade_in.km.toLocaleString("pt-PT")} km` : null].filter(Boolean).join(" · ")
                                        : "Não"}</td></tr>
                                </>
                            )}
                            {lead.utm_campaign && <tr><td className="fw-medium">Campanha</td><td>{lead.utm_campaign}</td></tr>}
                            <tr><td className="fw-medium">Recebida</td><td>{fmtDate(lead.created_at)}</td></tr>
                            {lead.status === "lost" && <tr><td className="fw-medium">Motivo perda</td><td>{lossReasonLabel(lead.lost_reason)}</td></tr>}
                        </tbody>
                    </table>
                </div>

                {lead.consent_contact_at && (
                    <div className="mb-3" data-testid="lead-consents">
                        <Label className="form-label text-uppercase fs-12">Consentimentos</Label>
                        <p className="fs-13 mb-1"><strong>Contacto:</strong> aceite a {fmtDate(lead.consent_contact_at)}. <span className="text-muted">«{lead.consent_contact_text}»</span></p>
                        <p className="fs-13 mb-0"><strong>Novidades:</strong> {lead.consent_marketing
                            ? <>aceite a {fmtDate(lead.consent_marketing_at)}. <span className="text-muted">«{lead.consent_marketing_text}»</span></>
                            : "não aceite."}{lead.policy_version ? <span className="text-muted"> Política {lead.policy_version}.</span> : null}</p>
                    </div>
                )}

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

                {lead.trade_in && getWorkingCompanyId() ? <TradeInCard companyId={Number(getWorkingCompanyId())} leadId={lead.id} /> : null}

                {lead.car_id && getWorkingCompanyId() ? (
                    <FinancingDocumentsCard companyId={Number(getWorkingCompanyId())} leadId={lead.id} embedded />
                ) : null}

                {(phone || lead.email) && (
                    <div className="d-flex gap-2 mt-3">
                        {phone && <a href={`tel:${phone}`} className="btn btn-sm btn-outline-primary"><i className="ri-phone-line me-1" />Ligar</a>}
                        {phone && <a href={`https://wa.me/${phone}`} target="_blank" rel="noreferrer" className="btn btn-sm btn-outline-primary"><i className="ri-whatsapp-line me-1" />WhatsApp</a>}
                        {lead.email && <a href={`mailto:${lead.email}`} className="btn btn-sm btn-outline-primary"><i className="ri-mail-line me-1" />Email</a>}
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
