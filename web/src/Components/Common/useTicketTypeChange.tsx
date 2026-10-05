import React, { useState } from "react";
import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { reclassifyAdminTicketType } from "helpers/laravel_helper";
import {
    ISupportTicket, QuoteStatus, SupportTicketType,
    TICKET_TYPE_META, QUOTE_STATUS_META, formatEuro,
} from "common/models/supportTicket.model";

/**
 * Mudança de tipo de um ticket pela equipa XPLENDOR (Kanban e detalhe admin).
 * Se o servidor responder 409 (a mudança anula um orçamento orçado ou rejeitado),
 * mostra o orçamento e pede confirmação; aprovado, pago ou concluído chega como
 * 422 com a razão, mostrada num aviso.
 */

interface PendingReset {
    ticket: ISupportTicket;
    type: SupportTicketType;
    quoteStatus: QuoteStatus | null;
    amount: number | null;
    hours: number | null;
}

const fmtHours = (h: number | null) => (h == null ? null : `${String(h).replace(".", ",")} h`);

export function useTicketTypeChange(onChanged: (updated: ISupportTicket) => void) {
    const [pending, setPending] = useState<PendingReset | null>(null);
    const [busy, setBusy] = useState(false);

    const run = async (ticket: ISupportTicket, type: SupportTicketType, confirmReset: boolean) => {
        setBusy(true);
        try {
            const r: any = await reclassifyAdminTicketType(ticket.id, type, confirmReset);
            if (r?.data) onChanged(r.data);
            setPending(null);
            toast.success(
                type === "site_change" && ticket.type !== "site_change"
                    ? "Tipo alterado. O cliente foi avisado de que o pedido passou a ser pago."
                    : confirmReset
                        ? "Tipo alterado. O orçamento foi anulado e o cliente foi avisado."
                        : "Tipo alterado."
            );
        } catch (e: any) {
            if (e?.__status === 409 && e?.errors?.confirmation_required) {
                setPending({
                    ticket, type,
                    quoteStatus: e.errors.quote_status ?? null,
                    amount: e.errors.quoted_amount ?? null,
                    hours: e.errors.estimated_hours ?? null,
                });
            } else {
                setPending(null);
                toast.error(e?.message || "Não foi possível alterar o tipo do ticket.");
            }
        } finally {
            setBusy(false);
        }
    };

    const requestTypeChange = (ticket: ISupportTicket, type: SupportTicketType) => {
        if (ticket.type === type || busy) return;
        void run(ticket, type, false);
    };

    const cancel = () => { if (!busy) setPending(null); };

    const modal = (
        <Modal isOpen={pending !== null} toggle={cancel} centered>
            <ModalHeader toggle={cancel}>Anular o orçamento?</ModalHeader>
            {pending && (
                <ModalBody>
                    <p className="fs-14 mb-2">
                        Mudar o tipo de «{TICKET_TYPE_META[pending.ticket.type].label}» para «{TICKET_TYPE_META[pending.type].label}» anula o orçamento deste pedido:
                    </p>
                    <div className="p-3 rounded mb-3" style={{ background: "var(--vz-tertiary-bg)" }}>
                        <div className="fw-semibold">
                            {pending.amount != null ? formatEuro(pending.amount) : "Sem valor"}
                            {fmtHours(pending.hours) ? ` · ${fmtHours(pending.hours)}` : ""}
                        </div>
                        {pending.quoteStatus && <div className="text-muted fs-13">{QUOTE_STATUS_META[pending.quoteStatus].label}</div>}
                    </div>
                    <p className="text-muted fs-13 mb-0">
                        O valor, as horas e o estado do orçamento ficam a zero. O orçamento anterior fica registado no histórico do ticket
                        e o cliente recebe uma mensagem a explicar a alteração.
                    </p>
                </ModalBody>
            )}
            <ModalFooter>
                <Button color="light" className="border" onClick={cancel} disabled={busy}>Cancelar</Button>
                <Button color="warning" disabled={busy} onClick={() => pending && void run(pending.ticket, pending.type, true)}>
                    {busy ? <><Spinner size="sm" className="me-2" />A alterar...</> : "Anular orçamento e mudar o tipo"}
                </Button>
            </ModalFooter>
        </Modal>
    );

    return { requestTypeChange, modal, busy };
}

export default useTicketTypeChange;
