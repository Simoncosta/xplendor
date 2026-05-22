import { useState, useEffect, useRef } from "react";
import { Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import { toast } from "react-toastify";

import { useQuill } from "react-quilljs";
import "quill/dist/quill.snow.css";

import type { ICarFormValues } from "./CarImagesDataFields";
import XButton from "Components/Common/XButton";
import { generateCarDescriptionApi } from "helpers/laravel_helper";
import type { VehicleType } from "common/models/car.model";

const FIELD_LABELS: Record<string, string> = {
    vehicle_type: "Tipo de Veículo",
    car_brand_id: "Marca",
    car_model_id: "Modelo",
    registration_year: "Ano",
    fuel_type: "Combustível",
    engine_capacity_cc: "Cilindrada",
    power_hp: "Potência",
    price_gross: "Preço",
};

function getMissingFields(values: ICarFormValues): string[] {
    const missing: string[] = [];
    const type = values.vehicle_type as VehicleType;

    if (!type) missing.push(FIELD_LABELS.vehicle_type);
    if (!values.car_brand_id) missing.push(FIELD_LABELS.car_brand_id);
    if (!values.car_model_id) missing.push(FIELD_LABELS.car_model_id);
    if (!values.registration_year) missing.push(FIELD_LABELS.registration_year);
    if (!values.price_gross) missing.push(FIELD_LABELS.price_gross);

    if (type === "car") {
        if (!values.fuel_type) missing.push(FIELD_LABELS.fuel_type);
        if (!values.engine_capacity_cc) missing.push(FIELD_LABELS.engine_capacity_cc);
        if (!values.power_hp) missing.push(FIELD_LABELS.power_hp);
    } else if (type === "motorhome") {
        if (!values.engine_capacity_cc) missing.push(FIELD_LABELS.engine_capacity_cc);
        if (!values.power_hp) missing.push(FIELD_LABELS.power_hp);
    }

    return missing;
}

export default function CarDescriptionDataFields({
    isEdit,
    companyId,
}: {
    isEdit: boolean;
    companyId?: number;
}) {
    const { values, setFieldValue } = useFormikContext<ICarFormValues>();
    const [isGenerating, setIsGenerating] = useState(false);

    const { quill, quillRef } = useQuill({
        theme: "snow",
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ["bold", "italic", "underline"],
                [{ list: "ordered" }, { list: "bullet" }],
                ["link"],
                ["clean"],
            ],
        },
    });

    const fieldName = "description_website_pt" as const;
    const isSettingFromQuill = useRef(false);

    useEffect(() => {
        if (!quill) return;
        const html = values[fieldName] ?? "";
        const current = quill.root.innerHTML;
        if (current !== html && !isSettingFromQuill.current) {
            quill.clipboard.dangerouslyPasteHTML(html);
        }
    }, [quill, values[fieldName]]);

    useEffect(() => {
        if (!quill) return;

        const handler = () => {
            isSettingFromQuill.current = true;
            const html = quill.root.innerHTML;
            const normalized = html === "<p><br></p>" ? "" : html;
            setFieldValue(fieldName, normalized);
            setTimeout(() => { isSettingFromQuill.current = false; }, 0);
        };

        quill.on("text-change", handler);
        return () => { quill.off("text-change", handler); };
    }, [quill, setFieldValue]);

    const missingFields = getMissingFields(values);
    const canGenerate = missingFields.length === 0 && Boolean(companyId);

    const handleGenerate = async () => {
        if (!canGenerate || isGenerating) return;

        setIsGenerating(true);
        try {
            const payload = {
                vehicle_type:        values.vehicle_type,
                car_brand_id:        values.car_brand_id,
                car_model_id:        values.car_model_id,
                registration_year:   values.registration_year,
                fuel_type:           values.fuel_type,
                power_hp:            values.power_hp,
                engine_capacity_cc:  values.engine_capacity_cc,
                transmission:        values.transmission,
                seats:               values.seats,
                mileage_km:          values.mileage_km,
                segment:             values.segment,
                subsegment:          values.subsegment,
                version:             values.version,
                exterior_color:      values.exterior_color,
                price_gross:         values.price_gross,
                promo_price_gross:   values.promo_price_gross,
                extras:              values.extras ?? [],
                vehicle_attributes:  values.vehicle_attributes,
            };

            const response: any = await generateCarDescriptionApi(companyId!, payload);
            const description: string = response?.data?.description ?? response?.description ?? "";

            if (description) {
                // Wrap paragraphs for Quill
                const html = description
                    .split(/\n{2,}/)
                    .map((p: string) => `<p>${p.trim()}</p>`)
                    .join("");
                setFieldValue(fieldName, html || `<p>${description}</p>`);
            }
        } catch (error: unknown) {
            console.error(error);
            const axiosError = error as { response?: { status?: number; data?: { message?: string } } };
            const status = axiosError?.response?.status;
            const serverMessage = axiosError?.response?.data?.message;

            let message: string;
            if (serverMessage && status !== undefined && status < 500) {
                message = serverMessage;
            } else if (status === undefined || status >= 500) {
                message = "Serviço temporariamente indisponível. Tenta novamente em alguns segundos.";
            } else {
                message = "Não foi possível gerar a descrição. Tenta novamente.";
            }

            toast.error(message, { position: "top-right", hideProgressBar: true });
        } finally {
            setIsGenerating(false);
        }
    };

    return (
        <div className="mt-4">
            <div className="mb-2 border-bottom pb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 className="card-title mb-0">Descrição</h5>

                <div className="d-flex align-items-center gap-2 flex-wrap">
                    {!canGenerate && missingFields.length > 0 && (
                        <small className="text-muted">
                            Faltam: {missingFields.join(", ")}
                        </small>
                    )}
                    <XButton
                        size="sm"
                        variant="info"
                        soft
                        rounded
                        icon={<i className="ri-magic-line" />}
                        loading={isGenerating}
                        disabled={!canGenerate || isGenerating}
                        onClick={handleGenerate}
                    >
                        {isGenerating ? "A gerar..." : "Gerar com IA"}
                    </XButton>
                </div>
            </div>

            <Row>
                <Col lg={12}>
                    <div className="snow-editor" style={{ height: 300 }}>
                        <div ref={quillRef} />
                    </div>
                </Col>
            </Row>
        </div>
    );
}
