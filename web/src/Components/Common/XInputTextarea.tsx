// React
import React from "react";
// Forms
import { useField } from "formik";
// Components
import { Input, Label, FormFeedback } from "reactstrap";

interface XInputTextareaProps {
    name: string;
    label?: string;
    placeholder?: string;
    rows?: number;
    required?: boolean;
    disabled?: boolean;
    className?: string;
}

const XInputTextarea: React.FC<XInputTextareaProps> = ({
    label,
    required = false,
    rows = 4,
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

            {/* null mostra-se vazio; o valor do formulário não muda (só o onChange do Formik o altera). */}
            <Input
                {...field}
                {...props}
                value={field.value ?? ""}
                type="textarea"
                rows={rows}
                invalid={!!hasError}
            />

            {hasError && (
                <FormFeedback type="invalid">
                    {meta.error}
                </FormFeedback>
            )}
        </div>
    );
};

export default XInputTextarea;