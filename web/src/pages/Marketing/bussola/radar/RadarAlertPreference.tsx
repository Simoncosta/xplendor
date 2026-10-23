import { useEffect, useState } from "react";
import { Input, Label } from "reactstrap";
import { toast } from "react-toastify";
import { getAlertPreferences, updateAlertPreferences } from "helpers/laravel_helper";

/**
 * Bússola 7: a preferência de quem está ligado para os avisos dos sinais de nota alta (no sino e por
 * e-mail, no máximo um por dia e por empresa, das 9 às 20 horas). Cada pessoa liga ou desliga os seus.
 */
export default function RadarAlertPreference({ companyId }: { companyId: number }) {
    const [on, setOn] = useState<boolean | null>(null);
    const [saving, setSaving] = useState(false);
    useEffect(() => {
        let alive = true;
        getAlertPreferences(companyId)
            .then((res: any) => { if (alive) setOn(res?.data?.radar_high_score !== false); })
            .catch(() => { if (alive) setOn(null); });
        return () => { alive = false; };
    }, [companyId]);
    if (on === null) return null;
    const change = async (next: boolean) => {
        setSaving(true);
        try {
            const res: any = await updateAlertPreferences(companyId, { radar_high_score: next });
            setOn(res?.data?.radar_high_score !== false);
            toast.success(res?.message ?? "Preferência guardada.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar a preferência.");
        } finally {
            setSaving(false);
        }
    };
    return (
        <div className="form-check form-switch fs-13 mb-3" data-testid="radar-alert-preference">
            <Input id="radar-alert-pref" type="switch" role="switch" checked={on} disabled={saving} onChange={(e) => change(e.target.checked)} />
            <Label for="radar-alert-pref" className="form-check-label">Avisar-me no sino e por e-mail quando houver um sinal de nota alta (no máximo um por dia)</Label>
        </div>
    );
}
