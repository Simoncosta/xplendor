import { useEffect, useState } from "react";
import { Alert, Button, Input, InputGroup, InputGroupText, Label, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import { getMailSettings, saveMailSettings } from "helpers/laravel_helper";

/**
 * E-mail 8 · Perfil da empresa › Correio: o prazo de retenção das mensagens guardadas na XPLENDOR
 * (12 meses por omissão, de 3 a 24). Ao fim do prazo apagam-se de noite; as mensagens no servidor
 * de e-mail de cada pessoa não são tocadas. Muda só o administrador da própria empresa (ou o root);
 * os outros veem o prazo e o histórico.
 */
type Payload = { retention_months: number; default: number; min: number; max: number; info: string; can_edit: boolean;
    history: { before: number | null; after: number; user: string | null; at: string | null }[] };

export default function MailSettingsCard({ companyId }: { companyId: number }) {
    const [data, setData] = useState<Payload | null>(null);
    const [months, setMonths] = useState<string>("");
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        let alive = true;
        getMailSettings(companyId).then((r: any) => { if (alive) { setData(r?.data ?? null); setMonths(String(r?.data?.retention_months ?? "")); } })
            .catch((e: any) => { if (alive) setError(e?.message || "Não foi possível ler as definições do correio."); });
        return () => { alive = false; };
    }, [companyId]);

    const value = Number(months);
    const valid = !!data && Number.isInteger(value) && value >= data.min && value <= data.max;

    const save = async () => {
        if (!data || !valid) return;
        setSaving(true);
        setError(null);
        try {
            const r: any = await saveMailSettings(companyId, value);
            setData(r?.data ?? data);
            toast.success(r?.message || "Prazo de retenção guardado.");
        } catch (e: any) {
            const lists = e?.errors && typeof e.errors === "object" ? Object.values(e.errors).filter(Array.isArray) : [];
            setError((lists.flat()[0] as string | undefined) || e?.message || "Não foi possível guardar o prazo.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <PageCard title="Correio" flush={false} bodyClassName="p-4" loading={!data && !error} skeleton={!data && !error ? "lines" : undefined}
            info="As mensagens que a XPLENDOR guarda: as ligadas a clientes, vendas e leads, e as enviadas pela XPLENDOR."
            actions={data?.can_edit ? (
                <Button size="sm" color="primary" disabled={saving || !valid || value === data.retention_months} onClick={save}>
                    {saving ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />Guardar</>}
                </Button>
            ) : undefined}>
            {error && <Alert color="danger" className="py-2" data-testid="mail-settings-error">{error}</Alert>}
            {data && (
                <>
                    <Label htmlFor="mail-retention" className="form-label">Retenção das mensagens guardadas</Label>
                    <InputGroup style={{ maxWidth: 240 }}>
                        <Input id="mail-retention" type="number" min={data.min} max={data.max} step={1} value={months} disabled={!data.can_edit}
                            invalid={months !== "" && !valid} onChange={(e) => setMonths(e.target.value)} />
                        <InputGroupText>meses</InputGroupText>
                    </InputGroup>
                    <div className="text-muted fs-12 mt-1">Entre {data.min} e {data.max} meses ({data.default} por omissão). {data.info}</div>
                    {!data.can_edit && <div className="text-muted fs-12 mt-1" data-testid="mail-settings-readonly">Só o administrador da empresa muda o prazo.</div>}
                    {data.history.length > 0 && (
                        <div className="mt-4">
                            <div className="fs-13 fw-medium mb-2">Histórico</div>
                            <ul className="list-unstyled fs-13 text-muted mb-0" data-testid="mail-settings-history">
                                {data.history.map((h, i) => (
                                    <li key={i}>
                                        {h.at ? new Date(h.at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : ""} · {h.user ?? "Sistema"}: {h.before ?? data.default} → {h.after} meses
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </>
            )}
        </PageCard>
    );
}
