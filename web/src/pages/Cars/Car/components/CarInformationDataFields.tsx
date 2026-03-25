//React
import { Col, Label, Row } from "reactstrap";
import Select from "react-select";

// Components
import XInput from "Components/Common/XInput";

// Forms
import { useFormikContext } from "formik";

// Datas
import { statusOptions, originOptions } from "common/data/cars";

// Models
import { ICarUpdatePayload } from "common/models/car.model";

export default function CarInformationDataFields({
    isEdit,
    onStatusChange,
}: {
    isEdit: boolean;
    onStatusChange?: (nextStatus: string | null, previousStatus: string | null) => void;
}) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();

    return (
        <div>
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Informação da Viatura</h5>
            </div>

            <Row>
                <Col lg={2}>
                    <Label className="form-label">
                        Status:
                    </Label>
                    <Select
                        name="status"
                        options={statusOptions}
                        value={statusOptions.find((opt) => opt.value === values.status) || null}
                        onChange={(opt: any) => {
                            const previousStatus = values.status ?? null;
                            const nextStatus = opt?.value ?? null;

                            setFieldValue("status", nextStatus);
                            onStatusChange?.(nextStatus, previousStatus);
                        }}
                        onBlur={() => setFieldTouched("status", true)}
                        classNamePrefix="react-select"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label className="form-label">
                        Origem:
                    </Label>
                    <Select
                        name="origin"
                        options={originOptions}
                        value={originOptions.find((opt) => opt.value === values.origin) || null}
                        onChange={(opt: any) => setFieldValue("origin", opt?.value ?? null)}
                        onBlur={() => setFieldTouched("origin", true)}
                        classNamePrefix="react-select"
                        className="mb-3"
                    />
                </Col>
                <Col lg={3}>
                    <XInput
                        name="license_plate"
                        label="Matrícula"
                        placeholder="Matrícula"
                        className="mb-3"
                    />
                </Col>
                <Col lg={5}>
                    <XInput
                        name="vin"
                        label="Número de Identificação do Veículo (VIN)"
                        placeholder="Número de Identificação do Veículo (VIN)"
                        className="mb-3"
                    />
                </Col>
            </Row>
        </div>
    )
}
