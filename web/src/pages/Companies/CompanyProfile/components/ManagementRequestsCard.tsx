import { useCallback, useEffect, useState } from "react";
import { Button, Card, CardBody, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { acceptCompanyManagementRequest, declineCompanyManagementRequest, getCompanyManagementRequests } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";

/**
 * Pedidos de gestão recebidos (só os admins da própria empresa): que agência pede, a mensagem,
 * e o que a agência poderá e não poderá fazer. Aceitar ou recusar (motivo opcional) é sempre
 * aqui, na app, nunca por um link público.
 */
type Req = {
    id: number; agency: { id: number; name: string }; requested_by: string | null; message: string | null;
    requested_at: string | null; expires_at: string | null;
    scope: { can: string[]; cannot: string[]; note: string };
};

const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};
const dmy = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT") : "");

type Props = {
    companyId: number; pending: number; highlight?: boolean; onChanged: () => void;
    /** Depois de aceitar ou recusar (a página do pedido mostra o resultado). */
    onAccepted?: (agencyName: string) => void;
    onDeclined?: () => void;
};

export default function ManagementRequestsCard({ companyId, pending, highlight, onChanged, onAccepted, onDeclined }: Props) {
    const [list, setList] = useState<Req[] | null>(null);
    const [busy, setBusy] = useState(0);
    const [declining, setDeclining] = useState<Req | null>(null);
    const [reason, setReason] = useState("");

    const load = useCallback(() => {
        getCompanyManagementRequests(companyId).then((r: any) => setList(r?.data?.requests ?? [])).catch(() => setList([]));
    }, [companyId]);
    useEffect(() => { if (pending > 0) load(); else setList([]); }, [pending, load]);

    if (!list || list.length === 0) return null;

    const accept = async (r: Req) => {
        const ok = await confirmAction({
            title: `Aceitar a gestão pela agência ${r.agency.name}?`,
            text: "A agência passa a trabalhar na sua empresa com o âmbito indicado. Pode terminar a relação a qualquer momento.",
            confirmText: "Aceitar", icon: "question", confirmVariant: "success",
        });
        if (!ok) return;
        setBusy(r.id);
        try {
            await acceptCompanyManagementRequest(companyId, r.id);
            toast.success(`A agência ${r.agency.name} passou a gerir a sua empresa.`);
            onAccepted?.(r.agency.name);
            onChanged();
            load();
        } catch (e: any) { toast.error(errorOf(e, "Não foi possível aceitar o pedido.")); }
        finally { setBusy(0); }
    };
    const decline = async () => {
        if (!declining) return;
        setBusy(declining.id);
        try {
            await declineCompanyManagementRequest(companyId, declining.id, reason.trim());
            toast.success("Pedido recusado. A agência foi avisada.");
            setDeclining(null);
            setReason("");
            onDeclined?.();
            onChanged();
            load();
        } catch (e: any) { toast.error(errorOf(e, "Não foi possível recusar o pedido.")); }
        finally { setBusy(0); }
    };

    return (
        <Card id="gestao" data-testid="management-requests-card" className={highlight ? "border border-warning" : ""}>
            <CardBody>
                <h5 className="card-title mb-3"><i className="ri-mail-unread-line me-1" />Pedidos de gestão</h5>
                <div className="vstack gap-3">
                    {list.map((r) => (
                        <div key={r.id} className="border rounded p-3">
                            <div className="fw-semibold">{r.agency.name}</div>
                            <div className="text-muted fs-12 mb-2">
                                Pedido{r.requested_by ? ` por ${r.requested_by}` : ""} a {dmy(r.requested_at)}. Expira a {dmy(r.expires_at)}.
                            </div>
                            {r.message && <p className="fs-13 mb-2" style={{ whiteSpace: "pre-wrap" }}>{r.message}</p>}
                            <div className="fs-12 fw-semibold mb-1">O que a agência poderá fazer</div>
                            <ul className="fs-12 ps-3 mb-2">{r.scope.can.map((t) => <li key={t}>{t}</li>)}</ul>
                            <div className="fs-12 fw-semibold mb-1">O que a agência não poderá fazer</div>
                            <ul className="fs-12 ps-3 mb-2">{r.scope.cannot.map((t) => <li key={t}>{t}</li>)}</ul>
                            <p className="text-muted fs-12 mb-3">{r.scope.note}</p>
                            <div className="d-flex flex-wrap gap-2">
                                <Button size="sm" color="outline-primary" disabled={busy === r.id} onClick={() => { setReason(""); setDeclining(r); }}>Recusar</Button>
                                <Button size="sm" color="success" disabled={busy === r.id} onClick={() => accept(r)}>
                                    {busy === r.id ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aceitar</>}
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            </CardBody>

            <Modal isOpen={!!declining} toggle={() => !busy && setDeclining(null)} centered>
                <ModalHeader toggle={() => !busy && setDeclining(null)}>Recusar o pedido da agência {declining?.agency.name}?</ModalHeader>
                <ModalBody>
                    <Label for="decline-mgmt-reason" className="mb-1">Motivo <span className="text-muted fw-normal">(opcional; a agência vê-o)</span></Label>
                    <Input id="decline-mgmt-reason" type="textarea" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
                </ModalBody>
                <ModalFooter>
                    <Button color="light" disabled={!!busy} onClick={() => setDeclining(null)}>Cancelar</Button>
                    <Button color="primary" disabled={!!busy} onClick={decline}>{busy ? <Spinner size="sm" /> : "Recusar o pedido"}</Button>
                </ModalFooter>
            </Modal>
        </Card>
    );
}
