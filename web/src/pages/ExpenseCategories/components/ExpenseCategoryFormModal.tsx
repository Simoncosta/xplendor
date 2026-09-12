// React
import { useMemo, useState } from "react";
import { useDispatch } from "react-redux";
import { Modal, ModalHeader, ModalBody, ModalFooter, Row, Col, Label } from "reactstrap";
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";
import { toast } from "react-toastify";
// Components
import XInput from "Components/Common/XInput";
import XButton from "Components/Common/XButton";
// Redux
import { createExpenseCategory, updateExpenseCategory } from "slices/expense-categories/thunk";
// Models
import { IExpenseCategory } from "common/models/expense-category.model";

// Paleta sugerida (cores legíveis para os gráficos da 1c.2b). Opcional — a
// categoria pode ficar sem cor.
const COLOR_PRESETS = [
    "var(--vz-primary)", "#0ab39c", "#f7b84b", "#f06548",
    "#299cdb", "#6559cc", "#f672a7", "#4b38b3",
    "#3577f1", "#45cb85", "#ffbe0b", "#e74c3c",
];

interface ExpenseCategoryFormModalProps {
    isOpen: boolean;
    toggle: () => void;
    /** null → criar; objecto → editar. */
    category: IExpenseCategory | null;
    companyId: number;
    onSaved?: () => void;
}

export default function ExpenseCategoryFormModal({ isOpen, toggle, category, companyId, onSaved }: ExpenseCategoryFormModalProps) {
    const dispatch: any = useDispatch();
    const [saving, setSaving] = useState(false);
    const isEdit = Boolean(category);

    const initialValues = useMemo(
        () => ({
            name: category?.name ?? "",
            color: category?.color ?? null as string | null,
        }),
        [category]
    );

    const formik = useFormik({
        enableReinitialize: true,
        initialValues,
        validationSchema: Yup.object({
            name: Yup.string().trim().required("O nome é obrigatório"),
        }),
        onSubmit: async (values) => {
            if (!companyId) {
                toast.error("Empresa não identificada.");
                return;
            }

            const data = { name: values.name.trim(), color: values.color ?? null };

            setSaving(true);
            try {
                if (isEdit && category) {
                    await dispatch(updateExpenseCategory({ companyId, id: category.id, data })).unwrap();
                    toast.success("Categoria atualizada.");
                } else {
                    await dispatch(createExpenseCategory({ companyId, data })).unwrap();
                    toast.success("Categoria criada.");
                }
                onSaved?.();
                toggle();
            } catch {
                toast.error("Não foi possível guardar a categoria.");
            } finally {
                setSaving(false);
            }
        },
    });

    const selectedColor = formik.values.color;

    return (
        <Modal isOpen={isOpen} toggle={toggle} centered>
            <ModalHeader toggle={toggle}>{isEdit ? "Editar Categoria" : "Nova Categoria"}</ModalHeader>
            <FormikProvider value={formik}>
                <form onSubmit={formik.handleSubmit}>
                    <ModalBody>
                        <Row>
                            <Col lg={12}>
                                <XInput className="mb-3" name="name" label="Nome" placeholder="Ex.: Pintura" required />
                            </Col>
                            <Col lg={12}>
                                <Label className="form-label d-block">Cor (opcional)</Label>
                                <div className="d-flex flex-wrap gap-2 align-items-center">
                                    {COLOR_PRESETS.map((c) => (
                                        <button
                                            key={c}
                                            type="button"
                                            onClick={() => formik.setFieldValue("color", c)}
                                            title={c}
                                            className="border-0 rounded-circle"
                                            style={{
                                                width: 26,
                                                height: 26,
                                                backgroundColor: c,
                                                outline: selectedColor === c ? "3px solid rgba(0,0,0,0.25)" : "none",
                                                outlineOffset: 2,
                                                cursor: "pointer",
                                            }}
                                        />
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() => formik.setFieldValue("color", null)}
                                        className={`btn btn-sm ${selectedColor ? "btn-light" : "btn-secondary"}`}
                                    >
                                        Sem cor
                                    </button>
                                </div>
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
