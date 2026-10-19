import { Button, Input, InputGroup, InputGroupText, Label } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import { SettingItem, SettingValue } from "common/models/settings.model";

/**
 * XPLENDOR — Configurações: um campo, com o rótulo, o InfoTip em linguagem simples e o controlo do
 * tipo (percentagem, euros, número, ligado ou desligado, loja, dia e mês, alvos por família).
 */
const SOURCE: Record<SettingItem["source"], string | null> = { base_de_dados: null, env: "Valor do ficheiro de configuração antigo", defeito: "Valor por defeito" };

export default function SettingField({ item, value, onChange, stores = [], disabled = false, disabledReason }: {
    item: SettingItem; value: SettingValue; onChange: (v: SettingValue) => void;
    stores?: { id: string; name: string }[]; disabled?: boolean; disabledReason?: string;
}) {
    const id = `setting-${item.key.replace(/\W/g, "-")}`;
    const label = (
        <Label for={id} className="form-label mb-1 d-flex align-items-center gap-1">
            {item.label}<InfoTip text={item.info} label={`Sobre: ${item.label}`} />
        </Label>
    );
    const hint = SOURCE[item.source] ? <div className="text-muted fs-12 mt-1">{SOURCE[item.source]}</div> : null;

    if (item.type === "bool") {
        return (
            <div className="mb-3" data-testid={id}>
                <div className="form-check form-switch">
                    <Input id={id} type="switch" role="switch" className="form-check-input" checked={!!value} disabled={disabled}
                        onChange={(e) => onChange(e.target.checked)} />
                    <Label for={id} className="form-check-label d-inline-flex align-items-center gap-1">{item.label}<InfoTip text={item.info} label={`Sobre: ${item.label}`} /></Label>
                </div>
                {disabled && disabledReason && <div className="text-muted fs-12 mt-1">{disabledReason}</div>}
                {hint}
            </div>
        );
    }
    if (item.type === "family_pct") {
        const rows = Object.entries((value as Record<string, number>) ?? {});
        const set = (next: [string, number][]) => onChange(Object.fromEntries(next.filter(([k]) => k.trim() !== "")));
        return (
            <div className="mb-3" data-testid={id}>
                {label}
                {rows.length === 0 && <div className="text-muted fs-13 mb-1">Nenhuma família com alvo próprio.</div>}
                {rows.map(([family, pct], i) => (
                    <div key={i} className="d-flex gap-2 mb-1">
                        <Input bsSize="sm" aria-label="Família" value={family} disabled={disabled}
                            onChange={(e) => set(rows.map((r, j) => (j === i ? [e.target.value, r[1]] : r)) as [string, number][])} />
                        <InputGroup size="sm" style={{ maxWidth: 130 }}>
                            <Input type="number" aria-label="Percentagem" min={1} max={100} value={pct} disabled={disabled}
                                onChange={(e) => set(rows.map((r, j) => (j === i ? [r[0], Number(e.target.value)] : r)) as [string, number][])} />
                            <InputGroupText>%</InputGroupText>
                        </InputGroup>
                        <Button size="sm" color="light" aria-label="Tirar" disabled={disabled} onClick={() => set(rows.filter((_, j) => j !== i) as [string, number][])}><i className="ri-close-line" aria-hidden /></Button>
                    </div>
                ))}
                <Button size="sm" color="link" className="px-0" disabled={disabled} onClick={() => onChange({ ...(value as Record<string, number>), "": 30 })}>
                    <i className="ri-add-line me-1" aria-hidden />Acrescentar família
                </Button>
            </div>
        );
    }
    if (item.type === "store") {
        return (
            <div className="mb-3" data-testid={id}>
                {label}
                <Input id={id} type="select" bsSize="sm" value={String(value ?? "")} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
                    <option value="">A loja que todos os armazéns servem (o Economato)</option>
                    {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </Input>
                {hint}
            </div>
        );
    }
    if (item.type === "email") {
        return (
            <div className="mb-3" data-testid={id}>
                {label}
                <Input id={id} type="email" bsSize="sm" placeholder="contabilidade@exemplo.pt" style={{ maxWidth: 320 }} value={String(value ?? "")} disabled={disabled}
                    onChange={(e) => onChange(e.target.value)} />
                {hint}
            </div>
        );
    }
    if (item.type === "month_day") {
        return (
            <div className="mb-3" data-testid={id}>
                {label}
                <Input id={id} bsSize="sm" placeholder="dd/mm, por exemplo 24/06" style={{ maxWidth: 220 }} value={String(value ?? "")} disabled={disabled}
                    onChange={(e) => onChange(e.target.value)} />
                {hint}
            </div>
        );
    }
    const suffix = item.type === "pct" ? "%" : item.type === "eur" ? "€" : null;
    return (
        <div className="mb-3" data-testid={id}>
            {label}
            <InputGroup size="sm" style={{ maxWidth: 220 }}>
                <Input id={id} type="number" step="any" min={item.min ?? undefined} max={item.max ?? undefined} value={value as number} disabled={disabled}
                    onChange={(e) => onChange(e.target.value === "" ? "" : Number(e.target.value))} />
                {suffix && <InputGroupText>{suffix}</InputGroupText>}
            </InputGroup>
            {hint}
        </div>
    );
}
