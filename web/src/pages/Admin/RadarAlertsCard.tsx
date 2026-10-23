import { useEffect, useState } from "react";
import { Badge, Button, Input, Label, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import { updateAdminRadarAlerts } from "helpers/laravel_helper";
import type { RadarAdminAlerts } from "common/models/radar.model";

/**
 * Bússola 7: os avisos dos sinais de nota alta (sino e e-mail). O root muda a relevância da IA (0 a 100)
 * a partir da qual se avisa; no máximo um aviso por dia e por empresa, só no horário indicado (Lisboa).
 */
export default function RadarAlertsCard({ alerts, onSaved }: { alerts: RadarAdminAlerts | undefined; onSaved: (a: RadarAdminAlerts) => void }) {
    const [value, setValue] = useState("");
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (alerts) setValue(String(alerts.min_score)); }, [alerts]);
    if (!alerts) return null;
    const n = Number(value);
    const invalid = value.trim() === "" || !Number.isInteger(n) || n < 0 || n > 100;
    const save = async () => {
        if (invalid) return;
        setSaving(true);
        try {
            const res: any = await updateAdminRadarAlerts({ min_score: n });
            toast.success(res?.message ?? "Guardado.");
            if (res?.data) onSaved(res.data);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        } finally {
            setSaving(false);
        }
    };
    return (
        <PageCard title="Avisos de nota alta" data-testid="radar-alerts"
            info={`Um sinal cuja relevância dada pela IA ao ramo (de 0 a 100) é igual ou acima deste valor gera um aviso no sino e por e-mail, no máximo um por dia e por empresa, entre as ${alerts.window.from} e as ${alerts.window.to} (Lisboa). Com vários, avisa primeiro o mais bem colocado no Radar. Cada pessoa pode desligar os seus no cartão do Radar.`}
            status={<Badge color={alerts.email_enabled ? "success-subtle" : "secondary-subtle"} className={alerts.email_enabled ? "text-success" : "text-secondary"}>{alerts.email_enabled ? "E-mail ligado" : "E-mail desligado (chamadas externas)"}</Badge>}>
            <div className="p-3">
                <div className="d-flex flex-wrap align-items-end gap-2">
                    <div>
                        <Label for="radar-alert-score" className="fs-13">Relevância mínima da IA (0 a 100; por omissão {alerts.default_min_score})</Label>
                        <Input id="radar-alert-score" type="number" min={0} max={100} step={1} value={value} invalid={invalid} onChange={(e) => setValue(e.target.value)} style={{ maxWidth: 140 }} />
                    </div>
                    <Button color="primary" disabled={saving || invalid || n === alerts.min_score} onClick={save} data-testid="radar-alerts-save">{saving ? <Spinner size="sm" /> : "Guardar"}</Button>
                </div>
                <p className="text-muted fs-13 mb-0 mt-3" data-testid="radar-alerts-today">{alerts.sent_today === 1 ? "1 aviso enviado hoje." : `${alerts.sent_today} avisos enviados hoje.`}</p>
            </div>
        </PageCard>
    );
}
