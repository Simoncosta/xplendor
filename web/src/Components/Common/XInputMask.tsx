// XInputMask.tsx
import React from "react";
import { useField } from "formik";
import { Label, FormFeedback } from "reactstrap";
import Cleave from "cleave.js/react";
import "cleave.js/dist/addons/cleave-phone.in";

interface XInputMaskProps {
    name: string;
    label?: string;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
    options: any;
}

const XInputMask: React.FC<XInputMaskProps> = ({
    name,
    label,
    placeholder,
    required = false,
    disabled = false,
    className,
    options,
}) => {
    const [field, meta, helpers] = useField(name);
    const hasError = meta.touched && meta.error;

    return (
        <div className={className}>
            {label && (
                <Label className="form-label">
                    {label}: {required && <span className="text-danger">*</span>}
                </Label>
            )}

            <Cleave
                {...field}
                options={options}
                placeholder={placeholder}
                disabled={disabled}
                className={`form-control ${hasError ? "is-invalid" : ""}`}
                value={field.value || ""}
                onChange={(e: any) => {
                    helpers.setValue(e.target.rawValue); // guarda valor limpo
                }}
                onBlur={() => helpers.setTouched(true)}
            />

            {hasError && (
                <FormFeedback style={{ display: "block" }}>
                    {meta.error}
                </FormFeedback>
            )}
        </div>
    );
};

export default XInputMask;