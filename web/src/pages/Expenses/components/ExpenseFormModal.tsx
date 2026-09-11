// React
import { useEffect, useMemo, useState } from "react";
import { useDispatch } from "react-redux";
import { Modal, ModalHeader, ModalBody, ModalFooter, Row, Col, Label, Input, FormFeedback } from "reactstrap";
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";
import { toast } from "react-toastify";
import Select from "react-select";
import CreatableSelect from "react-select/creatable";
// Components
import XInput from "Components/Common/XInput";
import XInputTextarea from "Components/Common/XInputTextarea";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import XButton from "Components/Common/XButton";
import QuickAddSupplierModal from "pages/Suppliers/components/QuickAddSupplierModal";
// Redux / helpers
import { createExpense, updateExpense } from "slices/expenses/thunk";
import { getExpenseCategories, getSuppliers } from "helpers/laravel_helper";
// Models
import { IExpense, IExpensePayload } from "common/models/expense.model";
import { IExpenseCategory } from "common/models/expense-category.model";
import { ISupplier } from "common/models/supplier.model";

export interface CarOption {
    value: number;
    label: string;
}

interface Option {
    value: number;
    label: string;
}

interface ExpenseFormModalProps {
    isOpen: boolean;
    toggle: () => void;
    /** null → criar; objecto → editar. */
    expense: IExpense | null;
    companyId: number;
    /** Contexto da Ficha: fixa o car_id e esconde o select de viatura. */
    fixedCarId?: number;
    /** Opções de viatura para a tela geral (id + label). */
    carOptions?: CarOption[];
    onSaved?: () => void;
}

const todayStr = () => new Date().toISOString().slice(0, 10);

// BUG 5 — dentro de um Modal (scrollable), o menu do react-select expandia o
// modal. Renderizar o menu num portal para o body faz o dropdown flutuar POR
// CIMA sem deformar o modal.
const SELECT_PORTAL = {
    menuPortalTarget: typeof document !== "undefined" ? document.body : undefined,
    menuPosition: "fixed" as const,
    styles: { menuPortal: (base: any) => ({ ...base, zIndex: 9999 }) },
};

export default function ExpenseFormModal({ isOpen, toggle, expense, companyId, fixedCarId, carOptions = [], onSaved }: ExpenseFormModalProps) {
    const dispatch: any = useDispatch();
    const [saving, setSaving] = useState(false);
    const isEdit = Boolean(expense);

    const [categories, setCategories] = useState<IExpenseCategory[]>([]);
    const [suppliers, setSuppliers] = useState<ISupplier[]>([]);
    const [quickSupplierName, setQuickSupplierName] = useState<string | null>(null);

    // Carrega categorias e fornecedores activos quando o modal abre.
    useEffect(() => {
        if (!isOpen || !companyId) return;

        getExpenseCategories(companyId, { only_active: 1 })
            .then((res: any) => setCategories((res?.data as IExpenseCategory[]) ?? []))
            .catch(() => setCategories([]));

        getSuppliers(companyId, { only_active: 1 })
            .then((res: any) => setSuppliers((res?.data as ISupplier[]) ?? []))
            .catch(() => setSuppliers([]));
    }, [isOpen, companyId]);

    const initialValues = useMemo<IExpensePayload>(() => ({
        description: expense?.description ?? "",
        amount: expense?.amount ?? null,
        date: expense?.date ?? todayStr(),
        expense_category_id: expense?.expense_category_id ?? null,
        supplier_id: expense?.supplier_id ?? null,
        car_id: fixedCarId ?? expense?.car_id ?? null,
        is_paid: expense?.is_paid ?? false,
        paid_at: expense?.paid_at ?? null,
        notes: expense?.notes ?? null,
    }), [expense, fixedCarId]);

    const formik = useFormik({
        enableReinitialize: true,
        initialValues,
        validationSchema: Yup.object({
            description: Yup.string().trim().required("A descrição é obrigatória"),
            amount: Yup.number().typeError("Valor inválido").min(0, "Não pode ser negativo").required("O valor é obrigatório"),
            date: Yup.string().required("A data é obrigatória"),
        }),
        onSubmit: async (values) => {
            if (!companyId) {
                toast.error("Empresa não identificada.");
                return;
            }

            const data: IExpensePayload = {
                ...values,
                description: values.description.trim(),
                car_id: fixedCarId ?? values.car_id ?? null,
            };

            setSaving(true);
            try {
                if (isEdit && expense) {
                    await dispatch(updateExpense({ companyId, id: expense.id, data })).unwrap();
                    toast.success("Despesa atualizada.");
                } else {
                    await dispatch(createExpense({ companyId, data })).unwrap();
                    toast.success("Despesa criada.");
                }
                onSaved?.();
                toggle();
            } catch {
                toast.error("Não foi possível guardar a despesa.");
            } finally {
                setSaving(false);
            }
        },
    });

    const categoryOptions: Option[] = categories.map((c) => ({ value: c.id, label: c.name }));
    const supplierOptions: Option[] = suppliers.map((s) => ({ value: s.id, label: s.name }));

    const selectedCategory = categoryOptions.find((o) => o.value === formik.values.expense_category_id) ?? null;
    const selectedSupplier = supplierOptions.find((o) => o.value === formik.values.supplier_id) ?? null;
    const selectedCar = carOptions.find((o) => o.value === formik.values.car_id) ?? null;

    const handleSupplierCreated = (supplier: ISupplier) => {
        setSuppliers((prev) => [...prev, supplier]);
        formik.setFieldValue("supplier_id", supplier.id);
        setQuickSupplierName(null);
    };

    const amountError = formik.touched.amount && formik.errors.amount;

    return (
        <>
            <Modal isOpen={isOpen} toggle={toggle} size="lg" centered scrollable>
                <ModalHeader toggle={toggle}>{isEdit ? "Editar Despesa" : "Nova Despesa"}</ModalHeader>
                <FormikProvider value={formik}>
                    <form onSubmit={formik.handleSubmit}>
                        <ModalBody>
                            <Row className="g-3">
                                <Col lg={8}>
                                    <XInput name="description" label="Descrição" placeholder="Ex.: Revisão + pastilhas" required />
                                </Col>
                                <Col lg={4}>
                                    <Label className="form-label" htmlFor="amount">Valor (€): <span className="text-danger">*</span></Label>
                                    <Input
                                        type="number"
                                        id="amount"
                                        name="amount"
                                        step="0.01"
                                        value={formik.values.amount ?? ""}
                                        onChange={(e) => formik.setFieldValue("amount", e.target.value === "" ? null : Number(e.target.value))}
                                        onBlur={() => formik.setFieldTouched("amount", true)}
                                        invalid={Boolean(amountError)}
                                    />
                                    {amountError && <FormFeedback className="d-block">{String(formik.errors.amount)}</FormFeedback>}
                                </Col>

                                <Col lg={4}>
                                    <Label className="form-label" htmlFor="date">Data: <span className="text-danger">*</span></Label>
                                    <Input
                                        type="date"
                                        id="date"
                                        name="date"
                                        value={formik.values.date ?? ""}
                                        onChange={(e) => formik.setFieldValue("date", e.target.value || null)}
                                    />
                                </Col>

                                <Col lg={4}>
                                    <Label className="form-label">Categoria</Label>
                                    <Select
                                        isClearable
                                        placeholder="Sem categoria"
                                        options={categoryOptions}
                                        value={selectedCategory}
                                        onChange={(opt: Option | null) => formik.setFieldValue("expense_category_id", opt?.value ?? null)}
                                        classNamePrefix="react-select"
                                        {...SELECT_PORTAL}
                                    />
                                </Col>

                                <Col lg={4}>
                                    <Label className="form-label">Fornecedor</Label>
                                    <CreatableSelect
                                        isClearable
                                        placeholder="Sem fornecedor"
                                        formatCreateLabel={(input: string) => `Adicionar "${input}"`}
                                        options={supplierOptions}
                                        value={selectedSupplier}
                                        onChange={(opt: Option | null) => formik.setFieldValue("supplier_id", opt?.value ?? null)}
                                        onCreateOption={(input: string) => setQuickSupplierName(input)}
                                        classNamePrefix="react-select"
                                        {...SELECT_PORTAL}
                                    />
                                </Col>

                                {!fixedCarId && (
                                    <Col lg={6}>
                                        <Label className="form-label">Viatura</Label>
                                        <Select
                                            isClearable
                                            placeholder="Sem viatura"
                                            options={carOptions}
                                            value={selectedCar}
                                            onChange={(opt: CarOption | null) => formik.setFieldValue("car_id", opt?.value ?? null)}
                                            classNamePrefix="react-select"
                                            {...SELECT_PORTAL}
                                        />
                                    </Col>
                                )}

                                <Col lg={fixedCarId ? 6 : 3} className="d-flex align-items-end pb-3">
                                    <XInputCheckbox name="is_paid" label="Pago" />
                                </Col>

                                {formik.values.is_paid && (
                                    <Col lg={3}>
                                        <Label className="form-label" htmlFor="paid_at">Data de pagamento</Label>
                                        <Input
                                            type="date"
                                            id="paid_at"
                                            name="paid_at"
                                            value={formik.values.paid_at ?? todayStr()}
                                            onChange={(e) => formik.setFieldValue("paid_at", e.target.value || null)}
                                        />
                                    </Col>
                                )}

                                <Col lg={12}>
                                    <XInputTextarea name="notes" label="Observações" rows={2} placeholder="Notas internas" />
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

            {/* Reutiliza o modal de criação rápida da 1c.1 — cria e selecciona sem sair do fluxo. */}
            <QuickAddSupplierModal
                isOpen={quickSupplierName !== null}
                toggle={() => setQuickSupplierName(null)}
                companyId={companyId}
                initialName={quickSupplierName ?? ""}
                onCreated={handleSupplierCreated}
            />
        </>
    );
}
