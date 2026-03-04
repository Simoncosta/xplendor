import { Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import { useEffect, useRef } from "react";

import { useQuill } from "react-quilljs";
import "quill/dist/quill.snow.css";

import type { ICarFormValues } from "./CarImagesDataFields";

export default function CarDescriptionDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue } = useFormikContext<ICarFormValues>();

    const { quill, quillRef } = useQuill({
        theme: "snow",
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ["bold", "italic", "underline"],
                [{ list: "ordered" }, { list: "bullet" }],
                ["link"],
                ["clean"],
            ],
        },
    });

    const fieldName = "description_website_pt" as const;

    // evita escrever no Quill quando a mudança veio do próprio Quill
    const isSettingFromQuill = useRef(false);

    // 1) Inicializa / re-inicializa o editor com o valor do Formik
    useEffect(() => {
        if (!quill) return;

        const html = values[fieldName] ?? "";
        const current = quill.root.innerHTML;

        // só atualiza se for diferente (evita flicker / loops)
        if (current !== html && !isSettingFromQuill.current) {
            quill.clipboard.dangerouslyPasteHTML(html);
        }
    }, [quill, values[fieldName]]);

    // 2) Sempre que o utilizador escreve, atualiza o Formik
    useEffect(() => {
        if (!quill) return;

        const handler = () => {
            isSettingFromQuill.current = true;

            const html = quill.root.innerHTML;
            // opcional: considera "<p><br></p>" como vazio
            const normalized = html === "<p><br></p>" ? "" : html;

            setFieldValue(fieldName, normalized);

            // liberta no próximo tick para permitir updates externos
            setTimeout(() => {
                isSettingFromQuill.current = false;
            }, 0);
        };

        quill.on("text-change", handler);
        return () => {
            quill.off("text-change", handler);
        };
    }, [quill, setFieldValue]);

    return (
        <div className="mt-4">
            <div className="mb-2 border-bottom pb-2">
                <h5 className="card-title">Descrição</h5>
            </div>

            <Row>
                <Col lg={12}>
                    <div className="snow-editor" style={{ height: 300 }}>
                        <div ref={quillRef} />
                    </div>
                </Col>
            </Row>
        </div>
    );
}