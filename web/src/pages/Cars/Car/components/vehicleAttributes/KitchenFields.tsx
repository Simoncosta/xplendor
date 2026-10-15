import Select from "react-select";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import { Col, Label, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XSelect from "Components/Common/Select";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload } from "common/models/car.model";

const fridgeTypeOptions = [
    { value: "trivalent", label: "Trivalente" },
    { value: "compressor", label: "Compressor" },
    { value: "absorption", label: "Absorção" },
    { value: "none", label: "Nenhum" },
];

// Ficha das autocaravanas (B). "Sem indicação" limpa o valor.
const stoveTypeOptions: { value: string; label: string }[] = [
    { value: "", label: "Sem indicação" },
    { value: "gas", label: "Gás" },
    { value: "electric", label: "Elétrico" },
    { value: "mixed", label: "Misto (gás e elétrico)" },
    { value: "induction", label: "Indução" },
];

/**
 * Campos da cozinha (`habitation_basics.kitchen`). Usados no acordeão "Cozinha" das
 * caravanas e na secção "Sala e cozinha" das autocaravanas.
 */
export default function KitchenFields() {
    const { values, setFieldValue } = useFormikContext<ICarUpdatePayload>();
    const kitchen = values.vehicle_attributes?.habitation_basics?.kitchen;

    return (
        <>
            <Row>
                <Col lg={12}>
                    <XInputCheckbox
                        name="vehicle_attributes.habitation_basics.has_kitchen"
                        label="Tem cozinha"
                        className="mb-3"
                    />
                </Col>
            </Row>
            {values.vehicle_attributes?.habitation_basics?.has_kitchen && (
                <Row>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.kitchen.has_stove"
                            label="Fogão"
                            className="mb-3"
                        />
                    </Col>
                    {kitchen?.has_stove && (
                        <>
                            <Col lg={2}>
                                <XInput
                                    type="number"
                                    name="vehicle_attributes.habitation_basics.kitchen.stove_burners"
                                    label="Bocas do fogão"
                                    className="mb-3"
                                />
                            </Col>
                            <Col lg={2} className="mb-3">
                                <Label for="stove_type">Tipo de fogão</Label>
                                <XSelect
                                    id="stove_type"
                                    options={stoveTypeOptions}
                                    value={kitchen?.stove_type ?? null}
                                    onChange={(v) => setFieldValue("vehicle_attributes.habitation_basics.kitchen.stove_type", v || null)}
                                />
                            </Col>
                        </>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.kitchen.has_oven"
                            label="Forno"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.kitchen.has_extending_counter"
                            label="Acrescento de banca"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.kitchen.has_microwave"
                            label="Micro-ondas"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.kitchen.has_extractor"
                            label="Exaustor"
                            className="mb-3"
                        />
                    </Col>
                    {kitchen?.has_extractor && (
                        <Col lg={2}>
                            <XInput
                                name="vehicle_attributes.habitation_basics.kitchen.extractor_brand"
                                label="Marca do exaustor"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.kitchen.has_fridge"
                            label="Frigorífico"
                            className="mb-3"
                        />
                    </Col>
                    {kitchen?.has_fridge && (
                        <>
                            <Col lg={2}>
                                <XInput
                                    name="vehicle_attributes.habitation_basics.kitchen.fridge_brand"
                                    label="Marca do frigorífico"
                                    className="mb-3"
                                />
                            </Col>
                            <Col lg={2}>
                                <Label>Tipo de frigorífico</Label>
                                <Select
                                    styles={reactSelectTheme}
                                    menuPortalTarget={document.body}
                                    isClearable
                                    placeholder="Selecionar"
                                    options={fridgeTypeOptions}
                                    value={fridgeTypeOptions.find(o => o.value === kitchen?.fridge_type) ?? null}
                                    onChange={(opt: any) => setFieldValue("vehicle_attributes.habitation_basics.kitchen.fridge_type", opt?.value ?? null)}
                                    className="mb-3"
                                />
                            </Col>
                            <Col lg={2}>
                                <XInput
                                    type="number"
                                    name="vehicle_attributes.habitation_basics.kitchen.fridge_litres"
                                    label="Capacidade (L)"
                                    className="mb-3"
                                />
                            </Col>
                            <Col lg={2}>
                                <XInput
                                    type="number"
                                    name="vehicle_attributes.habitation_basics.kitchen.fridge_shelves"
                                    label="Prateleiras"
                                    className="mb-3"
                                />
                            </Col>
                        </>
                    )}
                </Row>
            )}
        </>
    );
}
