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
import type { BedType } from "./data/vehicleAttributes";
import { ICarSalePayload } from "common/models/car-sale.model";
// Components
import XButton from "Components/Common/XButton";
import ValidationAlert from "Components/Common/ValidationAlert";
import type { ApiValidationError } from "helpers/error_helper";
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
    validationErrors?: ApiValidationError[] | null;
    onDismissValidationErrors?: () => void;
};

const CarEditor = ({
    data,
    onSubmit,
    onSubmitSold,
    onCancel,
    loading = false,
    saleLoading = false,
    companyId,
    validationErrors = null,
    onDismissValidationErrors,
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

    const validBedSlugs = new Set<BedType>([
        "camas_gemeas", "cama_central", "cama_francesa", "cama_basculante",
        "cama_capucino", "cama_garagem", "beliche", "cama_transversal",
        "cama_elevatoria_eletrica", "cama_suspensa", "cama_convertivel",
        "outra", "cama_rebativel_cabine",
    ]);

    const normalizeBeds = (beds: unknown): Array<{ type: BedType }> => {
        if (!Array.isArray(beds)) return [];

        return beds
            .map((bed): { type: BedType } | null => {
                const raw = typeof bed === "string" ? bed : (bed as Record<string, unknown>)?.type;
                const type = typeof raw === "string" && validBedSlugs.has(raw as BedType)
                    ? (raw as BedType)
                    : "outra";
                return { type };
            })
            .filter((bed): bed is { type: BedType } => bed !== null);
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
                                    {/* paddingBottom: 80px no <form> garante espaço
                                        de respiração abaixo do último campo
                                        (CarImagesDataFields), para a barra sticky
                                        não tapar o conteúdo durante o scroll. */}
                                    <form onSubmit={formik.handleSubmit} style={{ paddingBottom: "80px" }}>
                                        <ValidationAlert
                                            errors={validationErrors}
                                            onDismiss={onDismissValidationErrors}
                                        />
                                        {/* Ids nas secções soltas — busca universal usa
                                            `getElementById` para scrollIntoView.
                                            CarVehicleDetailsDataFields já tem `id="section-details"`
                                            no seu próprio wrapper (acolhe campos base + 9 accordions
                                            de habitação). CarEquipmentDataFields é só accordion-container,
                                            scroll vai via ref do accordion (ver useFieldSpotlight). */}
                                        <div id="section-information">
                                            <CarInformationDataFields
                                                isEdit={isEdit}
                                                companyId={companyId}
                                                onStatusChange={handleStatusChange}
                                            />
                                        </div>
                                        <div id="section-vehicle">
                                            <CarVehicleDataFields isEdit={isEdit} />
                                        </div>
                                        <CarVehicleDetailsDataFields isEdit={isEdit} />
                                        <div id="section-additional">
                                            <CarAdditionalDataFields isEdit={isEdit} />
                                        </div>
                                        <div id="section-price">
                                            <CarPriceDataFields isEdit={isEdit} />
                                        </div>
                                        <CarEquipmentDataFields isEdit={isEdit} />
                                        <div id="section-description">
                                            <CarDescriptionDataFields isEdit={isEdit} companyId={companyId} />
                                        </div>
                                        <div id="section-images">
                                            <CarImagesDataFields isEdit={isEdit} companyId={companyId} />
                                        </div>

                                        {/* Barra de ações STICKY no fundo do form.
                                            Vive dentro do CardBody (não fixed ao viewport)
                                            → respeita sidebar à esquerda; quando o user faz
                                            scroll até ao fim, sai do scroll natural e fica
                                            estática (Velzon footer aparece abaixo sem
                                            colisão). Margens negativas no eixo X ocupam
                                            largura total do CardBody. */}
                                        <div
                                            style={{
                                                position: "sticky",
                                                bottom: 0,
                                                zIndex: 10,
                                                background: "#fff",
                                                borderTop: "1px solid #e9ebec",
                                                marginLeft: "calc(-1 * var(--vz-card-spacer-x, 1.5rem))",
                                                marginRight: "calc(-1 * var(--vz-card-spacer-x, 1.5rem))",
                                                marginBottom: "calc(-1 * var(--vz-card-spacer-y, 1.5rem))",
                                                marginTop: "1.5rem",
                                                padding: "12px 18px",
                                            }}
                                        >
                                            <div className="hstack gap-2 justify-content-end">
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
                                                        ? <>A concluir<span className="d-none d-sm-inline"> venda</span>…</>
                                                        : isSubmitting
                                                            ? isEdit
                                                                ? <>A guardar<span className="d-none d-sm-inline"> alterações</span>…</>
                                                                : <>A criar<span className="d-none d-sm-inline"> viatura</span>…</>
                                                            : isEdit
                                                                ? <>Guardar<span className="d-none d-sm-inline"> alterações</span></>
                                                                : <>Criar<span className="d-none d-sm-inline"> viatura</span></>}
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
                                        </div>
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
