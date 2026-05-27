// TODO: Extrair B1 accordions (Dimensões, Cozinha, Casa de Banho) para
// sub-componentes em components/vehicleAttributes/ na próxima iteração,
// alinhando com o padrão dos B2 accordions.

//React
import { useEffect, useState } from "react";
import Select from "react-select";
import { Accordion, AccordionBody, AccordionHeader, AccordionItem, Button, Col, Label, Row } from "reactstrap";

// Components
import XInput from "Components/Common/XInput";

// Forms
import { useFormikContext } from "formik";

// Models
import { ICarUpdatePayload } from "common/models/car.model";

// Redux
import { colorsOptions, conditionsOptions, seatsOptions, segmentOptions } from "common/data/cars";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import { DEFAULT_VEHICLE_ATTRIBUTES } from "slices/cars/car.defaults";
import { getCarCategories } from "helpers/laravel_helper";
import EnergyClimateAccordion from "./vehicleAttributes/EnergyClimateAccordion";
import ExteriorAccordion from "./vehicleAttributes/ExteriorAccordion";
import SecurityAccordion from "./vehicleAttributes/SecurityAccordion";
import ChassisStructureAccordion from "./vehicleAttributes/ChassisStructureAccordion";
import InteriorFurnitureAccordion from "./vehicleAttributes/InteriorFurnitureAccordion";
import { BED_LABELS, BedType } from "../data/vehicleAttributes";

const fridgeTypeOptions = [
    { value: "trivalent",  label: "Trivalente" },
    { value: "compressor", label: "Compressor" },
    { value: "absorption", label: "Absorção" },
    { value: "none",       label: "Nenhum" },
];

const showerTypeOptions = [
    { value: "independent", label: "Independente" },
    { value: "combined", label: "Combinado com WC" },
    { value: "none",     label: "Nenhum" },
];

// 12 options shown by default; cama_rebativel_cabine is legacy and only injected
// dynamically when the current bed already has that value (see getBedOptions below).
const BED_OPTIONS_DEFAULT: { value: BedType; label: string }[] = (
    [
        "camas_gemeas", "cama_central", "cama_francesa", "cama_basculante",
        "cama_capucino", "cama_garagem", "beliche", "cama_transversal",
        "cama_elevatoria_eletrica", "cama_suspensa", "cama_convertivel", "outra",
    ] as BedType[]
).map(slug => ({ value: slug, label: BED_LABELS[slug] }));

function getBedOptions(currentType: string | undefined): { value: BedType; label: string }[] {
    if (currentType === "cama_rebativel_cabine") {
        return [
            ...BED_OPTIONS_DEFAULT,
            { value: "cama_rebativel_cabine", label: BED_LABELS.cama_rebativel_cabine },
        ];
    }
    return BED_OPTIONS_DEFAULT;
}

export default function CarVehicleDetailsDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();
    const [categoryOptions, setCategoryOptions] = useState<{ value: number; label: string }[]>([]);
    const [loadingCategories, setLoadingCategories] = useState(false);

    useEffect(() => {
        setFieldValue("subsegment", null);
    }, [values.vehicle_type, setFieldValue]);

    useEffect(() => {
        if (values.vehicle_type === "motorhome") return;

        setFieldValue("car_category_id", null);
    }, [values.vehicle_type, setFieldValue]);

    useEffect(() => {
        let isMounted = true;

        const fetchCategories = async () => {
            if (values.vehicle_type !== "motorhome") {
                setCategoryOptions([]);
                return;
            }

            setLoadingCategories(true);

            try {
                const response = await getCarCategories("motorhome");
                const categories = (response?.data || [])
                    .map((category: any) => ({
                        value: Number(category.id),
                        label: category.name ?? String(category.slug ?? category.id),
                    }))
                    .filter((category: { value: number; label: string }) => Number.isFinite(category.value));

                if (isMounted) {
                    setCategoryOptions(categories);
                }
            } catch (error) {
                if (isMounted) {
                    setCategoryOptions([]);
                }
            } finally {
                if (isMounted) {
                    setLoadingCategories(false);
                }
            }
        };

        fetchCategories();

        return () => {
            isMounted = false;
        };
    }, [values.vehicle_type]);

    useEffect(() => {
        if (values.vehicle_type === "motorhome" || values.vehicle_type === "caravan") {
            setFieldValue("segment", values.vehicle_type);
            return;
        }

        setFieldValue("vehicle_attributes", { ...DEFAULT_VEHICLE_ATTRIBUTES });
    }, [values.vehicle_type, setFieldValue]);

    const hasHabitationAttributes = values.vehicle_type === "motorhome" || values.vehicle_type === "caravan";
    const beds = Array.isArray(values.vehicle_attributes?.beds)
        ? values.vehicle_attributes.beds
        : [];

    const [attrAccordion, setAttrAccordion] = useState<string>("1");
    const toggleAttrAccordion = (id: string) => setAttrAccordion(attrAccordion === id ? "" : id);

    return (
        <div className="mt-4">
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Detalhes da Viatura</h5>
            </div>

            <Row>
                {!hasHabitationAttributes && (
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
                )}
                {values.vehicle_type === "motorhome" && (
                    <Col lg={2}>
                        <Label for="car_category_id">
                            Categoria:
                        </Label>
                        <Select
                            id="car_category_id"
                            name="car_category_id"
                            options={categoryOptions}
                            isClearable
                            isLoading={loadingCategories}
                            placeholder="Selecionar categoria"
                            value={categoryOptions.find((option) => option.value === Number(values.car_category_id)) || null}
                            onChange={(option: any) => {
                                setFieldValue("car_category_id", option?.value || null);
                                setFieldTouched("car_category_id", true);
                            }}
                            className="mb-3"
                        />
                    </Col>
                )}
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
                    />
                </Col>
            </Row>

            {hasHabitationAttributes && (
                <div className="mt-2">
                    <Accordion flush open={attrAccordion} toggle={toggleAttrAccordion}>

                        <AccordionItem>
                            <AccordionHeader targetId="1">
                                <strong>Dimensões e Pesos</strong>
                            </AccordionHeader>
                            <AccordionBody accordionId="1">
                                <Row>
                                    <Col lg={2}>
                                        <XInput
                                            type="number"
                                            step="0.01"
                                            name="vehicle_attributes.dimensions.length_m"
                                            label="Comprimento (m)"
                                            className="mb-3"
                                        />
                                    </Col>
                                    <Col lg={2}>
                                        <XInput
                                            type="number"
                                            step="0.01"
                                            name="vehicle_attributes.dimensions.width_m"
                                            label="Largura (m)"
                                            className="mb-3"
                                        />
                                    </Col>
                                    <Col lg={2}>
                                        <XInput
                                            type="number"
                                            step="0.01"
                                            name="vehicle_attributes.dimensions.height_m"
                                            label="Altura (m)"
                                            className="mb-3"
                                        />
                                    </Col>
                                    <Col lg={2}>
                                        <XInput
                                            type="number"
                                            name="vehicle_attributes.weights.gross_weight_kg"
                                            label="Peso bruto (kg)"
                                            className="mb-3"
                                        />
                                    </Col>
                                    <Col lg={2}>
                                        <XInput
                                            type="number"
                                            name="vehicle_attributes.autonomy_km"
                                            label="Autonomia (km)"
                                            className="mb-3"
                                        />
                                    </Col>
                                </Row>

                                <div className="border rounded-3 p-3 mb-1">
                                    <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                        <div>
                                            <Label className="mb-1">Camas</Label>
                                            <div className="text-muted fs-12">Adiciona quantas camas forem necessárias.</div>
                                        </div>
                                        <Button
                                            type="button"
                                            color="light"
                                            className="border"
                                            onClick={() => setFieldValue("vehicle_attributes.beds", [...beds, { type: "outra" as BedType }])}
                                        >
                                            Adicionar cama
                                        </Button>
                                    </div>

                                    {beds.length === 0 ? (
                                        <div className="text-muted fs-13">Sem camas adicionadas.</div>
                                    ) : (
                                        <Row>
                                            {beds.map((bed, index) => (
                                                <Col lg={3} key={`bed-${index}`}>
                                                    <div className="d-flex gap-2 align-items-start mb-3">
                                                        <div className="flex-grow-1">
                                                            <Select
                                                                name={`vehicle_attributes.beds.${index}.type`}
                                                                options={getBedOptions(bed?.type)}
                                                                value={getBedOptions(bed?.type).find((option) => option.value === bed?.type) || null}
                                                                placeholder="Tipo de cama"
                                                                onChange={(option: { value: BedType; label: string } | null) => {
                                                                    const nextBeds = [...beds];
                                                                    nextBeds[index] = { type: option?.value ?? "outra" };
                                                                    setFieldValue("vehicle_attributes.beds", nextBeds);
                                                                }}
                                                            />
                                                        </div>
                                                        <Button
                                                            type="button"
                                                            color="light"
                                                            className="border"
                                                            onClick={() => {
                                                                const nextBeds = beds.filter((_, bedIndex) => bedIndex !== index);
                                                                setFieldValue("vehicle_attributes.beds", nextBeds);
                                                            }}
                                                        >
                                                            Remover
                                                        </Button>
                                                    </div>
                                                </Col>
                                            ))}
                                        </Row>
                                    )}
                                </div>
                            </AccordionBody>
                        </AccordionItem>

                        <AccordionItem>
                            <AccordionHeader targetId="2">
                                <strong>Cozinha</strong>
                            </AccordionHeader>
                            <AccordionBody accordionId="2">
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
                                        <Col lg={2}>
                                            <XInputCheckbox
                                                name="vehicle_attributes.habitation_basics.kitchen.has_oven"
                                                label="Forno"
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
                                        <Col lg={2}>
                                            <XInputCheckbox
                                                name="vehicle_attributes.habitation_basics.kitchen.has_fridge"
                                                label="Frigorífico"
                                                className="mb-3"
                                            />
                                        </Col>
                                        {values.vehicle_attributes?.habitation_basics?.kitchen?.has_fridge && (
                                            <>
                                                <Col lg={2}>
                                                    <Label>Tipo de frigorífico</Label>
                                                    <Select
                                                        isClearable
                                                        placeholder="Selecionar"
                                                        options={fridgeTypeOptions}
                                                        value={fridgeTypeOptions.find(o => o.value === values.vehicle_attributes?.habitation_basics?.kitchen?.fridge_type) ?? null}
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
                            </AccordionBody>
                        </AccordionItem>

                        <AccordionItem>
                            <AccordionHeader targetId="3">
                                <strong>Casa de Banho</strong>
                            </AccordionHeader>
                            <AccordionBody accordionId="3">
                                <Row>
                                    <Col lg={12}>
                                        <XInputCheckbox
                                            name="vehicle_attributes.habitation_basics.has_bathroom"
                                            label="Tem casa de banho"
                                            className="mb-3"
                                        />
                                    </Col>
                                </Row>
                                {values.vehicle_attributes?.habitation_basics?.has_bathroom && (
                                    <Row>
                                        <Col lg={2}>
                                            <XInputCheckbox
                                                name="vehicle_attributes.habitation_basics.bathroom.has_toilet"
                                                label="Sanita"
                                                className="mb-3"
                                            />
                                        </Col>
                                        <Col lg={2}>
                                            <XInputCheckbox
                                                name="vehicle_attributes.habitation_basics.bathroom.has_shower"
                                                label="Duche"
                                                className="mb-3"
                                            />
                                        </Col>
                                        {values.vehicle_attributes?.habitation_basics?.bathroom?.has_shower && (
                                            <Col lg={2}>
                                                <Label>Tipo de duche</Label>
                                                <Select
                                                    isClearable
                                                    placeholder="Selecionar"
                                                    options={showerTypeOptions}
                                                    value={showerTypeOptions.find(o => o.value === values.vehicle_attributes?.habitation_basics?.bathroom?.shower_type) ?? null}
                                                    onChange={(opt: any) => setFieldValue("vehicle_attributes.habitation_basics.bathroom.shower_type", opt?.value ?? null)}
                                                    className="mb-3"
                                                />
                                            </Col>
                                        )}
                                        <Col lg={2}>
                                            <XInput
                                                type="number"
                                                name="vehicle_attributes.habitation_basics.bathroom.clean_water_litres"
                                                label="Água limpa (L)"
                                                className="mb-3"
                                            />
                                        </Col>
                                        <Col lg={2}>
                                            <XInput
                                                type="number"
                                                name="vehicle_attributes.habitation_basics.bathroom.waste_water_litres"
                                                label="Águas residuais (L)"
                                                className="mb-3"
                                            />
                                        </Col>
                                    </Row>
                                )}
                            </AccordionBody>
                        </AccordionItem>

                        <EnergyClimateAccordion accordionId="4" />
                        <ExteriorAccordion      accordionId="5" />
                        <SecurityAccordion      accordionId="6" />
                        <ChassisStructureAccordion accordionId="7" />
                        <InteriorFurnitureAccordion accordionId="8" />

                    </Accordion>
                </div>
            )}
        </div>
    )
}
