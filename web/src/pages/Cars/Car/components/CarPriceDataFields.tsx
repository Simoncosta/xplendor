// React
import { Col, Row, Input, Label } from "reactstrap";
import { useFormikContext } from "formik";
import { useMemo } from "react";
// Models
import { ICarFormValues } from "./CarImagesDataFields";
import { CarVatRegime } from "common/models/car.model";
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";

// DMS Fase 1a — enum extensível do regime de IVA da compra. Acrescentar aqui
// (e no Rule::in do CarRequest.php) quando a taxonomia final for validada com
// contabilista na Fase 2. Só se CAPTURA o regime; o cálculo fica para a Fase 2.
const VAT_REGIME_OPTIONS: { value: CarVatRegime; label: string }[] = [
    { value: "margem", label: "Regime de margem" },
    { value: "normal", label: "Regime normal (IVA dedutível)" },
    { value: "isento", label: "Isento" },
];

/**
 * Lê o interruptor "modo IVA" da empresa autenticada a partir do
 * `sessionStorage.authUser.company` (o login carrega a relação `company`).
 * O regime de IVA por viatura só aparece quando a empresa trabalha com IVA.
 */
function useCompanyUsesVat(): boolean {
    return useMemo(() => {
        try {
            const raw = sessionStorage.getItem("authUser");
            if (!raw) return false;
            const parsed = JSON.parse(raw);
            return Boolean(parsed?.company?.uses_vat);
        } catch {
            return false;
        }
    }, []);
}

export default function CarPriceDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarFormValues>();
    const usesVat = useCompanyUsesVat();

    return (
        <div className="mt-4">
            <div className="mb-2 border-bottom pb-2">
                <h5 className="card-title">Preço e Condições</h5>
            </div>

            <Row>
                <Col lg={2}>
                    <XInput
                        type="number"
                        label="Preço (€) c/ IVA"
                        name="price_gross"
                        step="0.01"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        type="number"
                        label="Preço promo (€)"
                        name="promo_price_gross"
                        step="0.01"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        type="number"
                        label="Preço (€) s/ IVA"
                        name="price_net"
                        step="0.01"
                    />
                </Col>
                <Col lg={2} className="d-flex align-items-end pb-3">
                    <XInputCheckbox
                        name="hide_price_online"
                        label="Preço sob consulta"
                    />
                </Col>
            </Row>

            {/* DMS Fase 1a — Dados internos da compra. Nunca exposto ao público
                (CarPublicResource é allow-list). Preço de compra é para todos;
                regime de IVA só quando a empresa tem o modo IVA ligado. */}
            <div className="mt-4 mb-2 border-bottom pb-2">
                <h5 className="card-title mb-0">Dados da compra</h5>
                <small className="text-muted">Uso interno — nunca visível no site público.</small>
            </div>

            <Row>
                <Col lg={2}>
                    <XInput
                        type="number"
                        label="Preço de compra (€)"
                        name="purchase_price"
                        step="0.01"
                        hint="Custo de aquisição da viatura. Confidencial — base para o cálculo de margem/lucro."
                    />
                </Col>

                {usesVat && (
                    <Col lg={3}>
                        <Label className="form-label" htmlFor="vat_regime">Regime de IVA</Label>
                        <Input
                            type="select"
                            id="vat_regime"
                            name="vat_regime"
                            value={values.vat_regime ?? ""}
                            onChange={(e) => setFieldValue("vat_regime", e.target.value || null)}
                            onBlur={() => setFieldTouched("vat_regime", true)}
                        >
                            <option value="">— Sem regime definido —</option>
                            {VAT_REGIME_OPTIONS.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </Input>
                    </Col>
                )}
            </Row>
        </div>
    );
}
