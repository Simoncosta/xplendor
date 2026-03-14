// React
import React, { useEffect, useRef } from "react";
// Formik
import { useField, useFormikContext } from "formik";
// Reactstrap
import { Label, FormFeedback } from "reactstrap";
// Quill
import { useQuill } from "react-quilljs";
import "quill/dist/quill.snow.css";

interface XInputTextareaQuillProps {
    name: string;
    label?: string;
    required?: boolean;
    className?: string;
    height?: number;
    placeholder?: string;
    modules?: any;
}

const DEFAULT_MODULES = {
    toolbar: [
        [{ header: [1, 2, 3, false] }],
        ["bold", "italic", "underline"],
        [{ list: "ordered" }, { list: "bullet" }],
        ["link"],
        ["clean"],
    ],
};

const XInputTextareaQuill: React.FC<XInputTextareaQuillProps> = ({
    name,
    label,
    required = false,
    className,
    height = 300,
    placeholder,
    modules = DEFAULT_MODULES,
}) => {
    const [field, meta] = useField(name);
    const { setFieldValue, setFieldTouched } = useFormikContext<any>();

    const { quill, quillRef } = useQuill({
        theme: "snow",
        modules,
        placeholder,
    });

    const isSettingFromQuill = useRef(false);
    const hasError = meta.touched && meta.error;

    // sincroniza Formik -> Quill
    useEffect(() => {
        if (!quill) return;

        const html = field.value ?? "";
        const current = quill.root.innerHTML;

        if (current !== html && !isSettingFromQuill.current) {
            quill.clipboard.dangerouslyPasteHTML(html || "");
        }
    }, [quill, field.value]);

    // sincroniza Quill -> Formik
    useEffect(() => {
        if (!quill) return;

        const handleTextChange = () => {
            isSettingFromQuill.current = true;

            const html = quill.root.innerHTML;
            const normalized = html === "<p><br></p>" ? "" : html;

            setFieldValue(name, normalized);

            setTimeout(() => {
                isSettingFromQuill.current = false;
            }, 0);
        };

        const handleBlur = () => {
            setFieldTouched(name, true);
        };

        quill.on("text-change", handleTextChange);
        quill.root.addEventListener("blur", handleBlur);

        return () => {
            quill.off("text-change", handleTextChange);
            quill.root.removeEventListener("blur", handleBlur);
        };
    }, [quill, name, setFieldValue, setFieldTouched]);

    return (
        <div className={className}>
            {label && (
                <Label className="form-label">
                    {label}: {required && <span className="text-danger">*</span>}
                </Label>
            )}

            <div
                className={`snow-editor ${hasError ? "is-invalid" : ""}`}
                style={{ minHeight: height }}
            >
                <div ref={quillRef} />
            </div>

            {hasError && (
                <div className="d-block invalid-feedback">
                    {meta.error}
                </div>
            )}
        </div>
    );
};

export default XInputTextareaQuill;