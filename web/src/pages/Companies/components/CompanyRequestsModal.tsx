import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { decideAdminCompanyRequest, getAdminCompanyRequests } from "helpers/laravel_helper";
import { companyErrorText } from "pages/Companies/companyFormData";
import ActionsMenu from "Components/Common/ActionsMenu";

/**
 * Pedidos de nova empresa gerida (só o root): as agências pedem, a XPLENDOR aprova (a
 * empresa nasce gerida pela agência) ou recusa com o motivo. O root continua a poder criar
 * empresas diretamente.
 */
type Req = {
    id: number; status: "pending" | "approved" | "declined"; name: string;
    agency: { id: number; name: string | null }; sector: { id: number; name: string } | null;
    contact_name: string | null; contact_email: string | null; contact_phone: string | null; note: string | null;
    requested_by: string | null; requested_at: string | null; decided_by: string | null; decided_at: string | null; decline_reason: string | null;
};
type Filter = "pending" | "all";

const STATUS: Record<Req["status"], { label: string; color: string }> = {
    pending: { label: "Por decidir", color: "warning" },
    approved: { label: "Aprovado", color: "success" },
    declined: { label: "Recusado", color: "danger" },
};
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "");

type Props = { isOpen: boolean; onClose: () => void; onDecided: () => void };

export default function CompanyRequestsModal({ isOpen, onClose, onDecided }: Props) {
    const [filter, setFilter] = useState<Filter>("pending");
    const [list, setList] = useState<Req[] | null>(null);
    const [busy, setBusy] = useState(0);
    const [declining, setDeclining] = useState<Req | null>(null);
    const [reason, setReason] = useState("");

    const load = useCallback(() => {
        setList(null);
        getAdminCompanyRequests(filter === "pending" ? "pending" : undefined)
            .then((r: any) => setList(r?.data?.requests ?? []))
            .catch(() => setList([]));
    }, [filter]);
    useEffect(() => { if (isOpen) load(); }, [isOpen, load]);

    const decide = async (r: Req, decision: "approve" | "decline") => {
        setBusy(r.id);
        try {
            await decideAdminCompanyRequest(r.id, decision, decision === "decline" ? reason.trim() : undefined);
            toast.success(decision === "approve" ? `${r.name} criada e gerida por ${r.agency.name}.` : "Pedido recusado. A agência foi avisada.");
            setDeclining(null);
            setReason("");
            load();
            onDecided();
        } catch (e: any) {
            toast.error(companyErrorText(e, "Não foi possível decidir o pedido."));
        } finally {
            setBusy(0);
        }
    };

    return (
        <>
            <Modal isOpen={isOpen && !declining} toggle={onClose} size="lg" scrollable centered data-testid="company-requests-modal">
                <ModalHeader toggle={onClose}>Pedidos de nova empresa gerida</ModalHeader>
                <ModalBody>
                    <Nav pills className="mb-3">
                        {([["pending", "Por decidir"], ["all", "Todos"]] as const).map(([k, l]) => (
                            <NavItem key={k}><NavLink href="#" active={filter === k} onClick={(e) => { e.preventDefault(); setFilter(k); }}>{l}</NavLink></NavItem>
                        ))}
                    </Nav>
                    {list === null ? <div className="text-center py-4"><Spinner color="primary" /></div>
                        : list.length === 0 ? <p className="text-muted mb-0">{filter === "pending" ? "Não há pedidos por decidir." : "Ainda não há pedidos."}</p>
                            : (
                                <ul className="list-unstyled vstack gap-2 mb-0">
                                    {list.map((r) => (
                                        <li key={r.id} className="border rounded p-3">
                                            <div className="d-flex flex-wrap align-items-start gap-2">
                                                <div className="me-auto">
                                                    <div className="fw-semibold">{r.name}</div>
                                                    <div className="text-muted fs-13">Pedido por {r.requested_by ?? "?"} ({r.agency.name}), {fmt(r.requested_at)}</div>
                                                </div>
                                                <Badge color={STATUS[r.status].color} className="fw-normal">{STATUS[r.status].label}</Badge>
                                            </div>
                                            <dl className="row fs-13 mb-0 mt-2">
                                                <dt className="col-sm-3 fw-normal text-muted">Ramo</dt><dd className="col-sm-9 mb-1">{r.sector?.name ?? "Sem ramo (módulos base)"}</dd>
                                                {(r.contact_name || r.contact_email || r.contact_phone) && (<>
                                                    <dt className="col-sm-3 fw-normal text-muted">Contacto</dt>
                                                    <dd className="col-sm-9 mb-1">{[r.contact_name, r.contact_email, r.contact_phone].filter(Boolean).join(", ")}</dd>
                                                </>)}
                                                {r.note && (<><dt className="col-sm-3 fw-normal text-muted">Nota</dt><dd className="col-sm-9 mb-1" style={{ whiteSpace: "pre-wrap" }}>{r.note}</dd></>)}
                                                {r.decided_at && (<>
                                                    <dt className="col-sm-3 fw-normal text-muted">Decisão</dt>
                                                    <dd className="col-sm-9 mb-1">{r.decided_by}, {fmt(r.decided_at)}{r.decline_reason ? `. Motivo: ${r.decline_reason}` : ""}</dd>
                                                </>)}
                                            </dl>
                                            {r.status === "pending" && (
                                                <div className="d-flex flex-wrap gap-2 mt-2">
                                                    <Button size="sm" color="success" disabled={busy === r.id} onClick={() => decide(r, "approve")}>
                                                        {busy === r.id ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aprovar e criar a empresa</>}
                                                    </Button>
                                                    <ActionsMenu size="sm" label={`Mais ações: ${r.name}`} disabled={busy === r.id} items={[
                                                        { label: "Recusar", icon: "ri-close-line", danger: true, onClick: () => { setReason(""); setDeclining(r); } },
                                                    ]} />
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                </ModalBody>
                <ModalFooter><Button color="light" onClick={onClose}>Fechar</Button></ModalFooter>
            </Modal>

            <Modal isOpen={!!declining} toggle={() => setDeclining(null)} centered>
                <ModalHeader toggle={() => setDeclining(null)}>Recusar o pedido: {declining?.name}</ModalHeader>
                <ModalBody>
                    <Label for="decline-reason" className="mb-1">Motivo (a agência vê-o)</Label>
                    <Input id="decline-reason" type="textarea" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} />
                </ModalBody>
                <ModalFooter className="flex-wrap">
                    {!reason.trim() && <small className="text-muted me-auto">Escreva o motivo para recusar.</small>}
                    <Button color="light" onClick={() => setDeclining(null)}>Voltar</Button>
                    <Button color="danger" disabled={!reason.trim() || busy !== 0} onClick={() => declining && decide(declining, "decline")}>
                        {busy ? <Spinner size="sm" /> : "Recusar o pedido"}
                    </Button>
                </ModalFooter>
            </Modal>
        </>
    );
}
