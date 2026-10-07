import { useCallback, useEffect, useMemo, useState } from "react";
import { getBrandProfile, getEditorialCalendar, getEditorialFormats } from "helpers/laravel_helper";
import type { FormatTable } from "common/models/editorialWorkflow.model";
import PostPanel, { PanelTarget } from "./PostPanel";
import type { XOption } from "./XSelect";

/**
 * O painel da publicação aberto na EMPRESA dela, a partir de uma vista de várias empresas
 * (Linha Editorial da agência, "Todos os clientes"): carrega o que o painel precisa dessa
 * empresa (âncoras, meses, formatos, pilares, se pode produzir) e mostra o mesmo PostPanel.
 */
type Props = {
    companyId: number | null;
    target: PanelTarget | null;
    onClose: () => void;
    onChanged: () => void;
};

type MonthState = { month_key: string; state: "open" | "closed" };

export default function CompanyPostPanel({ companyId, target, onClose, onChanged }: Props) {
    const [cal, setCal] = useState<any>(null);
    const [formats, setFormats] = useState<FormatTable | null>(null);
    const [pillars, setPillars] = useState<string[]>([]);

    const load = useCallback(() => {
        if (!companyId) return;
        getEditorialCalendar(companyId).then((r: any) => setCal(r?.data ?? null)).catch(() => setCal(null));
        getEditorialFormats(companyId).then((r: any) => setFormats(r?.data ?? null)).catch(() => setFormats(null));
        getBrandProfile(companyId).then((r: any) => setPillars((r?.data?.pillars ?? []).map((x: any) => String(x?.name ?? "")).filter(Boolean))).catch(() => setPillars([]));
    }, [companyId]);
    useEffect(() => { setCal(null); load(); }, [load]);

    const anchors = useMemo(() => {
        const seen = new Set<string>();
        const opts: XOption[] = [{ value: "", label: "Sem âncora" }];
        for (const it of cal?.items ?? []) {
            const key = `${it.owned ? "o" : "a"}:${it.anchor_id}`;
            if (seen.has(key)) continue;
            seen.add(key);
            opts.push({ value: key, label: `${it.title}${it.owned ? " (própria)" : ""}` });
        }
        return opts;
    }, [cal]);
    const months: MonthState[] = useMemo(() => cal?.months ?? [], [cal]);
    const monthOpen = useCallback((date: string) => months.find((m) => m.month_key === date.slice(0, 7))?.state === "open", [months]);

    if (!companyId || !cal) return null;

    return (
        <PostPanel target={target} onClose={onClose} companyId={companyId} anchors={anchors} pillars={pillars}
            range={cal.has_sector ? { from: cal.from, to: cal.to } : null} canProduce={cal.can_produce !== false} formats={formats}
            monthOpen={monthOpen} onCalendar={(c) => { setCal(c); onChanged(); }} onChanged={onChanged} />
    );
}
