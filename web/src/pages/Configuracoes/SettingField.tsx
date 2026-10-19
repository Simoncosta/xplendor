import { Button, Col, Input, InputGroup, InputGroupText, Label, Row } from "reactstrap";
import { SettingItem, SettingValue } from "common/models/settings.model";

/**
 * XPLENDOR — Configurações: uma definição numa linha da grelha. À esquerda, o nome e a ajuda em
 * linguagem simples (o que acontece com ela); à direita, o campo, com a mesma largura para o mesmo
 * tipo (números, email e loja, dia e mês); os interruptores ficam alinhados à direita.
 */
const SOURCE: Record<SettingItem["source"], string | null> = { base_de_dados: null, env: "Valor do ficheiro de configuração antigo", defeito: "Valor por defeito" };
/** As larguras do campo por tipo: o mesmo tipo, a mesma largura, em todo o ecrã. */
const WIDTH: Partial<Record<SettingItem["type"], number>> = { pct: 180, eur: 180, number: 180, month_day: 180, email: 320, store: 320 };

export default function SettingField({ item, value, onChange, stores = [], disabled = false, disabledReason }: {
    item: SettingItem; value: SettingValue; onChange: (v: SettingValue) => void;
    stores?: { id: string; name: string }[]; disabled?: boolean; disabledReason?: string;
}) {
    const id = `setting-${item.key.replace(/\W/g, "-")}`;
    const source = SOURCE[item.source];
    const width = WIDTH[item.type];

    const control = (() => {
        if (item.type === "bool") {
            return (
                <div className="form-check form-switch m-0" style={{ minHeight: 0 }}>
                    <Input id={id} type="switch" role="switch" className="form-check-input" checked={!!value} disabled={disabled}
                        onChange={(e) => onChange(e.target.checked)} aria-describedby={`${id}-help`} />
                    <Label for={id} className="visually-hidden">{item.label}</Label>
                </div>
            );
        }
        if (item.type === "family_pct") {
            const rows = Object.entries((value as Record<string, number>) ?? {});
            const set = (next: [string, number][]) => onChange(Object.fromEntries(next.filter(([k]) => k.trim() !== "")));
            return (
                <div className="w-100" style={{ maxWidth: 320 }}>
                    {rows.length === 0 && <div className="text-muted fs-13 mb-1">Nenhuma família com alvo próprio.</div>}
                    {rows.map(([family, pct], i) => (
                        <div key={i} className="d-flex gap-2 mb-1">
                            <Input bsSize="sm" aria-label="Família" value={family} disabled={disabled}
                                onChange={(e) => set(rows.map((r, j) => (j === i ? [e.target.value, r[1]] : r)) as [string, number][])} />
                            <InputGroup size="sm" style={{ width: 110, flex: "0 0 110px" }}>
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
                <Input id={id} type="select" bsSize="sm" style={{ width }} value={String(value ?? "")} disabled={disabled} onChange={(e) => onChange(e.target.value)} aria-describedby={`${id}-help`}>
                    <option value="">A loja que todos os armazéns servem (o Economato)</option>
                    {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </Input>
            );
        }
        if (item.type === "month_day" || item.type === "email") {
            return (
                <Input id={id} type={item.type === "email" ? "email" : "text"} bsSize="sm" style={{ width }} value={String(value ?? "")} disabled={disabled}
                    placeholder={item.type === "email" ? "contabilidade@exemplo.pt" : "dd/mm, por exemplo 24/06"} onChange={(e) => onChange(e.target.value)} aria-describedby={`${id}-help`} />
            );
        }
        const suffix = item.type === "pct" ? "%" : item.type === "eur" ? "€" : null;
        return (
            <InputGroup size="sm" style={{ width }}>
                <Input id={id} type="number" step="any" className="text-end" min={item.min ?? undefined} max={item.max ?? undefined} value={value as number} disabled={disabled}
                    onChange={(e) => onChange(e.target.value === "" ? "" : Number(e.target.value))} aria-describedby={`${id}-help`} />
                {suffix && <InputGroupText style={{ width: 36, justifyContent: "center" }}>{suffix}</InputGroupText>}
            </InputGroup>
        );
    })();

    return (
        <Row className="g-2 py-3 border-bottom align-items-start xp-setting-row" data-testid={id}>
            <Col md={7} lg={8}>
                <Label for={item.type === "family_pct" ? undefined : id} className="form-label fw-medium mb-1">{item.label}</Label>
                <div id={`${id}-help`} className="text-muted fs-13">{item.info}</div>
                {disabled && disabledReason && <div className="text-muted fs-12 mt-1">{disabledReason}</div>}
                {source && <div className="text-muted fs-12 mt-1 fst-italic">{source}</div>}
            </Col>
            <Col md={5} lg={4} className="d-flex justify-content-md-end">{control}</Col>
        </Row>
    );
}
