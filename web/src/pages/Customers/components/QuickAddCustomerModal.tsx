// React
import { useState } from "react";
import { useDispatch } from "react-redux";
import { Modal, ModalHeader, ModalBody, ModalFooter, Row, Col } from "reactstrap";
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";
import { toast } from "react-toastify";
// Components
import XInput from "Components/Common/XInput";
import XButton from "Components/Common/XButton";
// Redux
import { createCustomer } from "slices/customers/thunk";
// Models
import { ICustomer } from "common/models/customer.model";
import { CUSTOMER_CREATE_DEFAULTS } from "slices/customers/customer.defaults";

interface QuickAddCustomerModalProps {
    isOpen: boolean;
    toggle: () => void;
    onCreated?: (customer: ICustomer) => void;
    companyId?: number;
    initialName?: string;
}

function resolveCompanyId(explicit?: number): number {
    if (explicit) return explicit;
    try {
        const raw = sessionStorage.getItem("authUser");
        return raw ? Number(JSON.parse(raw).company_id || 0) : 0;
    } catch {
        return 0;
    }
}

/**
 * Criação RÁPIDA de cliente — isolado e reutilizável (irmão do QuickAddSupplierModal).
 * Só o nome é obrigatório. Usado no "buscar ou criar" da ficha de venda.
 */
export default function QuickAddCustomerModal({ isOpen, toggle, onCreated, companyId, initialName = "" }: QuickAddCustomerModalProps) {
    const dispatch: any = useDispatch();
    const [saving, setSaving] = useState(false);
    const resolvedCompanyId = resolveCompanyId(companyId);

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: { name: initialName, nif: "", phone: "", email: "" },
        validationSchema: Yup.object({
            name: Yup.string().trim().required("O nome é obrigatório"),
            email: Yup.string().email("Email inválido").nullable(),
        }),
        onSubmit: async (values, { resetForm }) => {
            if (!resolvedCompanyId) {
                toast.error("Empresa não identificada.");
                return;
            }
            setSaving(true);
            try {
                const result: any = await dispatch(createCustomer({
                    companyId: resolvedCompanyId,
                    data: {
                        ...CUSTOMER_CREATE_DEFAULTS,
                        name: values.name.trim(),
                        nif: values.nif || null,
                        phone: values.phone || null,
                        email: values.email || null,
                    },
                })).unwrap();

                const created: ICustomer = result?.data;
                toast.success("Cliente criado.");
                onCreated?.(created);
                resetForm();
                toggle();
            } catch {
                toast.error("Não foi possível criar o cliente.");
            } finally {
                setSaving(false);
            }
        },
    });

    return (
        <Modal isOpen={isOpen} toggle={toggle} centered>
            <ModalHeader toggle={toggle}>Adicionar Cliente</ModalHeader>
            <FormikProvider value={formik}>
                <form onSubmit={formik.handleSubmit}>
                    <ModalBody>
                        <Row>
                            <Col lg={12}><XInput className="mb-2" name="name" label="Nome" placeholder="Nome do cliente" required /></Col>
                            <Col lg={6}><XInput className="mb-2" name="nif" label="NIF" placeholder="Contribuinte" /></Col>
                            <Col lg={6}><XInput className="mb-2" name="phone" label="Telefone" placeholder="Telefone" /></Col>
                            <Col lg={12}><XInput type="email" className="mb-2" name="email" label="Email" placeholder="Email" /></Col>
                        </Row>
                    </ModalBody>
                    <ModalFooter>
                        <XButton variant="light" type="button" onClick={toggle}>Cancelar</XButton>
                        <XButton variant="success" type="submit" loading={saving} icon={<i className="ri-check-line" />}>Criar</XButton>
                    </ModalFooter>
                </form>
            </FormikProvider>
        </Modal>
    );
}
