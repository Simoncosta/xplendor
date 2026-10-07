import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Label, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getAdminAgencies, getAdminCompanyManagement, setAdminCompanyAgency, setAdminCompanyManagement } from "helpers/laravel_helper";
import XSelect from "pages/Editorial/XSelect";
import { confirmAction } from "helpers/swal";

/**
 * Gestão por agências de UMA empresa (só o root): "Esta empresa é uma agência" e "Gerida
 * por" (definir, mudar ou retirar), com o histórico da relação. Mudar de agência termina a
 * relação atual e cria outra; tudo fica no histórico.
 */

type History = {
    id: number; agency: { id: number; name: string }; origin: string; status: string;
    started_at: string | null; started_by: string | null; ended_at: string | null; ended_by: string | null;
    ended_by_side: string | null; end_reason: string | null;
};
type Payload = {
    company: { id: number; name: string }; is_agency: boolean; agency_notification_email: string | null;
    current: History | null; history: History[];
};

const ORIGIN: Record<string, string> = { platform: "Definida pela plataforma", created_by_agency: "Criada pela agência", request_accepted: "Pedido aceite" };
const STATUS: Record<string, { label: string; color: string }> = {
    active: { label: "Ativa", color: "success" }, pending: { label: "Pendente", color: "warning" }, declined: { label: "Recusada", color: "secondary" },
    withdrawn: { label: "Retirada", color: "secondary" }, ended: { label: "Terminada", color: "secondary" }, expired: { label: "Expirada", color: "secondary" },
};
const SIDE: Record<string, string> = { company: "pela empresa", agency: "pela agência", platform: "pela plataforma" };
const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT") : "");
/** O helper da API rejeita os 4xx com o corpo da resposta ({ message, errors }). */
const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

export default function AgencyManagementPanel({ companyId, onChanged }: { companyId: number; onChanged?: () => void }) {
    const [data, setData] = useState<Payload | null>(null);
    const [agencies, setAgencies] = useState<{ id: number; name: string }[]>([]);
    const [isAgency, setIsAgency] = useState(false);
    const [email, setEmail] = useState("");
    const [agencyId, setAgencyId] = useState(0);
    const [busy, setBusy] = useState<"agency" | "managed" | null>(null);

    const apply = (d: Payload) => {
        setData(d);
        setIsAgency(d.is_agency);
        setEmail(d.agency_notification_email ?? "");
        setAgencyId(d.current?.agency.id ?? 0);
    };
    const load = useCallback(() => {
        Promise.all([getAdminCompanyManagement(companyId), getAdminAgencies()])
            .then(([m, a]: any[]) => { apply(m.data); setAgencies(a.data.agencies ?? []); })
            .catch(() => toast.error("Não foi possível carregar a gestão por agências."));
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    const saveAgency = async () => {
        setBusy("agency");
        try {
            const r: any = await setAdminCompanyAgency(companyId, isAgency, email);
            apply(r.data);
            toast.success(isAgency ? "Empresa marcada como agência. A Linha Editorial fica ativa." : "A empresa deixou de ser agência.");
            getAdminAgencies().then((a: any) => setAgencies(a.data.agencies ?? []));
            onChanged?.();
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível guardar."));
            setIsAgency(!!data?.is_agency);
        } finally { setBusy(null); }
    };
    const saveManaged = async () => {
        if (!agencyId) {
            const ok = await confirmAction({
                title: "Retirar a gestão?", text: `A agência ${data?.current?.agency.name ?? ""} deixa de gerir esta empresa.`,
                confirmText: "Retirar gestão", icon: "warning", confirmVariant: "danger",
            });
            if (!ok) return;
        }
        setBusy("managed");
        try {
            const r: any = await setAdminCompanyManagement(companyId, agencyId || null);
            apply(r.data);
            toast.success(agencyId ? "Agência gestora definida." : "Gestão retirada.");
            onChanged?.();
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível guardar."));
        } finally { setBusy(null); }
    };

    if (!data) return <div className="text-center py-3"><Spinner size="sm" /></div>;

    const options = [{ value: 0, label: "Sem agência gestora" }, ...agencies.filter((a) => a.id !== companyId).map((a) => ({ value: a.id, label: a.name }))];
    const agencyDirty = isAgency !== data.is_agency || (isAgency && email !== (data.agency_notification_email ?? ""));
    const managedDirty = agencyId !== (data.current?.agency.id ?? 0);

    return (
        <div data-testid="agency-panel">
            <div className="form-check form-switch mb-2">
                <Input type="switch" className="form-check-input" id={`agency-${companyId}`} checked={isAgency}
                    disabled={!!data.current} onChange={(e) => setIsAgency(e.target.checked)} />
                <Label className="form-check-label" for={`agency-${companyId}`}>Esta empresa é uma agência</Label>
            </div>
            {data.current && <p className="text-muted fs-12 mb-2">Uma empresa gerida por uma agência não pode ser ela própria uma agência.</p>}
            {isAgency && (
                <div className="mb-2">
                    <Label for={`agency-email-${companyId}`} className="mb-1 fs-13">Email para avisos da agência <span className="text-muted fw-normal">(opcional)</span></Label>
                    <Input id={`agency-email-${companyId}`} type="email" bsSize="sm" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="equipa@agencia.pt" />
                </div>
            )}
            {agencyDirty && (
                <Button size="sm" color="primary" className="mb-3" disabled={busy !== null} onClick={saveAgency}>
                    {busy === "agency" ? <Spinner size="sm" /> : "Guardar"}
                </Button>
            )}

            <Label for={`managed-by-${companyId}`} className="mb-1 d-block mt-2">Gerida por</Label>
            <div className="d-flex flex-wrap gap-2 align-items-start mb-1">
                <div className="flex-grow-1" style={{ minWidth: 220 }}>
                    <XSelect id={`managed-by-${companyId}`} options={options} value={agencyId} onChange={setAgencyId} disabled={data.is_agency || busy !== null} searchable />
                </div>
                {managedDirty && (
                    <Button size="sm" color="primary" disabled={busy !== null} onClick={saveManaged}>
                        {busy === "managed" ? <Spinner size="sm" /> : agencyId ? "Guardar agência gestora" : "Retirar gestão"}
                    </Button>
                )}
            </div>
            {data.is_agency && <p className="text-muted fs-12">Uma agência não pode ser gerida por outra agência.</p>}

            <h6 className="mt-3 mb-2 fs-13">Histórico da relação</h6>
            {data.history.length === 0 ? <p className="text-muted fs-13 mb-0">Sem relações de gestão.</p> : (
                <ul className="list-unstyled mb-0 fs-13">
                    {data.history.map((h) => (
                        <li key={h.id} className="border-top py-2">
                            <div className="d-flex flex-wrap align-items-center gap-2">
                                <strong>{h.agency.name}</strong>
                                <Badge color={STATUS[h.status]?.color ?? "secondary"} className="fw-normal">{STATUS[h.status]?.label ?? h.status}</Badge>
                                <span className="text-muted fs-12">{ORIGIN[h.origin] ?? h.origin}</span>
                            </div>
                            <div className="text-muted fs-12">
                                Desde {date(h.started_at)}{h.started_by ? `, por ${h.started_by}` : ""}
                                {h.ended_at && <>; terminada a {date(h.ended_at)} {SIDE[h.ended_by_side ?? ""] ?? ""}{h.ended_by ? ` (${h.ended_by})` : ""}{h.end_reason ? `: ${h.end_reason}` : ""}</>}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
