import { CountEntry, CountUnit } from "common/models/stockcount.model";

/**
 * XPLENDOR — Fatia 7b: a soma do que se contou em várias unidades (2 Grades 24 + 5 Unidades), na
 * unidade de stock. Vazio = não contado (null); "0" = zero contado. O fator vem do servidor (unidade de
 * stock = 1; outras só com conversão confirmada na XPLENDOR); no ecrã é só para mostrar o total.
 */

/** "1,5" → 1.5; "" → null; texto inválido ou negativo → NaN. */
export function parseQty(v: string | number | null | undefined): number | null {
    if (v === null || v === undefined) return null;
    const t = String(v).trim().replace(/\s/g, "").replace(",", ".");
    if (t === "") return null;
    const n = Number(t);
    return Number.isFinite(n) && n >= 0 ? n : NaN;
}

export function factorOf(unit: string, units: CountUnit[]): number | null {
    const u = units.find((x) => x.unit.toLowerCase() === unit.trim().toLowerCase());
    return u ? u.factor : null;
}

/** O total na unidade de stock, ou null se nada foi contado. NaN se alguma linha for inválida. */
export function sumEntries(entries: CountEntry[], units: CountUnit[]): number | null {
    let total = 0;
    let any = false;
    for (const e of entries) {
        const q = parseQty(e.qty);
        if (q === null) continue;
        if (Number.isNaN(q)) return NaN;
        const f = factorOf(e.unit, units);
        if (f === null) return NaN;
        total += q * f;
        any = true;
    }
    return any ? Math.round(total * 100000) / 100000 : null;
}

/** As entradas que se gravam (sem as vazias). */
export function entriesToSave(entries: CountEntry[]): { qty: string; unit: string }[] {
    return entries.filter((e) => parseQty(e.qty) !== null).map((e) => ({ qty: String(e.qty).trim().replace(",", "."), unit: e.unit }));
}
