// Components/Common/XInputCheckboxArray.tsx
import React, { useId } from "react";
import { useField, useFormikContext } from "formik";
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

interface XInputCheckboxArrayProps {
    /** Campo array no formik (ex: extrasByGroup.comfort_multimedia) */
    name: string;

    /** Valor que este checkbox representa (ex: "Bluetooth") */
    value: string;

    label?: string;
    className?: string;
    right?: boolean;
    variant?: CheckboxVariant;
    outline?: boolean;
    disabled?: boolean;
    required?: boolean;
    id?: string;

    onChange?: (checked: boolean) => void;
}

const XInputCheckboxArray: React.FC<XInputCheckboxArrayProps> = ({
    name,
    value,
    label,
    className,
    right = false,
    variant,
    outline = false,
    disabled = false,
    required = false,
    id,
    onChange,
}) => {
    const autoId = useId();
    const inputId = id ?? `${name}-${autoId}`;

    const { setFieldValue, setFieldTouched } = useFormikContext<any>();
    const [field, meta] = useField<string[]>({ name });

    const list = Array.isArray(field.value) ? field.value : [];
    const checked = list.includes(value);
    const hasError = Boolean(meta.touched && meta.error);

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
                id={inputId}
                type="checkbox"
                className="form-check-input"
                checked={checked}
                disabled={disabled}
                invalid={hasError}
                onBlur={() => setFieldTouched(name, true)}
                onChange={(e) => {
                    const nextChecked = e.target.checked;
                    const next = nextChecked
                        ? [...list, value]
                        : list.filter((v) => v !== value);

                    setFieldValue(name, next);
                    setFieldTouched(name, true);

                    onChange?.(nextChecked);
                }}
            />

            {label && (
                <Label className="form-check-label" htmlFor={inputId}>
                    {label} {required && <span className="text-danger">*</span>}
                </Label>
            )}

            {hasError && <FormFeedback type="invalid">{String(meta.error)}</FormFeedback>}
        </div>
    );
};

export default XInputCheckboxArray;