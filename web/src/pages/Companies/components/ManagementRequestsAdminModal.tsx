import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { endAdminCompanyManagement, getAdminManagementRequests } from "helpers/laravel_helper";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import { companyErrorText } from "pages/Companies/companyFormData";

/**
 * Pedidos de gestão (só o root): os aceites com a situação de faturação (paga quem dá o
 * acesso: a própria subscrição ou 15 € por mês para a agência) e terminar uma relação.
 */
type Row = {
    id: number; status: string; identifier_type: string; identifier: string | null; identifier_scrubbed: boolean;
    requested_by: string | null; requested_at: string | null; responded_at: string | null;
    agency: { id: number; name: string | null }; matched_company: { id: number; name: string } | null;
    billing: { pays_own: boolean; counts_for_agency: boolean; from_month: string | null; label: string } | null;
    relation_active: boolean;
};
type Filter = "accepted" | "all";

const STATUS: Record<string, { label: string; color: string }> = {
    pending: { label: "Pendente", color: "warning" }, accepted: { label: "Aceite", color: "success" }, declined: { label: "Recusado", color: "danger" },
    withdrawn: { label: "Retirado", color: "secondary" }, expired: { label: "Expirado", color: "secondary" },
};
const MONTHS = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
const monthLabel = (ym: string) => { const [y, m] = ym.split("-").map(Number); return `${MONTHS[m - 1]} de ${y}`; };
const dmy = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT") : "");

type Props = { isOpen: boolean; onClose: () => void; onChanged: () => void };

export default function ManagementRequestsAdminModal({ isOpen, onClose, onChanged }: Props) {
    const [filter, setFilter] = useState<Filter>("accepted");
    const [list, setList] = useState<Row[] | null>(null);
    const [ending, setEnding] = useState<Row | null>(null);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        setList(null);
        getAdminManagementRequests(filter === "accepted" ? "accepted" : undefined).then((r: any) => setList(r?.data?.requests ?? [])).catch(() => setList([]));
    }, [filter]);
    useEffect(() => { if (isOpen) load(); }, [isOpen, load]);

    const end = async () => {
        if (!ending?.matched_company) return;
        setBusy(true);
        try {
            await endAdminCompanyManagement(ending.matched_company.id, reason.trim());
            toast.success(`Relação de ${ending.matched_company.name} com ${ending.agency.name} terminada.`);
            setEnding(null);
            setReason("");
            load();
            onChanged();
        } catch (e: any) { toast.error(companyErrorText(e, "Não foi possível terminar a relação.")); }
        finally { setBusy(false); }
    };

    return (
        <>
            <Modal isOpen={isOpen && !ending} toggle={onClose} size="lg" scrollable centered data-testid="management-requests-admin">
                <ModalHeader toggle={onClose}>Pedidos de gestão</ModalHeader>
                <ModalBody>
                    <div className="xp-seg mb-3" role="tablist" aria-label="Filtro">
                        <button type="button" role="tab" aria-selected={filter === "accepted"} className={filter === "accepted" ? "on" : ""} onClick={() => setFilter("accepted")}>Aceites</button>
                        <button type="button" role="tab" aria-selected={filter === "all"} className={filter === "all" ? "on" : ""} onClick={() => setFilter("all")}>Todos</button>
                    </div>
                    {list === null ? <div className="text-center py-4"><Spinner color="primary" /></div>
                        : list.length === 0 ? <p className="text-muted mb-0">{filter === "accepted" ? "Ainda não há pedidos de gestão aceites." : "Ainda não há pedidos de gestão."}</p>
                            : (
                                <ul className="list-unstyled vstack gap-2 mb-0">
                                    {list.map((r) => (
                                        <li key={r.id} className="border rounded p-3">
                                            <div className="d-flex flex-wrap align-items-start gap-2">
                                                <div className="me-auto">
                                                    <div className="fw-semibold">{r.matched_company?.name ?? (r.identifier_scrubbed ? "Dados apagados" : `${r.identifier_type === "nipc" ? "NIPC" : "Email"}: ${r.identifier}`)}</div>
                                                    <div className="text-muted fs-13">
                                                        Agência {r.agency.name}{r.requested_by ? `, pedido por ${r.requested_by}` : ""} a {dmy(r.requested_at)}
                                                        {r.responded_at ? `; resposta a ${dmy(r.responded_at)}` : ""}.
                                                        {!r.matched_company && r.status !== "accepted" ? " Sem empresa correspondente." : ""}
                                                    </div>
                                                </div>
                                                <Badge color={STATUS[r.status]?.color ?? "secondary"} className="fw-normal">{STATUS[r.status]?.label ?? r.status}</Badge>
                                                {r.status === "accepted" && <Badge color={r.relation_active ? "info" : "light"} className={`fw-normal ${r.relation_active ? "" : "text-muted"}`}>{r.relation_active ? "Relação ativa" : "Relação terminada"}</Badge>}
                                                {r.status === "accepted" && r.relation_active && r.matched_company && (
                                                    <ActionsMenu size="sm" label={`Mais ações: ${r.matched_company.name}`} items={[
                                                        { label: "Terminar relação", icon: "ri-link-unlink", danger: true, onClick: () => { setReason(""); setEnding(r); } },
                                                    ]} />
                                                )}
                                            </div>
                                            {r.billing && !r.relation_active && <div className="text-muted fs-12 mt-2">Relação terminada: a empresa deixou de contar para esta agência.</div>}
                                            {r.billing && r.relation_active && (
                                                <div className={`fs-13 mt-2 p-2 rounded ${r.billing.counts_for_agency ? "bg-warning-subtle" : "bg-success-subtle"}`} data-testid="billing-situation">
                                                    <i className={`${r.billing.counts_for_agency ? "ri-building-4-line" : "ri-bank-card-line"} me-1`} />
                                                    {r.billing.counts_for_agency
                                                        ? <>Conta para a agência: 15 € por mês a partir de {r.billing.from_month ? monthLabel(r.billing.from_month) : "o mês seguinte"}.</>
                                                        : <>Paga a própria subscrição: não conta para a agência.</>}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                </ModalBody>
                <ModalFooter><Button color="light" onClick={onClose}>Fechar</Button></ModalFooter>
            </Modal>

            <Modal isOpen={!!ending} toggle={() => !busy && setEnding(null)} centered>
                <ModalHeader toggle={() => !busy && setEnding(null)}>Terminar a relação de {ending?.matched_company?.name}?</ModalHeader>
                <ModalBody>
                    <p className="mb-2">A agência {ending?.agency.name} deixa de ter acesso <strong>de imediato</strong>. Os dados ficam na empresa; a empresa e a agência são avisadas.</p>
                    <Label for="admin-end-reason" className="mb-1">Motivo</Label>
                    <Input id="admin-end-reason" type="textarea" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
                </ModalBody>
                <ModalFooter>
                    <Button color="light" disabled={busy} onClick={() => setEnding(null)}>Cancelar</Button>
                    <ReasonButton color="danger" disabled={busy} onClick={end} reason={reason.trim().length < 3 ? "Indique o motivo." : null}>
                        {busy ? <Spinner size="sm" /> : "Terminar relação"}
                    </ReasonButton>
                </ModalFooter>
            </Modal>
        </>
    );
}
