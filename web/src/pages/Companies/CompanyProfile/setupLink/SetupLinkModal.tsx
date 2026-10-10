import { useEffect, useState } from "react";
import { Button, Label, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import XSelect from "Components/Common/Select";
import { getAdminCompanies } from "helpers/laravel_helper";
import type { SetupStepKey } from "common/models/setupLink.model";
import SetupLinkPanel from "./SetupLinkPanel";

/**
 * O link de configuração numa janela: no Painel da agência (empresa fixa) e no ticket de
 * arranque (a equipa escolhe a empresa: num orçamento de prospeto o ticket está na XPLENDOR).
 */
type Props = {
    isOpen: boolean;
    onClose: () => void;
    companyId: number | null;
    companyName?: string;
    /** Ticket de arranque: mostra a escolha da empresa e guarda o ticket no link. */
    supportTicketId?: number | null;
    presetSteps?: SetupStepKey[];
};

export default function SetupLinkModal({ isOpen, onClose, companyId, companyName, supportTicketId, presetSteps }: Props) {
    const [chosen, setChosen] = useState<number | null>(companyId);
    const [companies, setCompanies] = useState<{ value: number; label: string }[]>([]);
    useEffect(() => { if (isOpen) setChosen(companyId); }, [isOpen, companyId]);
    useEffect(() => {
        if (!isOpen || !supportTicketId || companies.length) return;
        getAdminCompanies().then((r: any) => setCompanies((r?.data?.companies ?? []).map((c: any) => ({ value: c.id, label: c.name })))).catch(() => setCompanies([]));
    }, [isOpen, supportTicketId, companies.length]);

    const name = supportTicketId ? companies.find((c) => c.value === chosen)?.label : companyName;

    return (
        <Modal isOpen={isOpen} toggle={onClose} centered scrollable data-testid="setup-link-modal">
            <ModalHeader toggle={onClose}>Link de configuração{name ? `: ${name}` : ""}</ModalHeader>
            <ModalBody>
                {supportTicketId && (
                    <div className="mb-3">
                        <Label for="setup-link-company" className="mb-1">Empresa a configurar</Label>
                        <XSelect id="setup-link-company" options={companies} value={chosen} onChange={setChosen} searchable placeholder="Escolher a empresa…" />
                        <p className="text-muted fs-12 mt-1 mb-0">As tarefas de acesso deste ticket marcam-se sozinhas quando o cliente concluir cada passo.</p>
                    </div>
                )}
                {chosen ? <SetupLinkPanel key={chosen} companyId={chosen} supportTicketId={supportTicketId} presetSteps={presetSteps} startGenerating />
                    : <p className="text-muted mb-0">Escolha a empresa para gerar o link.</p>}
            </ModalBody>
            <ModalFooter><Button color="light" onClick={onClose}>Fechar</Button></ModalFooter>
        </Modal>
    );
}
