import { useEffect, useMemo, useRef, useState } from "react";
import { Button, Input } from "reactstrap";
import { CountEntry, CountLine } from "common/models/stockcount.model";
import { entriesToSave, sumEntries } from "./countEntries";
import { fmtStockQty, unitLabel } from "./stockFormat";

type SaveState = "idle" | "saving" | "saved" | "error";

/**
 * XPLENDOR — Fatia 7b: uma linha da contagem, no telemóvel. Quantidade + unidade (a de stock ou uma
 * confirmada), "+ outra unidade" para somar, "0" explícito; o total na unidade de stock; gravação
 * automática (700 ms depois da última tecla) com "Gravado". Cega: o teórico só aparece se vier.
 */
export default function CountLineEditor({ line, disabled, onSave, onConfirmUnit, debounceMs = 700 }: {
    line: CountLine;
    disabled?: boolean;
    onSave: (entries: { qty: string; unit: string }[]) => Promise<{ qty_base: number | null } | void>;
    onConfirmUnit?: () => void;
    debounceMs?: number;
}) {
    const stockUnit = line.unit ?? "Unidade";
    const initial: CountEntry[] = line.entries?.length ? line.entries.map((e) => ({ qty: String(e.qty).replace(".", ","), unit: e.unit })) : [{ qty: "", unit: stockUnit }];
    const [entries, setEntries] = useState<CountEntry[]>(initial);
    const [state, setState] = useState<SaveState>(line.qty_base !== null ? "saved" : "idle");
    const [error, setError] = useState<string | null>(null);
    const dirty = useRef(false);
    const saver = useRef(onSave);
    saver.current = onSave;
    const total = useMemo(() => sumEntries(entries, line.units), [entries, line.units]);

    useEffect(() => {
        if (!dirty.current || disabled) return;
        if (Number.isNaN(total)) { setState("error"); setError("Escreva números maiores ou iguais a 0."); return; }
        setState("saving");
        const t = setTimeout(async () => {
            try {
                await saver.current(entriesToSave(entries));
                dirty.current = false;
                setError(null);
                setState("saved");
            } catch (e: any) {
                setState("error");
                setError(e?.response?.data?.message ?? e?.message ?? "Não foi possível gravar.");
            }
        }, debounceMs);
        return () => clearTimeout(t);
    }, [entries, total, disabled, debounceMs]);

    const change = (i: number, patch: Partial<CountEntry>) => {
        dirty.current = true;
        setEntries((p) => p.map((e, j) => (j === i ? { ...e, ...patch } : e)));
    };
    const addUnit = () => {
        const other = line.units.find((u) => !entries.some((e) => e.unit === u.unit)) ?? line.units[0];
        dirty.current = true;
        setEntries((p) => [...p, { qty: "", unit: other?.unit ?? stockUnit }]);
    };
    const zero = () => { dirty.current = true; setEntries([{ qty: "0", unit: stockUnit }]); };
    const clear = () => { dirty.current = true; setEntries([{ qty: "", unit: stockUnit }]); };

    return (
        <div className="py-2 border-bottom" data-testid="count-line" data-line={line.id}>
            <div className="d-flex align-items-start gap-2 mb-1">
                <div className="flex-grow-1" style={{ minWidth: 0 }}>
                    <div className="fw-medium text-truncate">{line.description}</div>
                    <div className="text-muted fs-12">
                        {line.code}{line.abc && <span className={`badge ms-1 bg-${line.abc === "A" ? "danger" : line.abc === "B" ? "warning" : "secondary"}-subtle text-${line.abc === "A" ? "danger" : line.abc === "B" ? "warning" : "secondary"}`}>{line.abc}</span>}
                        {line.recount_requested && <span className="badge ms-1 bg-warning text-dark">Recontar</span>}
                        {line.theoretical !== null && line.theoretical !== undefined && <span className="ms-2" data-testid="count-theoretical">Teórico: {fmtStockQty(line.theoretical)}</span>}
                    </div>
                </div>
                <div className="text-end fs-12 text-nowrap" style={{ minWidth: 72 }} role="status" aria-live="polite">
                    {state === "saving" && <span className="text-muted">A gravar…</span>}
                    {state === "saved" && <span className="text-success" data-testid="count-saved"><i className="ri-check-line" /> Gravado</span>}
                    {state === "error" && <span className="text-danger">{error}</span>}
                </div>
            </div>
            {entries.map((e, i) => (
                <div key={i} className="d-flex gap-2 mb-1 align-items-center">
                    <Input
                        inputMode="decimal"
                        aria-label={`Quantidade de ${line.description}${i ? ` (${i + 1})` : ""}`}
                        value={e.qty ?? ""}
                        onChange={(ev) => change(i, { qty: ev.target.value })}
                        disabled={disabled}
                        style={{ maxWidth: 120, minHeight: 44, fontSize: 18 }}
                        placeholder="—"
                    />
                    <Input type="select" aria-label="Unidade" value={e.unit} onChange={(ev) => change(i, { unit: ev.target.value })} disabled={disabled} style={{ maxWidth: 170, minHeight: 44 }}>
                        {line.units.map((u) => <option key={u.unit} value={u.unit}>{u.unit}{u.factor !== 1 ? ` (${fmtStockQty(u.factor)} ${unitLabel(stockUnit, u.factor)})` : ""}</option>)}
                    </Input>
                    {entries.length > 1 && (
                        <Button color="link" className="text-danger p-1" aria-label="Tirar esta unidade" disabled={disabled}
                            onClick={() => { dirty.current = true; setEntries((p) => p.filter((_, j) => j !== i)); }}>
                            <i className="ri-close-line fs-18" />
                        </Button>
                    )}
                </div>
            ))}
            <div className="d-flex flex-wrap gap-2 align-items-center mt-1">
                <Button size="sm" color="light" onClick={zero} disabled={disabled} style={{ minHeight: 36 }} data-testid="count-zero">0</Button>
                {line.units.length > 1 && <Button size="sm" color="light" onClick={addUnit} disabled={disabled} style={{ minHeight: 36 }} data-testid="count-add-unit">+ outra unidade</Button>}
                {onConfirmUnit && <Button size="sm" color="link" className="p-0" onClick={onConfirmUnit} disabled={disabled}>Outra embalagem…</Button>}
                {total !== null && !Number.isNaN(total) && entries.length > 0 && (
                    <span className="ms-auto fw-semibold" data-testid="count-total">= {fmtStockQty(total)} {unitLabel(stockUnit, total)}</span>
                )}
                {total !== null && <Button size="sm" color="link" className="p-0 text-muted" onClick={clear} disabled={disabled} title="Volta a ficar por contar">Limpar</Button>}
                {total === null && <span className="ms-auto text-muted fs-12">Por contar</span>}
            </div>
        </div>
    );
}
