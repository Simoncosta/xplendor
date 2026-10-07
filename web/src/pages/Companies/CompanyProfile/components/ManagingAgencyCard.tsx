import { useCallback, useEffect, useState } from "react";
import { Button, Card, CardBody, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { decideCompanyConnections, endCompanyManagement, getCompanyManagement, inviteFirstAdmin } from "helpers/laravel_helper";
import ManagementRequestsCard from "./ManagementRequestsCard";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";

/**
 * Definições da empresa gerida: "Agência gestora: [nome]". Só o admin da própria empresa
 * termina a relação (com confirmação e a escolha sobre as ligações à Meta feitas pela
 * agência): o acesso da agência é cortado de imediato e os dados ficam na empresa. Por cima,
 * os pedidos de gestão por responder; depois de um fim pela agência ou pela XPLENDOR, a
 * escolha pendente sobre as ligações. Pela agência, numa empresa ainda sem admin, convida-se
 * o primeiro administrador do cliente.
 */
/** O helper da API rejeita os 4xx com o corpo da resposta ({ message, errors }). */
const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

type Connection = { kind: string; label: string };
type Management = {
    agency: { id: number; name: string } | null; since: string | null;
    can_end: boolean; via_agency: boolean; can_invite_first_admin: boolean;
    pending_requests?: number; agency_connections?: Connection[];
    connections_decision?: { agency: string | null; connections: Connection[] } | null;
};

export default function ManagingAgencyCard({ companyId, highlight = false }: { companyId: number; highlight?: boolean }) {
    const [m, setM] = useState<Management | null>(null);
    const [connections, setConnections] = useState<"keep" | "disconnect">("keep");
    const [confirm, setConfirm] = useState(false);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);
    const [invite, setInvite] = useState({ name: "", email: "" });

    const load = useCallback(() => {
        getCompanyManagement(companyId).then((r: any) => setM(r.data)).catch(() => setM(null));
    }, [companyId]);
    useEffect(() => { if (companyId) load(); }, [companyId, load]);
    // Vindo de um aviso (?gestao=1): leva o ecrã até aos pedidos ou à agência gestora.
    useEffect(() => {
        if (!highlight || !m) return;
        const t = window.setTimeout(() => document.getElementById("gestao")?.scrollIntoView({ behavior: "smooth", block: "center" }), 400);
        return () => window.clearTimeout(t);
    }, [highlight, m]);

    if (!m) return null;
    const decision = m.connections_decision ?? null;
    const showCard = !!m.agency || m.can_invite_first_admin || !!decision;

    const end = async () => {
        setBusy(true);
        try {
            await endCompanyManagement(companyId, reason.trim(), (m.agency_connections ?? []).length > 0 ? connections : undefined);
            toast.success("Relação terminada. A agência deixou de ter acesso a esta empresa.");
            setConfirm(false);
            load();
        } catch (e: any) {
            toast.error(errorOf(e, "Não foi possível terminar a relação."));
        } finally { setBusy(false); }
    };
    const decide = async (choice: "keep" | "disconnect") => {
        setBusy(true);
        try {
            await decideCompanyConnections(companyId, choice);
            toast.success(choice === "disconnect" ? "Ligações da agência desligadas." : "Ligações mantidas.");
            load();
        } catch (e: any) {
            toast.error(errorOf(e, "Não foi possível guardar a escolha."));
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
        <>
        <ManagementRequestsCard companyId={companyId} pending={m.pending_requests ?? 0} highlight={highlight} onChanged={load} />
        {showCard && <Card data-testid="managing-agency-card" id={(m.pending_requests ?? 0) > 0 ? undefined : "gestao"} className={highlight && !(m.pending_requests ?? 0) ? "border border-warning" : ""}>
            <CardBody>
                <div className="d-flex align-items-start gap-2 mb-3">
                    <h5 className="card-title mb-0 me-auto"><i className="ri-building-2-line me-1" />Agência gestora</h5>
                    {m.agency && m.can_end && (
                        <ActionsMenu size="sm" label={`Mais ações: ${m.agency.name}`} items={[
                            { label: "Terminar relação", icon: "ri-link-unlink", danger: true, onClick: () => setConfirm(true) },
                        ]} />
                    )}
                </div>
                {m.agency ? (
                    <>
                        <p className="mb-1"><strong data-testid="managing-agency-name">{m.agency.name}</strong></p>
                        {m.since && <p className="text-muted fs-13 mb-2">Desde {new Date(m.since).toLocaleDateString("pt-PT")}</p>}
                        <p className="text-muted fs-12 mb-0">
                            A agência produz conteúdos, configura integrações e vê resultados com a própria conta. A aprovação e os acessos são sempre desta empresa.
                        </p>
                    </>
                ) : <p className="text-muted fs-13 mb-2">Sem agência gestora.</p>}

                {decision && (
                    <div className="border-top mt-3 pt-3" data-testid="connections-decision">
                        <h6 className="fs-13 mb-1">Ligações deixadas pela agência{decision.agency ? ` ${decision.agency}` : ""}</h6>
                        <p className="text-muted fs-12 mb-2">
                            A relação terminou. A agência ligou em nome da sua empresa: {decision.connections.map((c) => c.label).join(", ")}. Escolha se as quer manter ou desligar.
                        </p>
                        <div className="d-flex flex-wrap gap-2">
                            <Button size="sm" color="outline-primary" disabled={busy} onClick={() => decide("disconnect")}>Desligar</Button>
                            <Button size="sm" color="primary" disabled={busy} onClick={() => decide("keep")}>Manter</Button>
                        </div>
                    </div>
                )}

                {m.can_invite_first_admin && (
                    <div className="border-top mt-3 pt-3">
                        <h6 className="fs-13 mb-1">Convidar o primeiro administrador</h6>
                        <p className="text-muted fs-12 mb-2">Esta empresa ainda não tem administrador. Com conta, o cliente aprova na plataforma e gere os acessos.</p>
                        <Label for="first-admin-name" className="visually-hidden">Nome</Label>
                        <Input id="first-admin-name" bsSize="sm" className="mb-2" placeholder="Nome" value={invite.name} onChange={(e) => setInvite({ ...invite, name: e.target.value })} />
                        <Label for="first-admin-email" className="visually-hidden">Email</Label>
                        <Input id="first-admin-email" bsSize="sm" type="email" className="mb-2" placeholder="Email" value={invite.email} onChange={(e) => setInvite({ ...invite, email: e.target.value })} />
                        <ReasonButton size="sm" color="outline-primary" disabled={busy} onClick={sendInvite}
                            reason={!invite.name.trim() || !invite.email.trim() ? "Indique o nome e o email." : null}>
                            {busy ? <Spinner size="sm" /> : "Enviar convite"}
                        </ReasonButton>
                    </div>
                )}
            </CardBody>

            <Modal isOpen={confirm} toggle={() => !busy && setConfirm(false)} centered>
                <ModalHeader toggle={() => !busy && setConfirm(false)}>Terminar a relação com {m.agency?.name}?</ModalHeader>
                <ModalBody>
                    <p className="mb-2">A agência deixa de ter acesso a esta empresa <strong>de imediato</strong>. As publicações, os ficheiros e as integrações ficam na empresa.</p>
                    <Label for="end-reason" className="mb-1">Motivo <span className="text-muted fw-normal">(opcional)</span></Label>
                    <Input id="end-reason" type="textarea" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
                    {(m.agency_connections ?? []).length > 0 && (
                        <div className="mt-3">
                            <div className="fw-medium fs-13 mb-1">Ligações feitas pela agência</div>
                            <p className="text-muted fs-12 mb-2">{(m.agency_connections ?? []).map((c) => c.label).join(", ")}. O que quer fazer com elas?</p>
                            <div className="form-check">
                                <Input type="radio" className="form-check-input" id="conn-keep" name="conn" checked={connections === "keep"} onChange={() => setConnections("keep")} />
                                <Label className="form-check-label fs-13" for="conn-keep">Manter (continuam a funcionar na sua empresa)</Label>
                            </div>
                            <div className="form-check">
                                <Input type="radio" className="form-check-input" id="conn-disconnect" name="conn" checked={connections === "disconnect"} onChange={() => setConnections("disconnect")} />
                                <Label className="form-check-label fs-13" for="conn-disconnect">Desligar (os dados já recolhidos ficam)</Label>
                            </div>
                        </div>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" disabled={busy} onClick={() => setConfirm(false)}>Cancelar</Button>
                    <Button color="danger" disabled={busy} onClick={end}>{busy ? <Spinner size="sm" /> : "Terminar relação"}</Button>
                </ModalFooter>
            </Modal>
        </Card>}
        </>
    );
}
