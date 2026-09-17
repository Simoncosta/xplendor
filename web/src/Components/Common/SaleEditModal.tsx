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
import CreatableSelect from "react-select/creatable";
import { updateCarSale } from "slices/car-sales/thunk";
import { getCustomers, getSaleLeadMatch, linkSaleLead } from "helpers/laravel_helper";
import QuickAddCustomerModal from "pages/Customers/components/QuickAddCustomerModal";
import PostSaleLeadPrompt, { type LeadCandidate } from "Components/Common/PostSaleLeadPrompt";
import ValidationAlert from "Components/Common/ValidationAlert";
import {
    parseApiValidationErrors,
    showApiErrorToast,
    type ApiValidationError,
} from "helpers/error_helper";
import type { CarSpecsSale } from "types/api";
import type { ICustomer } from "common/models/customer.model";

interface CustomerOption { value: number; label: string; }

// Menu do react-select num portal → não deforma o modal (padrão das despesas).
const SELECT_PORTAL = {
    menuPortalTarget: typeof document !== "undefined" ? document.body : undefined,
    menuPosition: "fixed" as const,
    styles: { menuPortal: (base: any) => ({ ...base, zIndex: 9999 }) },
};

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

// Sim/Não tri-estado ("" = não registado).
const TRI_OPTIONS = [
    { value: "",    label: "(não registado)" },
    { value: "yes", label: "Sim" },
    { value: "no",  label: "Não" },
];

interface SaleEditModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSaved: () => void;          // refrescar specs depois de gravar
    companyId: number;
    carId: number;
    initial: CarSpecsSale | null; // null = criar
}

// Booleans tri-estado no formulário: "" = (não registado/null), "yes"/"no".
type Tri = "" | "yes" | "no";
const boolToTri = (b?: boolean | null): Tri => (b === true ? "yes" : b === false ? "no" : "");
const triToBool = (t: Tri): boolean | null => (t === "yes" ? true : t === "no" ? false : null);
const numOrNull = (s: string): number | null => (s.trim() === "" ? null : Number(s));

interface FormState {
    customer_id: number | null;   // DMS — cliente da venda
    buyer_name: string;
    buyer_phone: string;
    buyer_email: string;
    sale_price: string;           // string para tolerar input numérico vazio
    sale_channel: string;
    buyer_gender: string;
    buyer_age_range: string;
    contact_consent: boolean;
    notes: string;
    // Fase 1 — registo de venda enriquecido.
    advertised_price: string;
    discount_amount: string;
    offers: string;
    has_financing: Tri;
    financing_entity: string;
    financed_amount: string;
    has_trade_in: Tri;
    trade_in_vehicle: string;
    trade_in_value: string;
    first_motorhome: Tri;
    previous_vehicle: string;
}

const emptyForm: FormState = {
    customer_id: null,
    buyer_name: "",
    buyer_phone: "",
    buyer_email: "",
    sale_price: "",
    sale_channel: "",
    buyer_gender: "",
    buyer_age_range: "",
    contact_consent: false,
    notes: "",
    advertised_price: "",
    discount_amount: "",
    offers: "",
    has_financing: "",
    financing_entity: "",
    financed_amount: "",
    has_trade_in: "",
    trade_in_vehicle: "",
    trade_in_value: "",
    first_motorhome: "",
    previous_vehicle: "",
};

const fromInitial = (s: CarSpecsSale | null): FormState => {
    if (!s) return emptyForm;
    return {
        customer_id:     s.customer_id ?? null,
        buyer_name:      s.buyer_name ?? "",
        buyer_phone:     s.buyer_phone ?? "",
        buyer_email:     s.buyer_email ?? "",
        sale_price:      s.sale_price != null ? String(s.sale_price) : "",
        sale_channel:    s.sale_channel ?? "",
        buyer_gender:    s.buyer_gender ?? "",
        buyer_age_range: s.buyer_age_range ?? "",
        contact_consent: !!s.contact_consent,
        notes:           s.notes ?? "",
        advertised_price: s.advertised_price != null ? String(s.advertised_price) : "",
        discount_amount:  s.discount_amount != null ? String(s.discount_amount) : "",
        offers:           s.offers ?? "",
        has_financing:    boolToTri(s.has_financing),
        financing_entity: s.financing_entity ?? "",
        financed_amount:  s.financed_amount != null ? String(s.financed_amount) : "",
        has_trade_in:     boolToTri(s.has_trade_in),
        trade_in_vehicle: s.trade_in_vehicle ?? "",
        trade_in_value:   s.trade_in_value != null ? String(s.trade_in_value) : "",
        first_motorhome:  boolToTri(s.first_motorhome),
        previous_vehicle: s.previous_vehicle ?? "",
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
    const [customers, setCustomers] = useState<ICustomer[]>([]);
    const [quickCustomerName, setQuickCustomerName] = useState<string | null>(null);
    // Fase 2 — CRM: leads abertas do cliente detetadas após gravar a venda.
    const [leadCandidates, setLeadCandidates] = useState<LeadCandidate[]>([]);
    const [linkingLead, setLinkingLead] = useState(false);

    // Sempre que o modal abre, pré-preenche com os valores actuais + carrega clientes.
    useEffect(() => {
        if (isOpen) {
            setForm(fromInitial(initial));
            setErrors(null);
            getCustomers(companyId, { only_active: 1 })
                .then((res: any) => setCustomers((res?.data as ICustomer[]) ?? []))
                .catch(() => setCustomers([]));
        }
    }, [isOpen, initial, companyId]);

    const customerOptions: CustomerOption[] = customers.map((c) => ({ value: c.id, label: c.name }));
    const selectedCustomer = customerOptions.find((o) => o.value === form.customer_id) ?? null;

    // Seleccionar cliente → guarda customer_id e pré-preenche o snapshot buyer_*.
    const pickCustomer = (c: ICustomer | null) => {
        setForm((prev) => ({
            ...prev,
            customer_id: c?.id ?? null,
            buyer_name: c ? c.name : prev.buyer_name,
            buyer_phone: c && c.phone ? c.phone : prev.buyer_phone,
            buyer_email: c && c.email ? c.email : prev.buyer_email,
        }));
    };

    const handleCustomerCreated = (c: ICustomer) => {
        setCustomers((prev) => [...prev, c]);
        pickCustomer(c);
        setQuickCustomerName(null);
    };

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
            customer_id:     form.customer_id,
            buyer_name:      form.buyer_name.trim() || null,
            buyer_phone:     form.buyer_phone.trim() || null,
            buyer_email:     form.buyer_email.trim() || null,
            sale_price:      form.sale_price === "" ? null : Number(form.sale_price),
            sale_channel:    form.sale_channel || null,
            buyer_gender:    form.buyer_gender || null,
            buyer_age_range: form.buyer_age_range || null,
            contact_consent: !!form.contact_consent,
            notes:           form.notes.trim() || null,
            // Fase 1 — registo de venda enriquecido.
            advertised_price: numOrNull(form.advertised_price),
            discount_amount:  numOrNull(form.discount_amount),
            offers:           form.offers.trim() || null,
            has_financing:    triToBool(form.has_financing),
            financing_entity: form.financing_entity.trim() || null,
            financed_amount:  numOrNull(form.financed_amount),
            has_trade_in:     triToBool(form.has_trade_in),
            trade_in_vehicle: form.trade_in_vehicle.trim() || null,
            trade_in_value:   numOrNull(form.trade_in_value),
            first_motorhome:  triToBool(form.first_motorhome),
            previous_vehicle: form.previous_vehicle.trim() || null,
        };

        try {
            await dispatch(updateCarSale({ companyId, carId, data: payload })).unwrap();
            toast("Dados do comprador actualizados.", { position: "top-right", hideProgressBar: false, className: "bg-success text-white" });
            onSaved();

            // Fase 2 — CRM: detetar lead aberta do cliente para propor mover ao funil.
            // Só pergunta se houver candidata; nunca move sozinho. Falha de deteção
            // não bloqueia o fecho da venda.
            try {
                const r: any = await getSaleLeadMatch(companyId, carId);
                const cands: LeadCandidate[] = r?.data?.candidates ?? [];
                if (cands.length > 0) {
                    setLeadCandidates(cands);
                    setSaving(false);
                    return; // mantém o modal; o prompt trata do resto
                }
            } catch { /* deteção é best-effort */ }

            onClose();
        } catch (err) {
            setErrors(parseApiValidationErrors(err));
            showApiErrorToast(err, "Erro ao actualizar dados da venda.");
        } finally {
            setSaving(false);
        }
    };

    // Confirmação do prompt: mover a lead escolhida para "Venda" no funil.
    const confirmMoveLead = async (leadId: number) => {
        setLinkingLead(true);
        try {
            await linkSaleLead(companyId, carId, leadId);
            toast("Lead movida para 'Venda' no funil.", { position: "top-right", className: "bg-success text-white" });
            onSaved();
        } catch (err) {
            showApiErrorToast(err, "Não foi possível mover a lead.");
        } finally {
            setLinkingLead(false);
            setLeadCandidates([]);
            onClose();
        }
    };

    const dismissLeadPrompt = () => { setLeadCandidates([]); onClose(); };

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
                        <div className="col-12">
                            <FormGroup className="mb-0">
                                <Label>Cliente</Label>
                                <CreatableSelect
                                    isClearable
                                    placeholder="Procurar ou adicionar cliente…"
                                    formatCreateLabel={(input: string) => `Adicionar "${input}"`}
                                    options={customerOptions}
                                    value={selectedCustomer}
                                    onChange={(opt: CustomerOption | null) => {
                                        const c = opt ? customers.find((x) => x.id === opt.value) ?? null : null;
                                        pickCustomer(c);
                                    }}
                                    onCreateOption={(input: string) => setQuickCustomerName(input)}
                                    classNamePrefix="react-select"
                                    {...SELECT_PORTAL}
                                />
                                <small className="text-muted">
                                    Liga a venda a um cliente. Os campos abaixo são o registo da venda.
                                </small>
                            </FormGroup>
                        </div>
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

                        {/* ── Registo de venda enriquecido (Fase 1) ─────────── */}
                        <div className="col-12">
                            <hr className="my-1" />
                            <h6 className="text-uppercase text-muted fs-12 mb-0">Registo da venda</h6>
                        </div>

                        {/* Origem herdada da lead ligada (só leitura; Fase 2 popula) */}
                        {initial?.lead_origin && (
                            <div className="col-12">
                                <div className="p-2 rounded" style={{ background: "var(--vz-tertiary-bg)", border: "1px dashed var(--vz-border-color)", fontSize: 12 }}>
                                    <i className="ri-global-line me-1" />
                                    <span className="text-muted">Origem (da lead): </span>
                                    <span className="fw-medium">
                                        {[initial.lead_origin.channel, initial.lead_origin.utm_source, initial.lead_origin.utm_campaign].filter(Boolean).join(" · ") || "—"}
                                    </span>
                                </div>
                            </div>
                        )}

                        <div className="col-md-6">
                            <FormGroup className="mb-0">
                                <Label for="advertised-price">Valor anunciado (€)</Label>
                                <Input id="advertised-price" type="number" step="0.01" min="0" value={form.advertised_price} onChange={(e) => setField("advertised_price", e.target.value)} />
                            </FormGroup>
                        </div>
                        <div className="col-md-6">
                            <FormGroup className="mb-0">
                                <Label for="discount-amount">Desconto (€)</Label>
                                <Input id="discount-amount" type="number" step="0.01" min="0" value={form.discount_amount} onChange={(e) => setField("discount_amount", e.target.value)} />
                            </FormGroup>
                        </div>
                        <div className="col-12">
                            <FormGroup className="mb-0">
                                <Label for="offers">Ofertas incluídas</Label>
                                <Input id="offers" type="text" value={form.offers} onChange={(e) => setField("offers", e.target.value)} placeholder="Ex.: tapetes, revisão, jogo de pneus…" />
                            </FormGroup>
                        </div>

                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="has-financing">Financiamento</Label>
                                <Input id="has-financing" type="select" value={form.has_financing} onChange={(e) => setField("has_financing", e.target.value as Tri)}>
                                    {TRI_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                </Input>
                            </FormGroup>
                        </div>
                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="financing-entity">Entidade financeira</Label>
                                <Input id="financing-entity" type="text" value={form.financing_entity} onChange={(e) => setField("financing_entity", e.target.value)} disabled={form.has_financing !== "yes"} />
                            </FormGroup>
                        </div>
                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="financed-amount">Valor financiado (€)</Label>
                                <Input id="financed-amount" type="number" step="0.01" min="0" value={form.financed_amount} onChange={(e) => setField("financed_amount", e.target.value)} disabled={form.has_financing !== "yes"} />
                            </FormGroup>
                        </div>

                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="has-trade-in">Retoma</Label>
                                <Input id="has-trade-in" type="select" value={form.has_trade_in} onChange={(e) => setField("has_trade_in", e.target.value as Tri)}>
                                    {TRI_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                </Input>
                            </FormGroup>
                        </div>
                        <div className="col-md-5">
                            <FormGroup className="mb-0">
                                <Label for="trade-in-vehicle">Veículo de retoma</Label>
                                <Input id="trade-in-vehicle" type="text" value={form.trade_in_vehicle} onChange={(e) => setField("trade_in_vehicle", e.target.value)} placeholder="Marca/modelo · matrícula" disabled={form.has_trade_in !== "yes"} />
                            </FormGroup>
                        </div>
                        <div className="col-md-3">
                            <FormGroup className="mb-0">
                                <Label for="trade-in-value">Valor retoma (€)</Label>
                                <Input id="trade-in-value" type="number" step="0.01" min="0" value={form.trade_in_value} onChange={(e) => setField("trade_in_value", e.target.value)} disabled={form.has_trade_in !== "yes"} />
                            </FormGroup>
                        </div>

                        <div className="col-md-4">
                            <FormGroup className="mb-0">
                                <Label for="first-motorhome">Primeira autocaravana</Label>
                                <Input id="first-motorhome" type="select" value={form.first_motorhome} onChange={(e) => setField("first_motorhome", e.target.value as Tri)}>
                                    {TRI_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                </Input>
                            </FormGroup>
                        </div>
                        <div className="col-md-8">
                            <FormGroup className="mb-0">
                                <Label for="previous-vehicle">Veículo anterior</Label>
                                <Input id="previous-vehicle" type="text" value={form.previous_vehicle} onChange={(e) => setField("previous_vehicle", e.target.value)} placeholder="O que o cliente tinha antes (opcional)" />
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

            {/* "Buscar ou criar" cliente — reutiliza o modal de criação rápida. */}
            <QuickAddCustomerModal
                isOpen={quickCustomerName !== null}
                toggle={() => setQuickCustomerName(null)}
                companyId={companyId}
                initialName={quickCustomerName ?? ""}
                onCreated={handleCustomerCreated}
            />

            {/* Fase 2 — CRM: propor mover a lead do cliente para "Venda" no funil. */}
            <PostSaleLeadPrompt
                isOpen={leadCandidates.length > 0}
                candidates={leadCandidates}
                saving={linkingLead}
                onConfirm={confirmMoveLead}
                onCancel={dismissLeadPrompt}
            />
        </Modal>
    );
}
