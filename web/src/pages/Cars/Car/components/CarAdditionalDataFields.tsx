//React
import Select from "react-select";
import { Col, Label, Row } from "reactstrap";

// Components
import XInput from "Components/Common/XInput";

// Forms
import { useFormikContext } from "formik";

// Models
import { ICarUpdatePayload } from "common/models/car.model";

// Redux
import { classTaxOptions, colorsOptions, conditionsOptions, seatsOptions, segmentOptions } from "common/data/cars";
import XInputCheckbox from "Components/Common/XInputCheckbox";

export default function CarAdditionalDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();

    return (
        <div className="mt-4">
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Dados Adicionais</h5>
            </div>

            <Row>
                <Col lg={2}>
                    <XInput
                        name="co2_emissions"
                        label="Emissões CO2 (g/km)"
                    />
                </Col>
                <Col lg={2}>
                    <Label for="toll_class">
                        Classe portagem:
                    </Label>
                    <Select
                        id="toll_class"
                        name="toll_class"
                        options={classTaxOptions}
                        value={classTaxOptions.find((option: any) => option.value === values.toll_class) || null}
                        onChange={(option: any) => {
                            setFieldValue("toll_class", option?.value || null);
                            setFieldTouched("toll_class", true);
                        }}
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        name="cylinders"
                        label="Cilindradas"
                    />
                </Col>
                <Col lg={1}>
                    <XInputCheckbox
                        name="has_spare_key"
                        label="Tem Chave Reserva"
                    />
                </Col>
                <Col lg={1}>
                    <XInputCheckbox
                        name="has_manuals"
                        label="Tem Manual"
                    />
                </Col>
            </Row>
        </div>
    )
}