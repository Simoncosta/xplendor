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

// Máximo de gerações por veículo (proteção de custo de tokens — decisão do Simon).
const MAX_GENERATIONS = 3;

// Botões fixos de afinação. A CHAVE viaja para o backend; o texto da instrução
// vive lá (allow-list) — o frontend não manda instruções livres nos presets.
const REFINEMENT_OPTIONS: { key: string; label: string; icon: string }[] = [
    { key: "shorter",             label: "Mais curto",            icon: "ri-scissors-2-line" },
    { key: "formal",              label: "Mais formal",           icon: "ri-briefcase-4-line" },
    { key: "highlight_equipment", label: "Destacar equipamento",  icon: "ri-tools-line" },
    { key: "family_tone",         label: "Tom mais familiar",     icon: "ri-heart-3-line" },
];

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
    // Preço deixa de ser obrigatório quando 'Sob consulta' (hide_price_online) está marcado.
    // O backend (CarDescriptionService) já interpreta o flag e instrui a IA a tratar o preço
    // como "sob consulta" sem mencionar valores.
    if (!values.price_gross && !values.hide_price_online) missing.push(FIELD_LABELS.price_gross);

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
    // Sugestão da IA a aguardar validação humana (null = sem sugestão pendente).
    // O campo SÓ é preenchido quando o utilizador clica "Usar esta descrição".
    const [suggestion, setSuggestion] = useState<string | null>(null);

    // ── Limite de gerações (proteção de tokens) ─────────────────────────────
    // Contagem POR VEÍCULO. Em edição persiste em localStorage pela id do carro
    // (sobrevive a reload — protege melhor); em criação (sem id) fica em memória.
    // Sem migration nem coluna nova. Qualquer chamada à IA conta (dirigida ou não).
    const carId = isEdit && (values as { id?: number }).id ? Number((values as { id?: number }).id) : null;
    const storageKey = carId ? `xplndor_ai_desc_gens_${carId}` : null;
    const [genCount, setGenCount] = useState<number>(() => {
        if (!storageKey) return 0;
        try { return Number(localStorage.getItem(storageKey)) || 0; } catch { return 0; }
    });
    const limitReached = genCount >= MAX_GENERATIONS;
    const bumpCount = () => {
        // Sem side-effects dentro do updater (evita dupla-escrita em StrictMode).
        // Uma geração = um clique → genCount está fresco aqui.
        const next = genCount + 1;
        setGenCount(next);
        if (storageKey) { try { localStorage.setItem(storageKey, String(next)); } catch { /* ignore */ } }
    };

    // ── Afinação da geração ─────────────────────────────────────────────────
    const [refinements, setRefinements] = useState<string[]>([]);
    const [customInstruction, setCustomInstruction] = useState("");
    const toggleRefinement = (key: string) =>
        setRefinements((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));

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
    const canGenerate = missingFields.length === 0 && Boolean(companyId) && !limitReached;

    // Converte o texto da IA (parágrafos separados por linhas em branco) em HTML
    // para o editor Quill. Fonte única — usada ao "Usar esta descrição".
    const toHtml = (text: string): string =>
        text
            .split(/\n{2,}/)
            .map((p) => `<p>${p.trim()}</p>`)
            .join("") || `<p>${text}</p>`;

    // Pede uma sugestão à IA e mostra-a em pré-visualização — NÃO preenche o campo.
    const requestSuggestion = async () => {
        if (!canGenerate || isGenerating) return;

        setIsGenerating(true);
        // Qualquer chamada à IA consome token → conta já para o limite.
        bumpCount();
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
                hide_price_online:   values.hide_price_online,
                extras:              values.extras ?? [],
                vehicle_attributes:  values.vehicle_attributes,
                // Afinação (opcional) — presets por chave + texto livre.
                refinements:         refinements,
                custom_instruction:  customInstruction.trim() || undefined,
            };

            const response: any = await generateCarDescriptionApi(companyId!, payload);
            const description: string = response?.data?.description ?? response?.description ?? "";

            if (description) {
                setSuggestion(description.trim());   // fica em pré-visualização, à espera de validação
            } else {
                toast.error("A IA não devolveu texto. Tenta novamente.", { position: "top-right", hideProgressBar: true });
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

    // Aprova a sugestão → preenche o campo e fecha a pré-visualização.
    const applySuggestion = () => {
        if (!suggestion) return;
        setFieldValue(fieldName, toHtml(suggestion));
        setSuggestion(null);
        toast.success("Descrição aplicada.", { position: "top-right", hideProgressBar: true });
    };

    return (
        <div className="mt-4">
            <div className="mb-2 border-bottom pb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 className="card-title mb-0">Descrição</h5>

                <div className="d-flex align-items-center gap-2 flex-wrap">
                    {missingFields.length > 0 && (
                        <small className="text-muted">
                            Faltam: {missingFields.join(", ")}
                        </small>
                    )}
                    <span className="badge bg-light text-body" title="Gerações usadas neste veículo">
                        {genCount}/{MAX_GENERATIONS} gerações
                    </span>
                    <XButton
                        size="sm"
                        variant="info"
                        soft
                        rounded
                        icon={<i className="ri-magic-line" />}
                        loading={isGenerating}
                        disabled={!canGenerate || isGenerating}
                        onClick={requestSuggestion}
                    >
                        {isGenerating ? "A gerar..." : "Gerar com IA"}
                    </XButton>
                </div>
            </div>

            {/* Limite atingido — geração desativada, edição manual sempre livre. */}
            {limitReached && (
                <div className="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert">
                    <i className="ri-error-warning-line" />
                    <span>Atingiste o limite de {MAX_GENERATIONS} gerações para este veículo. Usa uma das sugestões ou edita a descrição manualmente.</span>
                </div>
            )}

            {/* Afinação da geração — botões fixos + campo livre. Dirige a próxima
                geração (conta para o limite). Escondido quando o limite foi atingido. */}
            {!limitReached && (
                <div className="border rounded p-3 mb-3">
                    <div className="text-muted fw-semibold fs-12 text-uppercase mb-2" style={{ letterSpacing: "0.04em" }}>
                        Afinar a geração <span className="fw-normal text-lowercase">(opcional)</span>
                    </div>
                    <div className="d-flex flex-wrap gap-2 mb-2">
                        {REFINEMENT_OPTIONS.map((opt) => {
                            const active = refinements.includes(opt.key);
                            return (
                                <button
                                    key={opt.key}
                                    type="button"
                                    className={"btn btn-sm " + (active ? "btn-info" : "btn-soft-info")}
                                    onClick={() => toggleRefinement(opt.key)}
                                >
                                    <i className={opt.icon + " me-1"} />{opt.label}
                                </button>
                            );
                        })}
                    </div>
                    <input
                        type="text"
                        className="form-control form-control-sm"
                        maxLength={300}
                        placeholder='Instrução própria (ex.: "foca na autonomia para viagens longas")'
                        value={customInstruction}
                        onChange={(e) => setCustomInstruction(e.target.value)}
                    />
                </div>
            )}

            {/* Pré-visualização da sugestão — validação humana antes de preencher.
                O campo abaixo só recebe o texto quando o utilizador clica "Usar esta descrição". */}
            {suggestion !== null && (
                <div className="border border-info rounded p-3 mb-3" style={{ background: "var(--vz-info-bg-subtle)" }}>
                    <div className="d-flex align-items-center gap-2 mb-2">
                        <i className="ri-magic-line text-info" />
                        <span className="fw-semibold">Sugestão da IA</span>
                        <span className="badge bg-info-subtle text-info">Pré-visualização — ainda não preenchido</span>
                    </div>
                    <p className="mb-3" style={{ whiteSpace: "pre-wrap" }}>{suggestion}</p>
                    <div className="d-flex flex-wrap gap-2">
                        <XButton
                            size="sm"
                            variant="success"
                            icon={<i className="ri-check-line" />}
                            disabled={isGenerating}
                            onClick={applySuggestion}
                        >
                            Usar esta descrição
                        </XButton>
                        <XButton
                            size="sm"
                            variant="info"
                            soft
                            icon={<i className="ri-refresh-line" />}
                            loading={isGenerating}
                            disabled={isGenerating || limitReached}
                            onClick={requestSuggestion}
                        >
                            {isGenerating ? "A gerar..." : "Gerar outra"}
                        </XButton>
                        {limitReached && (
                            <small className="text-muted align-self-center">Limite de gerações atingido — usa esta ou edita à mão.</small>
                        )}
                        <XButton
                            size="sm"
                            variant="light"
                            disabled={isGenerating}
                            onClick={() => setSuggestion(null)}
                        >
                            Descartar
                        </XButton>
                    </div>
                </div>
            )}

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
