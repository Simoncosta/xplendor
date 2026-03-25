// React
import { Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import { useEffect, useMemo, useState } from "react";
// Models
import { ICarFormValues } from "./CarImagesDataFields";
import XInput from "Components/Common/XInput";

export default function CarPriceDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarFormValues>();

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
            </Row>
        </div>
    );
}
