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
import CarVehicleDetailsDataFields, { type CarVehicleDetailsHandle } from "./components/CarVehicleDetailsDataFields";
import FormSearchBar from "./components/FormSearchBar";
import { useFieldSpotlight } from "./hooks/useFieldSpotlight";
import CarAdditionalDataFields from "./components/CarAdditionalDataFields";
import CarImagesDataFields from "./components/CarImagesDataFields";
import CarPriceDataFields from "./components/CarPriceDataFields";
import CarEquipmentDataFields, { type CarEquipmentHandle } from "./components/CarEquipmentDataFields";
import CarDescriptionDataFields from "./components/CarDescriptionDataFields";
import CarSaleClosingModal from "./components/CarSaleClosingModal";

//formik
import { FormikProvider, useFormik } from "formik";
import { useMemo, useRef, useState } from "react";
import * as Yup from "yup";
import { DEFAULT_VEHICLE_ATTRIBUTES } from "slices/cars/car.defaults";

type CarEditorProps = {
    data: ICarUpdatePayload;
    onSubmit: (data: ICarUpdatePayload) => void | Promise<void>;
    onSubmitSold?: (carData: ICarUpdatePayload, saleData: ICarSalePayload) => void | Promise<void>;
    // R2 (1.14.7) — "Guardar rascunho": payload com status forçado a 'draft' +
    // flag `__save_as_draft=1`. Validação backend relaxa-se, BD aceita.
    // O pai (CarCreate/CarUpdate) é quem despacha o thunk e mostra toast.
    onSubmitDraft?: (data: ICarUpdatePayload) => void | Promise<void>;
    onCancel: () => void;
    loading?: boolean;
    saleLoading?: boolean;
    draftLoading?: boolean;
    companyId?: number;
    validationErrors?: ApiValidationError[] | null;
    onDismissValidationErrors?: () => void;
};

const CarEditor = ({
    data,
    onSubmit,
    onSubmitSold,
    onSubmitDraft,
    onCancel,
    loading = false,
    saleLoading = false,
    draftLoading = false,
    companyId,
    validationErrors = null,
    onDismissValidationErrors,
}: CarEditorProps) => {
    const isEdit = Boolean((data as any)?.id);
    const initialStatus = data.status ?? "draft";

    // Etapa 5 da busca universal — refs aos 2 accordion-containers para o
    // useFieldSpotlight conseguir abrir programaticamente.
    const habitationRef = useRef<CarVehicleDetailsHandle | null>(null);
    const equipmentRef = useRef<CarEquipmentHandle | null>(null);
    const spotlightField = useFieldSpotlight({ habitationRef, equipmentRef });

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

    // Fix 1.D (auditoria pós Sub-fase F, 2026-06-18): `cama_sofa` adicionado em
    // M2.1 (1.13.3, 2026-06-15) à BD e ao seeder, mas estava em falta neste set.
    // Sem isto, escolher "Cama sofá" no dropdown reverte para "outra" no próximo
    // init do Formik.
    const validBedSlugs = new Set<BedType>([
        "camas_gemeas", "cama_central", "cama_francesa", "cama_basculante",
        "cama_capucino", "cama_garagem", "beliche", "cama_transversal",
        "cama_elevatoria_eletrica", "cama_suspensa", "cama_convertivel",
        "cama_sofa",
        "outra", "cama_rebativel_cabine",
    ]);

    // Fix 1.A (auditoria pós Sub-fase F, 2026-06-18): preserva `capacity`.
    // Antes, esta função devolvia só `{type}` e descartava `capacity` no init
    // dos initialValues do Formik. Combinado com `enableReinitialize: true`,
    // a capacity desaparecia sempre depois de gravar — o input caía no
    // fallback `value={bed?.capacity ?? 1}` e mostrava "1".
    // Clamp 1-4 espelha as constraints da Form Request backend
    // (`vehicle_attributes.beds.*.capacity` → min:1 max:4) e da
    // `VehicleAttribute::normalizeBedTypes`. Fallback 1 para registos legacy
    // sem capacity.
    const normalizeBeds = (beds: unknown): Array<{ type: BedType; capacity: number }> => {
        if (!Array.isArray(beds)) return [];

        return beds.map((bed) => {
            const raw = typeof bed === "string" ? bed : (bed as Record<string, unknown>)?.type;
            const type = typeof raw === "string" && validBedSlugs.has(raw as BedType)
                ? (raw as BedType)
                : "outra";

            const capRaw = typeof bed === "object" && bed !== null
                ? (bed as Record<string, unknown>).capacity
                : undefined;
            const capNum = typeof capRaw === "number" ? capRaw : Number(capRaw);
            const capacity = Number.isFinite(capNum)
                ? Math.max(1, Math.min(4, Math.floor(capNum)))
                : 1;

            return { type, capacity };
        });
    };

    const normalizeVehicleAttributes = (attributes?: ICarUpdatePayload["vehicle_attributes"]) => {
        const { autonomy_km, ...restAttributes } = attributes ?? {};

        // 2026-06-29 — Retro-compat dos depósitos de água: viaturas antigas
        // podem ter `clean_water_litres` / `waste_water_litres` > 0 mas ainda
        // sem as checkboxes novas. Neste caso o UI marca a checkbox como
        // true automaticamente (litros > 0 implica "tem depósito"). O JSON
        // gravado a partir do próximo save fica com a checkbox explícita.
        const hb = attributes?.habitation_basics ?? {};
        const b = (hb as any).bathroom ?? {};
        const cleanLitres = Number(b.clean_water_litres) || 0;
        const wasteLitres = Number(b.waste_water_litres) || 0;
        const bathroomWithRetroCompat = {
            ...b,
            has_clean_water_tank: b.has_clean_water_tank === true || cleanLitres > 0,
            has_waste_water_tank: b.has_waste_water_tank === true || wasteLitres > 0,
        };

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
            habitation_basics: {
                ...hb,
                bathroom: bathroomWithRetroCompat,
            },
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
        () => loading || saleLoading || draftLoading,
        [loading, saleLoading, draftLoading]
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
                                        {/* Busca universal — sticky no topo. Etapa 5: o
                                            `useFieldSpotlight` abre o accordion certo (via ref
                                            exposta com useImperativeHandle), scrolla até ao
                                            topo do .accordion-item, e anima destaque amarelo soft
                                            de 1.5s no header. Para `parent_off`, o FormSearchBar
                                            já substituiu upstream a entrada do filho pela entrada
                                            do PAI — este `spotlightField` recebe a entrada-pai e
                                            abre o accordion onde o pai vive. */}
                                        <FormSearchBar onSelect={spotlightField} />
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
                                        <CarVehicleDetailsDataFields ref={habitationRef} isEdit={isEdit} />
                                        <div id="section-additional">
                                            <CarAdditionalDataFields isEdit={isEdit} />
                                        </div>
                                        <div id="section-price">
                                            <CarPriceDataFields isEdit={isEdit} />
                                        </div>
                                        <CarEquipmentDataFields ref={equipmentRef} isEdit={isEdit} />
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
                                                {onSubmitDraft && (
                                                    <XButton
                                                        variant="secondary"
                                                        type="button"
                                                        outline
                                                        rounded
                                                        icon={<i className="ri-draft-line" />}
                                                        loading={draftLoading}
                                                        disabled={isSubmitting}
                                                        onClick={async () => {
                                                            // R2 (1.14.7) — força status='draft' antes do submit, mesmo
                                                            // que o <Select> esteja noutro valor. Garante que rascunhos
                                                            // gravam como rascunhos independentemente do dropdown.
                                                            const draftValues = { ...formik.values, status: "draft" as const };
                                                            await onSubmitDraft(draftValues);
                                                        }}
                                                    >
                                                        {draftLoading
                                                            ? <>A guardar<span className="d-none d-sm-inline"> rascunho</span>…</>
                                                            : <>Guardar<span className="d-none d-sm-inline"> rascunho</span></>}
                                                    </XButton>
                                                )}
                                                <XButton
                                                    variant="success"
                                                    type='submit'
                                                    outline
                                                    rounded
                                                    icon={<i className="ri-check-double-line" />}
                                                    loading={loading || saleLoading}
                                                    disabled={isSubmitting}
                                                >
                                                    {saleLoading
                                                        ? <>A concluir<span className="d-none d-sm-inline"> venda</span>…</>
                                                        : (loading || saleLoading)
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
