import { useCallback, useEffect, useState } from "react";
import { Button, Card, CardBody, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { endCompanyManagement, getCompanyManagement, inviteFirstAdmin } from "helpers/laravel_helper";

/**
 * Definições da empresa gerida: "Agência gestora: [nome]". Só o admin da própria empresa
 * termina a relação (com confirmação): o acesso da agência é cortado de imediato e os
 * dados ficam na empresa. Pela agência, numa empresa ainda sem admin, convida-se o
 * primeiro administrador do cliente.
 */
/** O helper da API rejeita os 4xx com o corpo da resposta ({ message, errors }). */
const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

type Management = {
    agency: { id: number; name: string } | null; since: string | null;
    can_end: boolean; via_agency: boolean; can_invite_first_admin: boolean;
};

export default function ManagingAgencyCard({ companyId }: { companyId: number }) {
    const [m, setM] = useState<Management | null>(null);
    const [confirm, setConfirm] = useState(false);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);
    const [invite, setInvite] = useState({ name: "", email: "" });

    const load = useCallback(() => {
        getCompanyManagement(companyId).then((r: any) => setM(r.data)).catch(() => setM(null));
    }, [companyId]);
    useEffect(() => { if (companyId) load(); }, [companyId, load]);

    if (!m || (!m.agency && !m.can_invite_first_admin)) return null;

    const end = async () => {
        setBusy(true);
        try {
            await endCompanyManagement(companyId, reason.trim());
            toast.success("Relação terminada. A agência deixou de ter acesso a esta empresa.");
            setConfirm(false);
            load();
        } catch (e: any) {
            toast.error(errorOf(e, "Não foi possível terminar a relação."));
        } finally { setBusy(false); }
    };
    const sendInvite = async () => {
        setBusy(true);
        try {
            await inviteFirstAdmin(companyId, invite);
            toast.success("Convite enviado ao administrador da empresa.");
            setInvite({ name: "", email: "" });
            load();
        } catch (e: any) {
            toast.error(errorOf(e, "Não foi possível enviar o convite."));
        } finally { setBusy(false); }
    };

    return (
        <Card data-testid="managing-agency-card">
            <CardBody>
                <h5 className="card-title mb-3"><i className="ri-building-2-line me-1" />Agência gestora</h5>
                {m.agency ? (
                    <>
                        <p className="mb-1"><strong data-testid="managing-agency-name">{m.agency.name}</strong></p>
                        {m.since && <p className="text-muted fs-13 mb-2">Desde {new Date(m.since).toLocaleDateString("pt-PT")}</p>}
                        <p className="text-muted fs-12 mb-3">
                            A agência produz conteúdos, configura integrações e vê resultados com a própria conta. A aprovação e os acessos são sempre desta empresa.
                        </p>
                        {m.can_end && (
                            <Button color="soft-danger" size="sm" onClick={() => setConfirm(true)}>
                                <i className="ri-link-unlink me-1" />Terminar relação
                            </Button>
                        )}
                    </>
                ) : <p className="text-muted fs-13 mb-2">Sem agência gestora.</p>}

                {m.can_invite_first_admin && (
                    <div className="border-top mt-3 pt-3">
                        <h6 className="fs-13 mb-1">Convidar o primeiro administrador</h6>
                        <p className="text-muted fs-12 mb-2">Esta empresa ainda não tem administrador. Com conta, o cliente aprova na plataforma e gere os acessos.</p>
                        <Label for="first-admin-name" className="visually-hidden">Nome</Label>
                        <Input id="first-admin-name" bsSize="sm" className="mb-2" placeholder="Nome" value={invite.name} onChange={(e) => setInvite({ ...invite, name: e.target.value })} />
                        <Label for="first-admin-email" className="visually-hidden">Email</Label>
                        <Input id="first-admin-email" bsSize="sm" type="email" className="mb-2" placeholder="Email" value={invite.email} onChange={(e) => setInvite({ ...invite, email: e.target.value })} />
                        <Button size="sm" color="primary" disabled={busy || !invite.name.trim() || !invite.email.trim()} onClick={sendInvite}>
                            {busy ? <Spinner size="sm" /> : "Enviar convite"}
                        </Button>
                    </div>
                )}
            </CardBody>

            <Modal isOpen={confirm} toggle={() => !busy && setConfirm(false)} centered>
                <ModalHeader toggle={() => !busy && setConfirm(false)}>Terminar a relação com {m.agency?.name}?</ModalHeader>
                <ModalBody>
                    <p className="mb-2">A agência deixa de ter acesso a esta empresa <strong>de imediato</strong>. As publicações, os ficheiros e as integrações ficam na empresa.</p>
                    <Label for="end-reason" className="mb-1">Motivo <span className="text-muted fw-normal">(opcional)</span></Label>
                    <Input id="end-reason" type="textarea" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
                </ModalBody>
                <ModalFooter>
                    <Button color="light" disabled={busy} onClick={() => setConfirm(false)}>Cancelar</Button>
                    <Button color="danger" disabled={busy} onClick={end}>{busy ? <Spinner size="sm" /> : "Terminar relação"}</Button>
                </ModalFooter>
            </Modal>
        </Card>
    );
}
