// React
import React from "react";
// Forms
import { useField } from "formik";
// Components
import { Input, Label, FormFeedback } from "reactstrap";
import FieldLabelWithHint from "./FieldLabelWithHint";
// Models
import { InputType } from "reactstrap/types/lib/Input";

interface XInputProps {
    name: string;
    label?: string;
    /** Helper-text on-demand. Quando presente, o label ganha um ícone de
     *  informação que mostra o hint num tooltip (hover/focus/click — funciona
     *  em touch). Substitui o padrão de <small> permanente por baixo. */
    hint?: string;
    type?: InputType;
    placeholder?: string;
    step?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
}

const XInput: React.FC<XInputProps> = ({
    label,
    hint,
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
                hint
                    ? <FieldLabelWithHint
                          label={label}
                          hint={hint}
                          htmlFor={props.name}
                          required={required}
                      />
                    : <Label className="form-label" htmlFor={props.name}>
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