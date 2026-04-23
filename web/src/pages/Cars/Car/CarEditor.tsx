// React
import {
    Col,
    Container,
    Row,
    CardBody,
    Card,
} from "reactstrap";
// Models
import { ICarUpdatePayload } from "common/models/car.model";
import { ICarSalePayload } from "common/models/car-sale.model";
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
import CarSaleClosingModal from "./components/CarSaleClosingModal";

//formik
import { FormikProvider, useFormik } from "formik";
import { useMemo, useState } from "react";
import * as Yup from "yup";
import { DEFAULT_VEHICLE_ATTRIBUTES } from "slices/cars/car.defaults";

type CarEditorProps = {
    data: ICarUpdatePayload;
    onSubmit: (data: ICarUpdatePayload) => void | Promise<void>;
    onSubmitSold?: (carData: ICarUpdatePayload, saleData: ICarSalePayload) => void | Promise<void>;
    onCancel: () => void;
    loading?: boolean;
    saleLoading?: boolean;
    companyId?: number;
};

const CarEditor = ({
    data,
    onSubmit,
    onSubmitSold,
    onCancel,
    loading = false,
    saleLoading = false,
    companyId,
}: CarEditorProps) => {
    const isEdit = Boolean((data as any)?.id);
    const initialStatus = data.status ?? "draft";
    const [isSaleModalOpen, setIsSaleModalOpen] = useState(false);
    const [saleDraft, setSaleDraft] = useState<ICarSalePayload | null>(null);
    const [pendingSubmitValues, setPendingSubmitValues] = useState<ICarUpdatePayload | null>(null);
    const [saleModalSource, setSaleModalSource] = useState<"status_change" | "submit" | null>(null);
    const [statusBeforeSaleModal, setStatusBeforeSaleModal] = useState<string | null>(null);

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

    const normalizeBeds = (beds: any) => {
        if (!Array.isArray(beds)) {
            return [];
        }

        return beds
            .map((bed) => {
                const type = typeof bed === "string" ? bed : bed?.type;

                return type ? { type } : null;
            })
            .filter((bed): bed is { type: string } => bed !== null);
    };

    const normalizeVehicleAttributes = (attributes?: ICarUpdatePayload["vehicle_attributes"]) => {
        const { autonomy_km, ...restAttributes } = attributes ?? {};

        return {
            ...DEFAULT_VEHICLE_ATTRIBUTES,
            ...restAttributes,
            autonomy: restAttributes.autonomy ?? autonomy_km ?? "",
            beds: normalizeBeds(restAttributes.beds),
            has_bathroom:
                attributes?.has_bathroom === true
                || attributes?.has_bathroom === 1
                || String(attributes?.has_bathroom ?? "") === "1",
            has_kitchen:
                attributes?.has_kitchen === true
                || attributes?.has_kitchen === 1
                || String(attributes?.has_kitchen ?? "") === "1",
        };
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
        subsegment: Yup.string().nullable(),
        car_category_id: Yup.number().nullable(),
    });

    const isSubmitting = useMemo(
        () => loading || saleLoading,
        [loading, saleLoading]
    );

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: {
            ...data,
            vehicle_type: data.vehicle_type ?? "car",
            subsegment: null,
            segment: data.segment,
            extras: data.extras ?? [],
            extrasByGroup: arrayToMap(data.extras),
            vehicle_attributes: normalizeVehicleAttributes(data.vehicle_attributes),

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
        onSubmit: async (values) => {
            if (isSubmitting) return;

            const shouldCaptureSale = isEdit
                && initialStatus !== "sold"
                && values.status === "sold"
                && Boolean(onSubmitSold);

            if (shouldCaptureSale) {
                if (!saleDraft) {
                    setPendingSubmitValues(values);
                    setSaleModalSource("submit");
                    setStatusBeforeSaleModal(values.status ?? initialStatus);
                    setIsSaleModalOpen(true);
                    return;
                }

                await onSubmitSold?.(values, saleDraft);
                return;
            }

            await onSubmit?.(values);
        },
    });

    const openSaleModalFromStatusChange = (previousStatus: string | null) => {
        if (!isEdit || initialStatus === "sold" || !onSubmitSold) {
            return;
        }

        setStatusBeforeSaleModal(previousStatus);
        setSaleModalSource("status_change");
        setIsSaleModalOpen(true);
    };

    const handleStatusChange = (nextStatus: string | null, previousStatus: string | null) => {
        if (nextStatus === "sold" && previousStatus !== "sold") {
            openSaleModalFromStatusChange(previousStatus);
            return;
        }

        if (nextStatus !== "sold") {
            setSaleDraft(null);
        }
    };

    const closeSaleModal = () => {
        if (saleModalSource === "status_change" && formik.values.status === "sold") {
            formik.setFieldValue("status", statusBeforeSaleModal ?? initialStatus);
        }

        setPendingSubmitValues(null);
        setSaleModalSource(null);
        setStatusBeforeSaleModal(null);
        setIsSaleModalOpen(false);
    };

    const handleSaleModalConfirm = async (saleData: ICarSalePayload, mode: "draft" | "submit") => {
        if (mode === "submit" && onSubmitSold) {
            const valuesToSubmit = pendingSubmitValues ?? formik.values;

            await onSubmitSold(valuesToSubmit, saleData);
            setSaleDraft(saleData);
            setPendingSubmitValues(null);
            setSaleModalSource(null);
            setStatusBeforeSaleModal(null);
            setIsSaleModalOpen(false);
            return;
        }

        setSaleDraft(saleData);
        setPendingSubmitValues(null);
        setSaleModalSource(null);
        setStatusBeforeSaleModal(null);
        setIsSaleModalOpen(false);
    };

    return (
        <div className="page-content">
            <Container fluid>
                <Row>
                    <Col lg={12}>
                        <Card>
                            <CardBody>
                                <FormikProvider value={formik}>
                                    <form onSubmit={formik.handleSubmit}>
                                        <CarInformationDataFields
                                            isEdit={isEdit}
                                            companyId={companyId}
                                            onStatusChange={handleStatusChange}
                                        />
                                        <CarVehicleDataFields isEdit={isEdit} />
                                        <CarVehicleDetailsDataFields isEdit={isEdit} />
                                        <CarAdditionalDataFields isEdit={isEdit} />
                                        <CarPriceDataFields isEdit={isEdit} />
                                        <CarEquipmentDataFields isEdit={isEdit} />
                                        <CarDescriptionDataFields isEdit={isEdit} />
                                        <CarImagesDataFields isEdit={isEdit} companyId={companyId} />

                                        <Col lg={12}>
                                            <div className="hstack gap-2 justify-content-end mt-4">
                                                <XButton
                                                    variant="success"
                                                    type='submit'
                                                    outline
                                                    rounded
                                                    icon={<i className="ri-check-double-line" />}
                                                    loading={isSubmitting}
                                                    disabled={isSubmitting}
                                                >
                                                    {saleLoading
                                                        ? "A concluir venda..."
                                                        : isSubmitting
                                                            ? isEdit
                                                                ? "A guardar alterações..."
                                                                : "A criar viatura..."
                                                            : isEdit
                                                            ? "Guardar alterações"
                                                            : "Criar viatura"}
                                                </XButton>
                                                <XButton
                                                    variant="danger"
                                                    outline
                                                    rounded
                                                    icon={<i className="ri-close-line" />}
                                                    disabled={isSubmitting}
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

            <CarSaleClosingModal
                isOpen={isSaleModalOpen}
                loading={saleLoading}
                initialData={saleDraft}
                defaultSalePrice={formik.values.price_gross ?? data.price_gross ?? null}
                onClose={closeSaleModal}
                onConfirm={handleSaleModalConfirm}
            />
        </div>
    );
};

export default CarEditor;
