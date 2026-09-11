// React
import { useState } from "react";
import { useDispatch } from "react-redux";
import { Modal, ModalHeader, ModalBody, ModalFooter, Row, Col, Spinner } from "reactstrap";
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";
import { toast } from "react-toastify";
// Components
import XInput from "Components/Common/XInput";
import XButton from "Components/Common/XButton";
// Redux
import { createSupplier } from "slices/suppliers/thunk";
// Models
import { ISupplier } from "common/models/supplier.model";

interface QuickAddSupplierModalProps {
    isOpen: boolean;
    toggle: () => void;
    /** Chamado com o fornecedor criado — usado pela sub-fase das despesas
     *  para seleccionar logo o novo fornecedor no react-select. */
    onCreated?: (supplier: ISupplier) => void;
    /** company_id explícito; se omitido, deriva do authUser. */
    companyId?: number;
    /** Pré-preenche o nome (ex.: o texto que o utilizador escreveu no select). */
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
 * Modal de criação RÁPIDA de fornecedor — isolado e reutilizável.
 * Só o nome é obrigatório. Pensado para ser embebido noutros fluxos
 * (ex.: "adicionar quando não encontra" no react-select das despesas).
 */
export default function QuickAddSupplierModal({
    isOpen,
    toggle,
    onCreated,
    companyId,
    initialName = "",
}: QuickAddSupplierModalProps) {
    const dispatch: any = useDispatch();
    const [saving, setSaving] = useState(false);
    const resolvedCompanyId = resolveCompanyId(companyId);

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: {
            name: initialName,
            nif: "",
            phone: "",
            email: "",
        },
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
                const result: any = await dispatch(
                    createSupplier({
                        companyId: resolvedCompanyId,
                        data: {
                            name: values.name.trim(),
                            nif: values.nif || null,
                            phone: values.phone || null,
                            email: values.email || null,
                            address: null,
                            postal_code: null,
                            district_id: null,
                            municipality_id: null,
                            parish_id: null,
                            iban: null,
                            notes: null,
                        },
                    })
                ).unwrap();

                const created: ISupplier = result?.data;
                toast.success("Fornecedor criado.");
                onCreated?.(created);
                resetForm();
                toggle();
            } catch {
                toast.error("Não foi possível criar o fornecedor.");
            } finally {
                setSaving(false);
            }
        },
    });

    return (
        <Modal isOpen={isOpen} toggle={toggle} centered>
            <ModalHeader toggle={toggle}>Adicionar Fornecedor</ModalHeader>
            <FormikProvider value={formik}>
                <form onSubmit={formik.handleSubmit}>
                    <ModalBody>
                        <Row>
                            <Col lg={12}>
                                <XInput className="mb-2" name="name" label="Nome" placeholder="Nome do fornecedor" required />
                            </Col>
                            <Col lg={6}>
                                <XInput className="mb-2" name="nif" label="NIF" placeholder="Contribuinte" />
                            </Col>
                            <Col lg={6}>
                                <XInput className="mb-2" name="phone" label="Telefone" placeholder="Telefone" />
                            </Col>
                            <Col lg={12}>
                                <XInput type="email" className="mb-2" name="email" label="Email" placeholder="Email" />
                            </Col>
                        </Row>
                    </ModalBody>
                    <ModalFooter>
                        <XButton variant="light" type="button" onClick={toggle}>Cancelar</XButton>
                        <XButton variant="success" type="submit" disabled={saving} icon={saving ? <Spinner size="sm" /> : <i className="ri-check-line" />}>
                            Criar
                        </XButton>
                    </ModalFooter>
                </form>
            </FormikProvider>
        </Modal>
    );
}
