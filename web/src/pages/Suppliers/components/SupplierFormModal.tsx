// React
import { useMemo, useState } from "react";
import { useDispatch } from "react-redux";
import { Modal, ModalHeader, ModalBody, ModalFooter, Row, Col } from "reactstrap";
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";
import { toast } from "react-toastify";
// Components
import XInput from "Components/Common/XInput";
import XInputTextarea from "Components/Common/XInputTextarea";
import XButton from "Components/Common/XButton";
import AddressFields from "Components/Common/AddressFields";
// Redux
import { createSupplier, updateSupplier } from "slices/suppliers/thunk";
// Models
import { ISupplier, ISupplierPayload } from "common/models/supplier.model";
import { SUPPLIER_CREATE_DEFAULTS } from "slices/suppliers/supplier.defaults";

interface SupplierFormModalProps {
    isOpen: boolean;
    toggle: () => void;
    /** null → criar; objecto → editar. */
    supplier: ISupplier | null;
    companyId?: number;
    onSaved?: () => void;
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

function toPayload(supplier: ISupplier): ISupplierPayload {
    return {
        name: supplier.name,
        nif: supplier.nif,
        phone: supplier.phone,
        email: supplier.email,
        address: supplier.address,
        postal_code: supplier.postal_code,
        district_id: supplier.district_id,
        municipality_id: supplier.municipality_id,
        parish_id: supplier.parish_id,
        iban: supplier.iban,
        notes: supplier.notes,
    };
}

/** Modal completo de criação/edição de fornecedor (inclui morada partilhada). */
export default function SupplierFormModal({ isOpen, toggle, supplier, companyId, onSaved }: SupplierFormModalProps) {
    const dispatch: any = useDispatch();
    const [saving, setSaving] = useState(false);
    const resolvedCompanyId = resolveCompanyId(companyId);
    const isEdit = Boolean(supplier);

    const initialValues = useMemo<ISupplierPayload>(
        () => (supplier ? toPayload(supplier) : { ...SUPPLIER_CREATE_DEFAULTS }),
        [supplier]
    );

    const formik = useFormik({
        enableReinitialize: true,
        initialValues,
        validationSchema: Yup.object({
            name: Yup.string().trim().required("O nome é obrigatório"),
            email: Yup.string().email("Email inválido").nullable(),
        }),
        onSubmit: async (values) => {
            if (!resolvedCompanyId) {
                toast.error("Empresa não identificada.");
                return;
            }

            const data: ISupplierPayload = {
                ...values,
                name: values.name.trim(),
            };

            setSaving(true);
            try {
                if (isEdit && supplier) {
                    await dispatch(updateSupplier({ companyId: resolvedCompanyId, id: supplier.id, data })).unwrap();
                    toast.success("Fornecedor atualizado.");
                } else {
                    await dispatch(createSupplier({ companyId: resolvedCompanyId, data })).unwrap();
                    toast.success("Fornecedor criado.");
                }
                onSaved?.();
                toggle();
            } catch {
                toast.error("Não foi possível guardar o fornecedor.");
            } finally {
                setSaving(false);
            }
        },
    });

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered scrollable>
            <ModalHeader toggle={toggle}>{isEdit ? "Editar Fornecedor" : "Novo Fornecedor"}</ModalHeader>
            <FormikProvider value={formik}>
                <form onSubmit={formik.handleSubmit}>
                    <ModalBody>
                        <Row>
                            <Col lg={6}>
                                <XInput className="mb-2" name="name" label="Nome" placeholder="Nome do fornecedor" required />
                            </Col>
                            <Col lg={3}>
                                <XInput className="mb-2" name="nif" label="NIF" placeholder="Contribuinte" />
                            </Col>
                            <Col lg={3}>
                                <XInput className="mb-2" name="phone" label="Telefone" placeholder="Telefone" />
                            </Col>
                            <Col lg={6}>
                                <XInput type="email" className="mb-2" name="email" label="Email" placeholder="Email" />
                            </Col>
                            <Col lg={6}>
                                <XInput className="mb-2" name="iban" label="IBAN" placeholder="PT50 ..." />
                            </Col>
                        </Row>

                        {/* Morada partilhada — mesmo componente da config da empresa. */}
                        <AddressFields />

                        <Row>
                            <Col lg={12}>
                                <XInputTextarea className="mb-2" name="notes" label="Observações" rows={3} placeholder="Notas internas" />
                            </Col>
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
