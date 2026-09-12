// React
import { useMemo, useState } from "react";
import { useDispatch } from "react-redux";
import { Modal, ModalHeader, ModalBody, ModalFooter, Row, Col, Label, Input } from "reactstrap";
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";
import { toast } from "react-toastify";
// Components
import XInput from "Components/Common/XInput";
import XInputTextarea from "Components/Common/XInputTextarea";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import XButton from "Components/Common/XButton";
import AddressFields from "Components/Common/AddressFields";
// Redux
import { createCustomer, updateCustomer } from "slices/customers/thunk";
// Models
import { ICustomer, ICustomerPayload } from "common/models/customer.model";
import { CUSTOMER_CREATE_DEFAULTS } from "slices/customers/customer.defaults";

const MARITAL_STATUS_OPTIONS = ["Solteiro(a)", "Casado(a)", "União de facto", "Divorciado(a)", "Viúvo(a)"];

interface CustomerFormModalProps {
    isOpen: boolean;
    toggle: () => void;
    customer: ICustomer | null; // null → criar
    companyId: number;
    onSaved?: () => void;
}

function toPayload(c: ICustomer): ICustomerPayload {
    return {
        name: c.name,
        nif: c.nif,
        phone: c.phone,
        email: c.email,
        address: c.address,
        postal_code: c.postal_code,
        district_id: c.district_id,
        municipality_id: c.municipality_id,
        parish_id: c.parish_id,
        citizen_card_number: c.citizen_card_number,
        citizen_card_validity: c.citizen_card_validity,
        birth_date: c.birth_date,
        nationality: c.nationality,
        profession: c.profession,
        marital_status: c.marital_status,
        contact_consent: c.contact_consent,
        notes: c.notes,
    };
}

export default function CustomerFormModal({ isOpen, toggle, customer, companyId, onSaved }: CustomerFormModalProps) {
    const dispatch: any = useDispatch();
    const [saving, setSaving] = useState(false);
    const isEdit = Boolean(customer);

    const initialValues = useMemo<ICustomerPayload>(
        () => (customer ? toPayload(customer) : { ...CUSTOMER_CREATE_DEFAULTS }),
        [customer]
    );

    const formik = useFormik({
        enableReinitialize: true,
        initialValues,
        validationSchema: Yup.object({
            name: Yup.string().trim().required("O nome é obrigatório"),
            email: Yup.string().email("Email inválido").nullable(),
        }),
        onSubmit: async (values) => {
            if (!companyId) { toast.error("Empresa não identificada."); return; }
            const data: ICustomerPayload = { ...values, name: values.name.trim() };
            setSaving(true);
            try {
                if (isEdit && customer) {
                    await dispatch(updateCustomer({ companyId, id: customer.id, data })).unwrap();
                    toast.success("Cliente atualizado.");
                } else {
                    await dispatch(createCustomer({ companyId, data })).unwrap();
                    toast.success("Cliente criado.");
                }
                onSaved?.();
                toggle();
            } catch {
                toast.error("Não foi possível guardar o cliente.");
            } finally {
                setSaving(false);
            }
        },
    });

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered scrollable>
            <ModalHeader toggle={toggle}>{isEdit ? "Editar Cliente" : "Novo Cliente"}</ModalHeader>
            <FormikProvider value={formik}>
                <form onSubmit={formik.handleSubmit}>
                    <ModalBody>
                        <Row className="g-3">
                            <Col lg={6}><XInput name="name" label="Nome" placeholder="Nome do cliente" required /></Col>
                            <Col lg={3}><XInput name="nif" label="NIF" placeholder="Contribuinte" /></Col>
                            <Col lg={3}><XInput name="phone" label="Telefone" placeholder="Telefone" /></Col>
                            <Col lg={6}><XInput type="email" name="email" label="Email" placeholder="Email" /></Col>

                            {/* Dados legais (documentos de venda). */}
                            <Col lg={3}><XInput name="citizen_card_number" label="Nº Cartão de Cidadão" placeholder="Nº CC" /></Col>
                            <Col lg={3}>
                                <Label className="form-label" htmlFor="citizen_card_validity">Validade CC</Label>
                                <Input type="date" id="citizen_card_validity" value={formik.values.citizen_card_validity ?? ""}
                                    onChange={(e) => formik.setFieldValue("citizen_card_validity", e.target.value || null)} />
                            </Col>
                            <Col lg={3}>
                                <Label className="form-label" htmlFor="birth_date">Data de nascimento</Label>
                                <Input type="date" id="birth_date" value={formik.values.birth_date ?? ""}
                                    onChange={(e) => formik.setFieldValue("birth_date", e.target.value || null)} />
                            </Col>
                            <Col lg={3}><XInput name="nationality" label="Nacionalidade" placeholder="Ex.: Portuguesa" /></Col>
                            <Col lg={3}><XInput name="profession" label="Profissão" placeholder="Ex.: Motorista" /></Col>
                            <Col lg={3}>
                                <Label className="form-label" htmlFor="marital_status">Estado civil</Label>
                                <Input type="select" id="marital_status" value={formik.values.marital_status ?? ""}
                                    onChange={(e) => formik.setFieldValue("marital_status", e.target.value || null)}>
                                    <option value="">—</option>
                                    {MARITAL_STATUS_OPTIONS.map((o) => <option key={o} value={o}>{o}</option>)}
                                </Input>
                            </Col>
                        </Row>

                        {/* Morada partilhada. */}
                        <AddressFields />

                        <Row className="g-3">
                            <Col lg={12} className="pt-1">
                                <XInputCheckbox name="contact_consent" label="Cliente deu consentimento para contacto (RGPD)" />
                                <small className="text-muted d-block">Sem consentimento, o telefone e email não são guardados.</small>
                            </Col>
                            <Col lg={12}><XInputTextarea name="notes" label="Observações" rows={2} placeholder="Notas internas" /></Col>
                        </Row>
                    </ModalBody>
                    <ModalFooter>
                        <XButton variant="light" type="button" onClick={toggle}>Cancelar</XButton>
                        <XButton variant="success" type="submit" loading={saving} icon={<i className="ri-check-line" />}>
                            {isEdit ? "Guardar" : "Criar"}
                        </XButton>
                    </ModalFooter>
                </form>
            </FormikProvider>
        </Modal>
    );
}
