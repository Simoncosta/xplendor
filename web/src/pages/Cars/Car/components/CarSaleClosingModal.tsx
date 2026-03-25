import { useEffect, useMemo, useState } from "react";
import Select from "react-select";
import {
    Badge,
    Col,
    FormFeedback,
    Input,
    Label,
    Modal,
    ModalBody,
    ModalHeader,
    Row,
} from "reactstrap";

import XButton from "Components/Common/XButton";
import { ICarSalePayload } from "common/models/car-sale.model";

type CarSaleClosingModalProps = {
    isOpen: boolean;
    loading?: boolean;
    initialData?: Partial<ICarSalePayload> | null;
    defaultSalePrice?: number | null;
    onClose: () => void;
    onConfirm: (data: ICarSalePayload, mode: "draft" | "submit") => void | Promise<void>;
};

type SaleErrors = Partial<Record<keyof ICarSalePayload, string>>;

const buyerGenderOptions = [
    { value: "male", label: "Homem" },
    { value: "female", label: "Mulher" },
    { value: "company", label: "Empresa" },
];

const buyerAgeRangeOptions = [
    { value: "18-30", label: "18-30" },
    { value: "31-45", label: "31-45" },
    { value: "46-60", label: "46-60" },
    { value: "60+", label: "60+" },
];

const saleChannelOptions = [
    { value: "online", label: "Online" },
    { value: "in_person", label: "Presencial" },
    { value: "referral", label: "Referência" },
    { value: "trade_in", label: "Retoma" },
];

const buildInitialState = (
    initialData?: Partial<ICarSalePayload> | null,
    defaultSalePrice?: number | null
): ICarSalePayload => ({
    sale_price: initialData?.sale_price ?? defaultSalePrice ?? null,
    buyer_gender: initialData?.buyer_gender ?? "",
    buyer_age_range: initialData?.buyer_age_range ?? "",
    sale_channel: initialData?.sale_channel ?? "",
    buyer_name: initialData?.buyer_name ?? "",
    buyer_phone: initialData?.buyer_phone ?? "",
    buyer_email: initialData?.buyer_email ?? "",
    contact_consent: initialData?.contact_consent ?? false,
    notes: initialData?.notes ?? "",
});

export default function CarSaleClosingModal({
    isOpen,
    loading = false,
    initialData,
    defaultSalePrice,
    onClose,
    onConfirm,
}: CarSaleClosingModalProps) {
    const [step, setStep] = useState<1 | 2>(1);
    const [formData, setFormData] = useState<ICarSalePayload>(buildInitialState(initialData, defaultSalePrice));
    const [errors, setErrors] = useState<SaleErrors>({});

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        setStep(1);
        setErrors({});
        setFormData(buildInitialState(initialData, defaultSalePrice));
    }, [isOpen, initialData, defaultSalePrice]);

    const contactVisible = useMemo(() => formData.contact_consent, [formData.contact_consent]);

    const handleChange = <K extends keyof ICarSalePayload>(field: K, value: ICarSalePayload[K]) => {
        setFormData((prev) => ({
            ...prev,
            [field]: value,
        }));

        setErrors((prev) => ({
            ...prev,
            [field]: undefined,
        }));
    };

    const validateStepOne = () => {
        const nextErrors: SaleErrors = {};

        if (formData.sale_price !== null && Number.isNaN(Number(formData.sale_price))) {
            nextErrors.sale_price = "Preço inválido.";
        }

        if (!formData.buyer_gender) {
            nextErrors.buyer_gender = "Seleciona quem comprou.";
        }

        if (!formData.buyer_age_range) {
            nextErrors.buyer_age_range = "Seleciona a faixa etária.";
        }

        if (!formData.sale_channel) {
            nextErrors.sale_channel = "Seleciona o canal da venda.";
        }

        setErrors(nextErrors);

        return Object.keys(nextErrors).length === 0;
    };

    const validateStepTwo = () => {
        const nextErrors: SaleErrors = {};

        if (formData.buyer_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.buyer_email)) {
            nextErrors.buyer_email = "Email inválido.";
        }

        setErrors((prev) => ({
            ...prev,
            ...nextErrors,
        }));

        return Object.keys(nextErrors).length === 0;
    };

    const handleNext = () => {
        if (!validateStepOne()) {
            return;
        }

        setStep(2);
    };

    const buildPayload = (): ICarSalePayload => ({
        ...formData,
        buyer_name: contactVisible ? formData.buyer_name : "",
        buyer_phone: contactVisible ? formData.buyer_phone : "",
        buyer_email: contactVisible ? formData.buyer_email : "",
    });

    const handleConfirm = async (mode: "draft" | "submit") => {
        if (!validateStepOne() || !validateStepTwo()) {
            return;
        }

        await onConfirm(buildPayload(), mode);
    };

    return (
        <Modal isOpen={isOpen} toggle={loading ? undefined : onClose} centered size="lg" fade>
            <ModalHeader toggle={loading ? undefined : onClose}>
                Fecho de venda
            </ModalHeader>
            <ModalBody className="p-4">
                <div className="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
                    <div>
                        <Badge color="light" className="text-success mb-2 border border-success-subtle">
                            Passo {step} de 2
                        </Badge>
                        <h4 className="mb-1">Vamos fechar esta venda com contexto</h4>
                        <p className="text-muted mb-0">
                            São só alguns dados rápidos para alimentar inteligência comercial real.
                        </p>
                    </div>
                    <div className="text-muted small">
                        {step === 1 ? "Dados principais da venda" : "Contacto opcional e notas"}
                    </div>
                </div>

                {step === 1 && (
                    <div className="bg-light rounded-3 p-3 p-lg-4">
                        <Row className="g-3">
                            <Col lg={6}>
                                <Label className="form-label">Por quanto vendeste? <span className="text-muted">(opcional)</span></Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={formData.sale_price ?? ""}
                                    onChange={(e) => handleChange("sale_price", e.target.value === "" ? null : Number(e.target.value))}
                                    invalid={Boolean(errors.sale_price)}
                                />
                                {errors.sale_price && <FormFeedback>{errors.sale_price}</FormFeedback>}
                            </Col>
                            <Col lg={6}>
                                <Label className="form-label">Quem comprou?</Label>
                                <Select
                                    options={buyerGenderOptions}
                                    value={buyerGenderOptions.find((option) => option.value === formData.buyer_gender) ?? null}
                                    onChange={(option: any) => handleChange("buyer_gender", option?.value ?? "")}
                                    classNamePrefix="react-select"
                                />
                                {errors.buyer_gender && <div className="text-danger small mt-1">{errors.buyer_gender}</div>}
                            </Col>
                            <Col lg={6}>
                                <Label className="form-label">Faixa etária aproximada?</Label>
                                <Select
                                    options={buyerAgeRangeOptions}
                                    value={buyerAgeRangeOptions.find((option) => option.value === formData.buyer_age_range) ?? null}
                                    onChange={(option: any) => handleChange("buyer_age_range", option?.value ?? "")}
                                    classNamePrefix="react-select"
                                />
                                {errors.buyer_age_range && <div className="text-danger small mt-1">{errors.buyer_age_range}</div>}
                            </Col>
                            <Col lg={6}>
                                <Label className="form-label">Como chegou ao carro?</Label>
                                <Select
                                    options={saleChannelOptions}
                                    value={saleChannelOptions.find((option) => option.value === formData.sale_channel) ?? null}
                                    onChange={(option: any) => handleChange("sale_channel", option?.value ?? "")}
                                    classNamePrefix="react-select"
                                />
                                {errors.sale_channel && <div className="text-danger small mt-1">{errors.sale_channel}</div>}
                            </Col>
                        </Row>
                    </div>
                )}

                {step === 2 && (
                    <div className="bg-light rounded-3 p-3 p-lg-4">
                        <div className="form-check form-switch mb-3">
                            <Input
                                id="contact_consent"
                                type="switch"
                                role="switch"
                                checked={formData.contact_consent}
                                onChange={(e) => handleChange("contact_consent", e.target.checked)}
                            />
                            <Label check htmlFor="contact_consent">
                                Guardamos o contacto do comprador para futuras oportunidades?
                            </Label>
                        </div>

                        {contactVisible && (
                            <Row className="g-3 mb-3">
                                <Col lg={4}>
                                    <Label className="form-label">Nome</Label>
                                    <Input
                                        value={formData.buyer_name}
                                        onChange={(e) => handleChange("buyer_name", e.target.value)}
                                    />
                                </Col>
                                <Col lg={4}>
                                    <Label className="form-label">Telefone</Label>
                                    <Input
                                        value={formData.buyer_phone}
                                        onChange={(e) => handleChange("buyer_phone", e.target.value)}
                                    />
                                </Col>
                                <Col lg={4}>
                                    <Label className="form-label">Email</Label>
                                    <Input
                                        type="email"
                                        value={formData.buyer_email}
                                        onChange={(e) => handleChange("buyer_email", e.target.value)}
                                        invalid={Boolean(errors.buyer_email)}
                                    />
                                    {errors.buyer_email && <FormFeedback>{errors.buyer_email}</FormFeedback>}
                                </Col>
                            </Row>
                        )}

                        <div>
                            <Label className="form-label">Notas <span className="text-muted">(opcional)</span></Label>
                            <Input
                                type="textarea"
                                rows={4}
                                value={formData.notes}
                                onChange={(e) => handleChange("notes", e.target.value)}
                                placeholder="Ex.: fechou após test-drive, veio por recomendação, retoma envolvida..."
                            />
                        </div>
                    </div>
                )}

                <div className="hstack gap-2 justify-content-end mt-4 flex-wrap">
                    {step === 1 ? (
                        <XButton
                            variant="danger"
                            outline
                            rounded
                            onClick={onClose}
                            disabled={loading}
                        >
                            Cancelar
                        </XButton>
                    ) : (
                        <XButton
                            variant="secondary"
                            outline
                            rounded
                            onClick={() => setStep(1)}
                            disabled={loading}
                        >
                            Voltar
                        </XButton>
                    )}

                    {step === 1 ? (
                        <XButton
                            variant="success"
                            outline
                            rounded
                            onClick={handleNext}
                            disabled={loading}
                        >
                                Continuar
                        </XButton>
                    ) : (
                        <>
                            <XButton
                                variant="success"
                                outline
                                rounded
                                onClick={() => handleConfirm("draft")}
                                disabled={loading}
                            >
                                Só concluir venda
                            </XButton>
                            <XButton
                                variant="success"
                                rounded
                                onClick={() => handleConfirm("submit")}
                                loading={loading}
                                disabled={loading}
                            >
                                {loading ? "A concluir venda e guardar..." : "Concluir venda e guardar alterações"}
                            </XButton>
                        </>
                    )}
                </div>
            </ModalBody>
        </Modal>
    );
}
