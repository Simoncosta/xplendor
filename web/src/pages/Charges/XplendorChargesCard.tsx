import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Card, CardBody } from "reactstrap";
import { toast } from "react-toastify";
import { getCompanyCharges, companyChargeInvoicePath, indicateCompanyChargePaid } from "helpers/laravel_helper";
import { openPdfGet } from "helpers/download_helper";
import { CHARGE_STATUS_META, ClientCharge, dmy, euro } from "common/models/charge.model";
import ChargePaidModal from "./ChargePaidModal";

/**
 * Cobranças da XPLENDOR desta empresa, dentro da plataforma (também sem o módulo de
 * Finanças): ver a fatura e indicar "Já paguei". Só aparece quando há cobranças por
 * resolver (em aberto ou com pagamento indicado). A agência gestora não as vê.
 */
const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

export default function XplendorChargesCard({ companyId, onChanged }: { companyId: number; onChanged?: () => void }) {
    const [charges, setCharges] = useState<ClientCharge[] | null>(null);
    const [paying, setPaying] = useState<ClientCharge | null>(null);

    const load = useCallback(() => {
        if (!companyId) return;
        getCompanyCharges(companyId).then((r: any) => setCharges(r?.data?.charges ?? [])).catch(() => setCharges([]));
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    const pending = (charges ?? []).filter((c) => c.status === "open" || c.status === "payment_indicated");
    if (pending.length === 0) return null;

    const submit = async (note: string, proof: File | null): Promise<string | null> => {
        if (!paying) return null;
        const fd = new FormData();
        if (note) fd.append("note", note);
        if (proof) fd.append("proof", proof);
        try {
            await indicateCompanyChargePaid(companyId, paying.id, fd);
            toast.success("Obrigado. A XPLENDOR vai confirmar o pagamento.");
            setPaying(null);
            load();
            onChanged?.();
            return null;
        } catch (e) {
            return errorOf(e, "Não foi possível registar. Tente de novo.");
        }
    };

    return (
        <Card className="border-warning-subtle" data-testid="xplendor-charges-card">
            <CardBody>
                <h5 className="card-title mb-3"><i className="ri-file-list-3-line me-1" />Faturas da XPLENDOR</h5>
                <ul className="list-unstyled mb-0">
                    {pending.map((c) => (
                        <li key={c.id} className="d-flex flex-wrap align-items-center gap-2 py-2 border-top">
                            <div className="me-auto">
                                <div className="fw-medium">{c.description}</div>
                                <div className="text-muted fs-12">
                                    {euro(c.amount)} · vence a {dmy(c.due_date)}{c.overdue && <Badge color="danger" className="ms-2 fw-normal">Vencida</Badge>}
                                </div>
                                {c.refuse_note && <div className="text-danger fs-12 mt-1"><i className="ri-information-line me-1" />{c.refuse_note}</div>}
                            </div>
                            <Badge color={CHARGE_STATUS_META[c.status].color} className="fw-normal">{CHARGE_STATUS_META[c.status].label}</Badge>
                            <Button size="sm" color="light" onClick={async () => { const r = await openPdfGet(companyChargeInvoicePath(companyId, c.id)); if (!r.ok) toast.error("Não foi possível abrir a fatura."); }}>
                                <i className="ri-file-pdf-2-line me-1" />Fatura
                            </Button>
                            {c.can_indicate_payment && <Button size="sm" color="success" onClick={() => setPaying(c)}><i className="ri-check-line me-1" />Já paguei</Button>}
                        </li>
                    ))}
                </ul>
            </CardBody>
            {paying && <ChargePaidModal isOpen toggle={() => setPaying(null)} description={paying.description} amount={paying.amount} onSubmit={submit} />}
        </Card>
    );
}
