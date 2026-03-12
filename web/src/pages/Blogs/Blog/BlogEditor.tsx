// React Formik
import { useEffect, useState } from "react";
import { FormikProvider, useFormik } from "formik";
// Select
import CreatableSelect from "react-select/creatable";
import Select from "react-select";
// Yup
import * as Yup from "yup";
// Models
import { IBlogPost } from "common/models/blog.model";
// Components
import { Card, CardBody, Col, Container, Input, Label, Row } from "reactstrap";
import XButton from "Components/Common/XButton";
import XInput from "Components/Common/XInput";
import XInputTextareaQuill from "Components/Common/XInputTextareaQuill";

type BlogEditorProps = {
    data: IBlogPost;
    onSubmit: (data: IBlogPost) => void;
    onCancel: () => void;
    loading?: boolean;
};

type TagOption = {
    label: string;
    value: string;
};

const statusOptions = [
    { value: "draft", label: "Rascunho" },
    { value: "published", label: "Publicado" },
];

export default function BlogEditor({ data, onSubmit, onCancel }: BlogEditorProps) {
    const isEdit = Boolean((data as any)?.id);

    // State
    const [tagOptions, setTagOptions] = useState<TagOption[]>([]);

    const validationSchema = Yup.object({
    });

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: data,
        validationSchema,
        onSubmit: (values) => onSubmit?.(values),
    });

    // Effects
    useEffect(() => {
        const currentTags = (formik.values.tags || []).map((tag: string) => ({
            label: tag,
            value: tag,
        }));

        setTagOptions((prev) => {
            const merged = [...prev];

            currentTags.forEach((tag) => {
                if (!merged.find((item) => item.value === tag.value)) {
                    merged.push(tag);
                }
            });

            return merged;
        });
    }, [formik.values.tags]);

    // Selected Options Actions
    const selectedTagOptions: TagOption[] = (formik.values.tags || []).map((tag: string) => ({
        label: tag,
        value: tag,
    }));

    return (
        <div className="page-content">
            <Container fluid>
                <Row>
                    <Col lg={12}>
                        <Card>
                            <CardBody>
                                <FormikProvider value={formik}>
                                    <form onSubmit={formik.handleSubmit}>
                                        <Row>
                                            <Col lg={2}>
                                                <Label for="status">
                                                    Status: <span className="text-danger">*</span>
                                                </Label>
                                                <Select
                                                    name="status"
                                                    options={statusOptions}
                                                    value={statusOptions.find((option: any) => option.value === formik.values.status) || null}
                                                    onChange={(option: any) => {
                                                        formik.setFieldValue("status", option?.value || null);
                                                        formik.setFieldTouched("status", true);
                                                    }}
                                                    className="mb-3"
                                                    required
                                                />
                                            </Col>
                                            <Col lg={10}>
                                                <XInput
                                                    name="title"
                                                    label="Título"
                                                    className="mb-3"
                                                    required
                                                />
                                            </Col>
                                            <Col lg={4}>
                                                <XInput
                                                    name="subtitle"
                                                    label="Subtítulo"
                                                    className="mb-3"
                                                />
                                            </Col>
                                            <Col lg={4}>
                                                <XInput
                                                    name="excerpt"
                                                    label="Resumo"
                                                    className="mb-3"
                                                />
                                            </Col>
                                            <Col lg={4}>
                                                <Label>Tags:</Label>
                                                <CreatableSelect
                                                    className="mb-3"
                                                    isMulti
                                                    name="tags"
                                                    placeholder="Selecione ou escreva tags"
                                                    options={tagOptions}
                                                    value={selectedTagOptions}
                                                    onChange={(value) => {
                                                        const selectedValues = (value || []).map((item: any) => item.value);
                                                        formik.setFieldValue("tags", selectedValues);
                                                    }}
                                                    onCreateOption={(inputValue) => {
                                                        const normalized = inputValue.trim();

                                                        if (!normalized) return;

                                                        const newOption = {
                                                            label: normalized,
                                                            value: normalized,
                                                        };

                                                        setTagOptions((prev) => {
                                                            if (prev.find((item) => item.value === normalized)) {
                                                                return prev;
                                                            }

                                                            return [...prev, newOption];
                                                        });

                                                        formik.setFieldValue("tags", [
                                                            ...(formik.values.tags || []),
                                                            normalized,
                                                        ]);
                                                    }}
                                                />
                                            </Col>
                                            <Col lg={4}>
                                                <XInput
                                                    name="category"
                                                    label="Categoria"
                                                    className="mb-3"
                                                />
                                            </Col>
                                            <Col lg={8}>
                                                <Label>Banner:</Label>
                                                <Input
                                                    type="file"
                                                    id="banner"
                                                    name="banner"
                                                    accept="image/*"
                                                    className="form-control"
                                                    onChange={(event: React.ChangeEvent<HTMLInputElement>) => {
                                                        const file = event.currentTarget.files?.[0];

                                                        if (!file) return;

                                                        formik.setFieldValue("banner", file);
                                                    }}
                                                />
                                            </Col>
                                            <Col lg={12}>
                                                <XInputTextareaQuill
                                                    name="content"
                                                    label="Descrição"
                                                    className="mb-3"
                                                    required
                                                    height={300}
                                                />
                                            </Col>
                                        </Row>

                                        <Col lg={12}>
                                            <div className="hstack gap-2 justify-content-end mt-4">
                                                <XButton
                                                    variant="success"
                                                    type='submit'
                                                    outline
                                                    rounded
                                                    icon={<i className="ri-check-double-line" />}
                                                >
                                                    Salvar
                                                </XButton>
                                                <XButton
                                                    variant="danger"
                                                    outline
                                                    rounded
                                                    icon={<i className="ri-close-line" />}
                                                    onClick={() => onCancel()}
                                                >
                                                    Cancelar
                                                </XButton>
                                            </div>
                                        </Col>
                                    </form>
                                </FormikProvider>
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    )
}