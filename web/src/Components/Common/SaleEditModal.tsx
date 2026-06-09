import React, { useEffect, useState } from "react";
import {
    Button,
    Form,
    FormGroup,
    Input,
    Label,
    Modal,
    ModalBody,
    ModalFooter,
    ModalHeader,
} from "reactstrap";
import { useDispatch } from "react-redux";
import { toast } from "react-toastify";
import { updateCarSale } from "slices/car-sales/thunk";
import ValidationAlert from "Components/Common/ValidationAlert";
import {
    parseApiValidationErrors,
    showApiErrorToast,
    type ApiValidationError,
} from "helpers/error_helper";
import type { CarSpecsSale } from "types/api";

// Mesmas enums e labels que o SaleInfoCard usa para apresentação. Mantidas
// locais ao módulo de venda (não vão para helpers/labels.ts global).
const SALE_CHANNEL_OPTIONS = [
    { value: "",            label: "(sem canal)" },
    { value: "online",      label: "Online" },
    { value: "in_person",   label: "Presencial" },
    { value: "referral",    label: "Referência" },
    { value: "trade_in",    label: "Retoma" },
];

const BUYER_GENDER_OPTIONS = [
    { value: "",        label: "(não definido)" },
    { value: "male",    label: "Masculino" },
    { value: "female",  label: "Feminino" },
    { value: "company", label: "Empresa" },
];

const BUYER_AGE_RANGE_OPTIONS = [
    { value: "",      label: "(não definida)" },
    { value: "18-30", label: "18 a 30 anos" },
    { value: "31-45", label: "31 a 45 anos" },
    { value: "46-60", label: "46 a 60 anos" },
    { value: "60+",   label: "Mais de 60 anos" },
];

interface SaleEditModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSaved: () => void;          // refrescar specs depois de gravar
    companyId: number;
    carId: number;
    initial: CarSpecsSale | null; // null = criar
}

interface FormState {
    buyer_name: string;
    buyer_phone: string;
    buyer_email: string;
    sale_price: string;           // string para tolerar input numérico vazio
    sale_channel: string;
    buyer_gender: string;
    buyer_age_range: string;
    contact_consent: boolean;
    notes: string;
}

const emptyForm: FormState = {
    buyer_name: "",
    buyer_phone: "",
    buyer_email: "",
    sale_price: "",
    sale_channel: "",
    buyer_gender: "",
    buyer_age_range: "",
    contact_consent: false,
    notes: "",
};

const fromInitial = (s: CarSpecsSale | null): FormState => {
    if (!s) return emptyForm;
    return {
        buyer_name:      s.buyer_name ?? "",
        buyer_phone:     s.buyer_phone ?? "",
        buyer_email:     s.buyer_email ?? "",
        sale_price:      s.sale_price != null ? String(s.sale_price) : "",
        sale_channel:    s.sale_channel ?? "",
        buyer_gender:    s.buyer_gender ?? "",
        buyer_age_range: s.buyer_age_range ?? "",
        contact_consent: !!s.contact_consent,
        notes:           s.notes ?? "",
    };
};

export default function SaleEditModal({
    isOpen,
    onClose,
    onSaved,
    companyId,
    carId,
    initial,
}: SaleEditModalProps) {
    const dispatch: any = useDispatch();
    const [form, setForm] = useState<FormState>(emptyForm);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<ApiValidationError[] | null>(null);

    // Sempre que o modal abre, pré-preenche com os valores actuais.
    useEffect(() => {
        if (isOpen) {
            setForm(fromInitial(initial));
            setErrors(null);
        }
    }, [isOpen, initial]);

    const setField = <K extends keyof FormState>(key: K, value: FormState[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (saving) return;
        setSaving(true);
        setErrors(null);

        // Converte strings vazias em null para campos opcionais e parse de
        // sale_price. O backend valida tudo com a UpdateCarSaleRequest.
        const payload: Record<string, unknown> = {
            buyer_name:      form.buyer_name.trim() || null,
            buyer_phone:     form.buyer_phone.trim() || null,
            buyer_email:     form.buyer_email.trim() || null,
            sale_price:      form.sale_price === "" ? null : Number(form.sale_price),
            sale_channel:    form.sale_channel || null,
            buyer_gender:    form.buyer_gender || null,
            buyer_age_range: form.buyer_age_range || null,
            contact_consent: !!form.contact_consent,
            notes:           form.notes.trim() || null,
        };

        try {
            await dispatch(updateCarSale({ companyId, carId, data: payload })).unwrap();
            toast("Dados do comprador actualizados.", { position: "top-right", hideProgressBar: false, className: "bg-success text-white" });
            onSaved();
            onClose();
        } catch (err) {
            setErrors(parseApiValidationErrors(err));
            showApiErrorToast(err, "Erro ao actualizar dados da venda.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={onClose} centered size="lg" backdrop="static">
            <ModalHeader toggle={onClose}>
                <i className="ri-edit-2-line me-2 text-success" />
                Editar dados do comprador
            </ModalHeader>
            <Form onSubmit={handleSubmit}>
                <ModalBody>
                    <ValidationAlert errors={errors} onDismiss={() => setErrors(null)} />

                    <div className="row g-3">
                        <div className="col-md-6">
                            <FormGroup className="mb-0">
                                <Label for="sale-buyer-name">Nome do comprador</Label>
                                <Input
                                    id="sale-buyer-name"
                                    type="text"
                                    value={form.buyer_name}
                                    onChange={(e) => setField("buyer_name", e.target.value)}
                                />
                            </FormGroup>
                        </div>
                        <div className="col-md-6">
                            <FormGroup className="mb-0">
                                <Label for="sale-buyer-phone">Telefone</Label>
                                <Input
                                    id="sale-buyer-phone"
                                    type="text"
                                    value={form.buyer_phone}
                                    onChange={(e) => setField("buyer_phone", e.target.value)}
                                />
                            </FormGroup>
                        </div>
                        <div className="col-md-6">
                            <FormGroup className="mb-0">
                                <Label for="sale-buyer-email">Email</Label>
                                <Input
                                    id="sale-buyer-email"
                                    type="email"
                                    value={form.buyer_email}
                                    onChange={(e) => setField("buyer_email", e.target.value)}
                                />
                            </FormGroup>
                        </div>
                        <div className="col-md-6">
                            <FormGroup className="mb-0">
                                <Label for="sale-price">Preço de venda (€)</Label>
                                <Input
                                    id="sale-price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.sale_price}
                                    onChange={(e) => setField("sale_price", e.target.value)}
                                />
                            </FormGroup>
                        </div>
                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="sale-channel">Canal de venda</Label>
                                <Input
                                    id="sale-channel"
                                    type="select"
                                    value={form.sale_channel}
                                    onChange={(e) => setField("sale_channel", e.target.value)}
                                >
                                    {SALE_CHANNEL_OPTIONS.map((o) => (
                                        <option key={o.value} value={o.value}>{o.label}</option>
                                    ))}
                                </Input>
                            </FormGroup>
                        </div>
                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="buyer-gender">Género</Label>
                                <Input
                                    id="buyer-gender"
                                    type="select"
                                    value={form.buyer_gender}
                                    onChange={(e) => setField("buyer_gender", e.target.value)}
                                >
                                    {BUYER_GENDER_OPTIONS.map((o) => (
                                        <option key={o.value} value={o.value}>{o.label}</option>
                                    ))}
                                </Input>
                            </FormGroup>
                        </div>
                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="buyer-age-range">Faixa etária</Label>
                                <Input
                                    id="buyer-age-range"
                                    type="select"
                                    value={form.buyer_age_range}
                                    onChange={(e) => setField("buyer_age_range", e.target.value)}
                                >
                                    {BUYER_AGE_RANGE_OPTIONS.map((o) => (
                                        <option key={o.value} value={o.value}>{o.label}</option>
                                    ))}
                                </Input>
                            </FormGroup>
                        </div>
                        <div className="col-12">
                            <FormGroup check className="mb-0 mt-1">
                                <Input
                                    id="contact-consent"
                                    type="checkbox"
                                    checked={form.contact_consent}
                                    onChange={(e) => setField("contact_consent", e.target.checked)}
                                />
                                <Label check for="contact-consent" className="ms-1">
                                    Comprador deu consentimento para contacto futuro
                                </Label>
                            </FormGroup>
                        </div>
                        <div className="col-12">
                            <FormGroup className="mb-0">
                                <Label for="sale-notes">Notas</Label>
                                <Input
                                    id="sale-notes"
                                    type="textarea"
                                    rows={4}
                                    value={form.notes}
                                    onChange={(e) => setField("notes", e.target.value)}
                                    placeholder="Observações internas sobre a venda ou o comprador."
                                />
                            </FormGroup>
                        </div>
                    </div>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" type="button" onClick={onClose} disabled={saving}>
                        Cancelar
                    </Button>
                    <Button color="success" type="submit" disabled={saving}>
                        {saving ? "A gravar..." : "Gravar alterações"}
                    </Button>
                </ModalFooter>
            </Form>
        </Modal>
    );
}
