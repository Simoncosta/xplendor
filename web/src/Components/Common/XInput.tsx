// React
import React from "react";
// Forms
import { useField } from "formik";
// Components
import { Input, Label, FormFeedback } from "reactstrap";
// Models
import { InputType } from "reactstrap/types/lib/Input";

interface XInputProps {
    name: string;
    label?: string;
    type?: InputType;
    placeholder?: string;
    step?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
}

const XInput: React.FC<XInputProps> = ({
    label,
    required = false,
    type = "text",
    className,
    ...props
}) => {
    const [field, meta] = useField(props.name);

    const hasError = meta.touched && meta.error;

    return (
        <div className={className}>
            {label && (
                <Label className="form-label">
                    {label}: {required && <span className="text-danger">*</span>}
                </Label>
            )}

            <Input
                {...field}
                {...props}
                type={type}
                invalid={!!hasError}
            />

            {hasError && (
                <FormFeedback type="invalid">{meta.error}</FormFeedback>
            )}
        </div>
    );
};

export default XInput;