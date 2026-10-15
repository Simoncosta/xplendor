import { useEffect, useMemo, useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import { confirmUnitConversion } from "helpers/laravel_helper";
import { RecipeLine } from "common/models/recipe.model";
import { DiffBadge, Money, fmtQty } from "./recipeFormat";

/** "20" ou "0,75" → número (null se não for um número positivo). */
export const parseNum = (v: string): number | null => {
    const n = Number(String(v).trim().replace(/\s/g, "").replace(",", "."));
    return Number.isFinite(n) && n > 0 ? n : null;
};

/**
 * Fatia 1b — confirmar quantas unidades base vale uma unidade de compra de um componente (ex.:
 * AVINHEZ BRANCO, 1 «Unidade» = 20 L). Mostra a sugestão, o porquê e o efeito no custo. Escreve só
 * na XPLENDOR.
 */
/** Os campos da linha que o modal usa (a Auditoria abre-o para um artigo que pode não estar numa ficha). */
export type FactorLine = Pick<RecipeLine, "component_id" | "description" | "qnt_base" | "waste_pct" | "pingwin_cost_eur" | "conversion">;

export default function ConfirmFactorModal({ open, onClose, onDone, companyId, line, hidden }: {
    open: boolean; onClose: () => void; onDone: () => void; companyId: number | null; line: FactorLine; hidden: boolean;
}) {
    const conv = line.conversion!;
    const [choice, setChoice] = useState("current");
    const [custom, setCustom] = useState("");
    const [nif, setNif] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setChoice(conv.suggestions.length ? "s0" : "current");
        setCustom("");
        setNif("");
    }, [open, conv]);

    const options = useMemo(() => [
        ...conv.suggestions.map((s, i) => ({ id: `s${i}`, factor: s.factor, reason: s.reason, effect: s as typeof s | null })),
        ...(conv.current_factor !== null ? [{ id: "current", factor: conv.current_factor, reason: "O que a compra diz hoje (qnt_base da linha).", effect: conv.current }] : []),
    ], [conv]);

    const customFactor = parseNum(custom);
    const factor = choice === "custom" ? customFactor : options.find((o) => o.id === choice)?.factor ?? null;
    // Efeito de um fator escrito à mão: o preço por unidade de compra ÷ fator (aproximado).
    const customEffect = useMemo(() => {
        if (choice !== "custom" || customFactor === null || conv.price_per_unit_eur === null) return null;
        const unit = conv.price_per_unit_eur / customFactor;
        const lineCost = line.qnt_base * unit * (1 + line.waste_pct / 100);
        const pw = line.pingwin_cost_eur;
        return { unit_cost_eur: unit, line_cost_eur: lineCost, diff_pct: pw ? Math.round(((lineCost - pw) / pw) * 1000) / 10 : null };
    }, [choice, customFactor, conv.price_per_unit_eur, line]);

    const save = async () => {
        if (!companyId || factor === null) return;
        setSaving(true);
        try {
            const chosen = options.find((o) => o.id === choice);
            await confirmUnitConversion(companyId, line.component_id, {
                unit_key: conv.unit_key, unit_label: conv.unit_label, factor,
                supplier_nif: nif || null,
                reason: choice === "custom" ? "Indicado à mão" : chosen?.reason ?? null,
            });
            toast.success("Fator confirmado. O custo foi recalculado.");
            onDone();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível confirmar o fator.");
        } finally {
            setSaving(false);
        }
    };

    const effectLine = (e: { unit_cost_eur: number | null; line_cost_eur: number | null; diff_pct: number | null } | null | undefined, seed: number) =>
        e ? (
            <div className="text-muted fs-12">
                Custo <Money value={e.unit_cost_eur} hidden={hidden} decimals={4} seed={seed} /> por {conv.base_label.toLowerCase()}, linha{" "}
                <Money value={e.line_cost_eur} hidden={hidden} decimals={4} seed={seed + 1} />, face ao PingWin <DiffBadge pct={e.diff_pct} />
            </div>
        ) : null;

    const supplierOptions = [{ value: "", label: "Todos os fornecedores" }, ...conv.suppliers.map((s) => ({ value: s.nif, label: `${s.name ?? "Fornecedor"} (${s.nif})` }))];

    return (
        <Modal isOpen={open} toggle={() => !saving && onClose()} centered>
            <ModalHeader toggle={() => !saving && onClose()}>Confirmar fator: {line.description}</ModalHeader>
            <ModalBody>
                <p className="mb-3">
                    Na compra, quantos <strong>{conv.base_label}</strong> vale 1 <strong>«{conv.unit_label}»</strong>?
                    {conv.price_per_unit_eur !== null && <span className="text-muted fs-13"> Preço médio: <Money value={conv.price_per_unit_eur} hidden={hidden} decimals={2} seed={1} /> por «{conv.unit_label}».</span>}
                </p>
                <div role="radiogroup" aria-label="Fator" className="d-flex flex-column gap-2">
                    {options.map((o, i) => (
                        <label key={o.id} className={`border rounded p-2 d-flex gap-2 align-items-start ${choice === o.id ? "border-primary" : ""}`} style={{ cursor: "pointer" }}>
                            <Input type="radio" name="factor" checked={choice === o.id} onChange={() => setChoice(o.id)} className="mt-1" />
                            <div>
                                <div className="fw-semibold">1 «{conv.unit_label}» = {fmtQty(o.factor, 4)} {conv.base_label}{o.id === "current" ? " (como está)" : i === 0 ? " (sugerido)" : ""}</div>
                                <div className="fs-12">{o.reason}</div>
                                {effectLine(o.effect, 10 + i * 2)}
                            </div>
                        </label>
                    ))}
                    <label className={`border rounded p-2 d-flex gap-2 align-items-start ${choice === "custom" ? "border-primary" : ""}`} style={{ cursor: "pointer" }}>
                        <Input type="radio" name="factor" checked={choice === "custom"} onChange={() => setChoice("custom")} className="mt-1" />
                        <div className="flex-fill">
                            <div className="fw-semibold mb-1">Outro valor</div>
                            <Input value={custom} inputMode="decimal" placeholder={`${conv.base_label} por «${conv.unit_label}»`} aria-label="Outro fator"
                                onFocus={() => setChoice("custom")} onChange={(e) => { setCustom(e.target.value); setChoice("custom"); }} />
                            {effectLine(customEffect, 40)}
                        </div>
                    </label>
                </div>
                {conv.suppliers.length > 0 && (
                    <div className="mt-3">
                        <Label className="form-label">Aplica-se a</Label>
                        <XSelect ariaLabel="Fornecedor" options={supplierOptions} value={nif} onChange={setNif} searchable={false} />
                    </div>
                )}
                <p className="text-muted fs-12 mt-3 mb-0"><i className="ri-information-line me-1" />Fica só na XPLENDOR: o PingWin não muda.</p>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>Cancelar</Button>
                <ReasonButton color="primary" onClick={save} reason={factor === null ? "Indique um fator maior do que zero." : null} disabled={saving}>
                    {saving ? <><Spinner size="sm" className="me-1" />A confirmar…</> : "Confirmar fator"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}
