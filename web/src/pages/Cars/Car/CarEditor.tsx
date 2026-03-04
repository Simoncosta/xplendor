// React
import { useEffect, useState } from "react";
import {
    Col,
    Container,
    Row,
    Form,
    CardBody,
    Card,
} from "reactstrap";
// Models
import { ICarUpdatePayload } from "common/models/car.model";
// Components
import XButton from "Components/Common/XButton";
import CarInformationDataFields from "./components/CarInformationDataFields";
import CarVehicleDataFields from "./components/CarVehicleDataFields";
import CarVehicleDetailsDataFields from "./components/CarVehicleDetailsDataFields";
import CarAdditionalDataFields from "./components/CarAdditionalDataFields";
import CarImagesDataFields from "./components/CarImagesDataFields";
import CarPriceDataFields from "./components/CarPriceDataFields";
import CarEquipmentDataFields from "./components/CarEquipmentDataFields";
import CarDescriptionDataFields from "./components/CarDescriptionDataFields";

//formik
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";

type CarEditorProps = {
    data: ICarUpdatePayload;
    onSubmit: (data: ICarUpdatePayload) => void;
    onCancel: () => void;
    loading?: boolean;
};

const CarEditor = ({ data, onSubmit, onCancel }: CarEditorProps) => {
    const isEdit = Boolean((data as any)?.id);

    const emptyExtrasByGroup = {
        comfort_multimedia: [],
        exterior_equipment: [],
        interior_equipment: [],
        safety_performance: [],
    };

    const arrayToMap = (arr?: { group: string; items: string[] }[]) => {
        const map = { ...emptyExtrasByGroup } as any;
        (arr ?? []).forEach((g) => {
            map[g.group] = g.items ?? [];
        });
        return map;
    };

    const validationSchema = Yup.object({
        // car_brand_id: Yup.number().min(1, "Marca é obrigatória").required(),
        // car_model_id: Yup.number().min(1, "Modelo é obrigatório").required(),
        // registration_year: Yup.number().min(1900).max(new Date().getFullYear() + 1).required(),
        // version: Yup.string().required("Versão é obrigatória"),
        // fuel_type: Yup.string().required("Combustível é obrigatório"),
        // transmission: Yup.string().required("Transmissão é obrigatória"),
        // segment: Yup.string().required("Segmento é obrigatório"),
        // exterior_color: Yup.string().required("Cor exterior é obrigatória"),
    });

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: {
            ...data,
            extras: data.extras ?? [],
            extrasByGroup: arrayToMap(data.extras),

            stored_images: data.images,

            existing_images: data.images?.slice()
                .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
                .map((i) => i.image) ?? [],

            existing_images_meta: data.images?.slice()
                .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
                .map((i, idx) => ({ order: idx + 1, is_primary: Boolean(i.is_primary) })) ?? [],

            images: [],
            images_meta: [],
        },
        validationSchema,
        onSubmit: (values) => onSubmit?.(values),
    });

    return (
        <div className="page-content">
            <Container fluid>
                <Row>
                    <Col lg={12}>
                        <Card>
                            <CardBody>
                                <FormikProvider value={formik}>
                                    <form onSubmit={formik.handleSubmit}>
                                        <CarInformationDataFields isEdit={isEdit} />
                                        <CarVehicleDataFields isEdit={isEdit} />
                                        <CarVehicleDetailsDataFields isEdit={isEdit} />
                                        <CarAdditionalDataFields isEdit={isEdit} />
                                        <CarPriceDataFields isEdit={isEdit} />
                                        <CarEquipmentDataFields isEdit={isEdit} />
                                        <CarDescriptionDataFields isEdit={isEdit} />
                                        <CarImagesDataFields isEdit={isEdit} />

                                        <Col lg={12}>
                                            <div className="hstack gap-2 justify-content-end mt-4">
                                                <XButton
                                                    variant="success"
                                                    type='submit'
                                                    outline
                                                    rounded
                                                    icon={<i className="ri-check-double-line" />}
                                                >
                                                    Salvar
                                                </XButton>
                                                <XButton
                                                    variant="danger"
                                                    outline
                                                    rounded
                                                    icon={<i className="ri-close-line" />}
                                                    onClick={() => onCancel()}
                                                >
                                                    Cancelar
                                                </XButton>
                                            </div>
                                        </Col>
                                    </form>
                                </FormikProvider>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default CarEditor;
