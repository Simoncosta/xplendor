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
import { colorsOptions, conditionsOptions, seatsOptions, segmentOptions } from "common/data/cars";
import XInputCheckbox from "Components/Common/XInputCheckbox";

export default function CarVehicleDetailsDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();

    return (
        <div className="mt-4">
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Detalhes da Viatura</h5>
            </div>

            <Row>
                <Col lg={2}>
                    <Label for="segment">
                        Segmento: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="segment"
                        name="segment"
                        options={segmentOptions}
                        value={segmentOptions.find((option: any) => option.value === values.segment) || null}
                        onChange={(option: any) => {
                            setFieldValue("segment", option?.value || null);
                            setFieldTouched("segment", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={2}>
                    <Label for="seats">
                        Lugares: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="seats"
                        name="seats"
                        options={seatsOptions}
                        value={seatsOptions.find((option: any) => option.value === values.seats) || null}
                        onChange={(option: any) => {
                            setFieldValue("seats", option?.value || null);
                            setFieldTouched("seats", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={2}>
                    <Label for="exterior_color">
                        Cor: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="exterior_color"
                        name="exterior_color"
                        options={colorsOptions}
                        value={colorsOptions.find((option: any) => option.value === values.exterior_color) || null}
                        onChange={(option: any) => {
                            setFieldValue("exterior_color", option?.value || null);
                            setFieldTouched("exterior_color", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={2}>
                    <XInputCheckbox
                        name="is_metallic"
                        label="Cor Metálica"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label for="condition">
                        Estado: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="condition"
                        name="condition"
                        options={conditionsOptions}
                        value={conditionsOptions.find((option: any) => option.value === values.condition) || null}
                        onChange={(option: any) => {
                            setFieldValue("condition", option?.value || null);
                            setFieldTouched("condition", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        type="number"
                        name="mileage_km"
                        label="Quilometragem (km)"
                        className="mb-3"
                        required
                    />
                </Col>
            </Row>
        </div>
    )
}