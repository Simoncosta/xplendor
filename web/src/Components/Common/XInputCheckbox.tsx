// React
import React, { useEffect, useId, useRef } from "react";
// Forms
import { useField } from "formik";
// Components
import { Input, Label, FormFeedback } from "reactstrap";

type CheckboxVariant =
    | "primary"
    | "secondary"
    | "success"
    | "warning"
    | "danger"
    | "info"
    | "dark"
    | "light";

interface XInputCheckboxProps {
    name: string;
    label?: string;

    /** Ex: "mb-2" */
    className?: string;

    /** Coloca o check à direita (form-check-right) */
    right?: boolean;

    /** Aplica cor: form-check form-check-success ... */
    variant?: CheckboxVariant;

    /** Aplica outline: form-check-outline + variant */
    outline?: boolean;

    required?: boolean;
    disabled?: boolean;

    /** Se quiseres forçar o indeterminate (tristate visual) */
    indeterminate?: boolean;

    /** id opcional; se não vier, gera automaticamente */
    id?: string;

    /** callback extra além do Formik */
    onChange?: (checked: boolean) => void;
}

const XInputCheckbox: React.FC<XInputCheckboxProps> = ({
    name,
    label,
    className,
    right = false,
    variant,
    outline = false,
    required = false,
    disabled = false,
    indeterminate = false,
    id,
    onChange,
}) => {
    const [field, meta, helpers] = useField<boolean>(name);

    const autoId = useId();
    const inputId = id ?? `${name}-${autoId}`;

    const hasError = Boolean(meta.touched && meta.error);

    const inputRef = useRef<HTMLInputElement | null>(null);

    // indeterminate não é attribute controlável; é propriedade do DOM
    useEffect(() => {
        if (inputRef.current) {
            inputRef.current.indeterminate = Boolean(indeterminate);
        }
    }, [indeterminate]);

    const wrapperClasses = [
        "form-check",
        right ? "form-check-right" : "",
        outline ? "form-check-outline" : "",
        variant ? `form-check-${variant}` : "",
        className ?? "",
    ]
        .filter(Boolean)
        .join(" ");

    return (
        <div className={wrapperClasses}>
            <Input
                innerRef={inputRef}
                id={inputId}
                name={name}
                type="checkbox"
                className="form-check-input"
                checked={Boolean(field.value)}
                disabled={disabled}
                invalid={hasError}
                onBlur={field.onBlur}
                onChange={(e) => {
                    const checked = e.target.checked;
                    helpers.setValue(checked);
                    if (!meta.touched) helpers.setTouched(true);

                    onChange?.(checked);
                }}
            />

            {label && (
                <Label className="form-check-label" htmlFor={inputId}>
                    {label} {required && <span className="text-danger">*</span>}
                </Label>
            )}

            {hasError && <FormFeedback type="invalid">{meta.error}</FormFeedback>}
        </div>
    );
};

export default XInputCheckbox;