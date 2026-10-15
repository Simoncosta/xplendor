import { useEffect, useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import ReasonButton from "Components/Common/ReasonButton";
import { confirmRecipeYield } from "helpers/laravel_helper";
import { RecipeVersion } from "common/models/recipe.model";
import { Money, fmtDate, fmtQty } from "./recipeFormat";
import { parseNum } from "./ConfirmFactorModal";

/**
 * Fatia 1b — confirmar quanto rende o lote de uma versão da ficha de um semi-produto (ex.: o Molho
 * rende 30 L). Custo por unidade base = custo do lote ÷ rendimento. Escreve só na XPLENDOR.
 */
export default function ConfirmYieldModal({ open, onClose, onDone, companyId, productId, version, hidden }: {
    open: boolean; onClose: () => void; onDone: () => void; companyId: number | null; productId: string; version: RecipeVersion; hidden: boolean;
}) {
    const y = version.yield!;
    const [qty, setQty] = useState("");
    const [reason, setReason] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setQty(y.quantity !== null ? String(y.quantity).replace(".", ",") : "");
        setReason(y.confirmed ? y.reason : y.suggestions[0]?.reason ?? null);
    }, [open, y]);

    const n = parseNum(qty);
    const perUnit = n && version.cost_eur !== null ? version.cost_eur / n : null;

    const save = async () => {
        if (!companyId || n === null) return;
        setSaving(true);
        try {
            const sugg = y.suggestions.find((s) => Math.abs(s.quantity - n) < 1e-6);
            await confirmRecipeYield(companyId, productId, { bom_id: version.bom_id, quantity: n, reason: sugg?.reason ?? reason ?? "Indicado à mão" });
            toast.success("Rendimento confirmado. Os custos foram recalculados.");
            onDone();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível confirmar o rendimento.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={open} toggle={() => !saving && onClose()} centered>
            <ModalHeader toggle={() => !saving && onClose()}>Rendimento da ficha de {fmtDate(version.valid_from)}</ModalHeader>
            <ModalBody>
                <p className="mb-2">Quantos <strong>{y.unit_label}</strong> rende esta ficha (o lote inteiro)?</p>
                {y.sums.length > 0 && (
                    <p className="text-muted fs-13 mb-3">Os componentes somam {y.sums.map((s) => `${fmtQty(s.quantity, 2)} ${s.label}`).join(" e ")}.</p>
                )}
                {y.suggestions.length > 0 && (
                    <div className="d-flex flex-column gap-1 mb-3">
                        {y.suggestions.map((s) => (
                            <button key={s.quantity} type="button" className="btn btn-sm btn-outline-primary text-start" onClick={() => { setQty(String(s.quantity).replace(".", ",")); setReason(s.reason); }}>
                                Usar {fmtQty(s.quantity, 3)} {y.unit_label}: <span className="fw-normal">{s.reason}</span>
                            </button>
                        ))}
                    </div>
                )}
                <Label className="form-label" for="yield-qty">Rende ({y.unit_label})</Label>
                <Input id="yield-qty" value={qty} inputMode="decimal" onChange={(e) => { setQty(e.target.value); setReason(null); }} />
                <div className="text-muted fs-13 mt-2">
                    Custo do lote <Money value={version.cost_eur} hidden={hidden} decimals={2} seed={1} />
                    {n ? <> · custo por {y.unit_label.toLowerCase()} <Money value={perUnit} hidden={hidden} decimals={4} seed={2} /></> : null}
                    {!version.cost_complete && <span className="badge bg-warning-subtle text-warning ms-1">parcial</span>}
                </div>
                <p className="text-muted fs-12 mt-3 mb-0"><i className="ri-information-line me-1" />Fica só na XPLENDOR: o PingWin não tem rendimento na ficha.</p>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>Cancelar</Button>
                <ReasonButton color="primary" onClick={save} reason={n === null ? "Indique uma quantidade maior do que zero." : null} disabled={saving}>
                    {saving ? <><Spinner size="sm" className="me-1" />A confirmar…</> : "Confirmar"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}
