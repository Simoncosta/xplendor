import { useField } from "formik";
import { FormFeedback, Input } from "reactstrap";
import FieldLabelWithHint from "Components/Common/FieldLabelWithHint";

/**
 * Ficha da viatura (QR): "Endereço da viatura no site", um modelo com {id} (ex.:
 * https://quebom.pt/vehicles/{id}). A mesma validação do servidor (https e {id} obrigatórios) e a
 * pré-visualização do endereço de uma viatura, com e sem os parâmetros que o QR acrescenta.
 */
export const QR_PARAMS = "origem=qr&utm_source=qr&utm_medium=offline&utm_campaign=ficha_stand";
const EXAMPLE_ID = "123";
export const VEHICLE_URL_HINT = "Usado pelo QR Code da ficha da viatura (A4). Escreva {id} no sítio do número da viatura. Sem endereço, a ficha sai sem QR.";

export function vehicleUrlTemplateError(value: string): string | null {
    const t = value.trim();
    if (!t) return null;
    if (!t.toLowerCase().startsWith("https://")) return "O endereço da viatura tem de começar por https://.";
    if (!t.includes("{id}")) return "O endereço da viatura tem de ter {id} no sítio do número da viatura (ex.: https://quebom.pt/vehicles/{id}).";
    if (t.split("{id}").length > 2) return "O endereço da viatura não é válido (ex.: https://quebom.pt/vehicles/{id}).";
    try { new URL(t.replace("{id}", EXAMPLE_ID)); } catch { return "O endereço da viatura não é válido (ex.: https://quebom.pt/vehicles/{id})."; }
    return null;
}

export default function VehicleUrlTemplateField() {
    const [field, meta] = useField<string | null>("vehicle_url_template");
    const value = field.value ?? "";
    const localError = vehicleUrlTemplateError(value);
    const error = localError ?? (meta.touched && meta.error ? meta.error : null);
    const example = value.trim() && !localError ? value.trim().replace("{id}", EXAMPLE_ID) : null;

    return (
        <div className="mb-2" data-testid="vehicle-url-template">
            <FieldLabelWithHint htmlFor="vehicle_url_template" label="Endereço da viatura no site" hint={VEHICLE_URL_HINT} />
            <Input id="vehicle_url_template" name="vehicle_url_template" type="url" placeholder="https://quebom.pt/vehicles/{id}"
                value={value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!error} />
            {error && <FormFeedback>{error}</FormFeedback>}
            {example && (
                <div className="small mt-1" data-testid="vehicle-url-preview">
                    <div>Exemplo, viatura {EXAMPLE_ID}: <span className="fw-medium text-break">{example}</span></div>
                    <div className="text-muted text-break">Quem lê o QR chega a: {example}{example.includes("?") ? "&" : "?"}{QR_PARAMS}</div>
                </div>
            )}
        </div>
    );
}
