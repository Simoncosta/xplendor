// React
import React, { useState } from 'react';
// Formik
import * as Yup from "yup";
import { FormikProvider, useFormik } from 'formik';
// Models
import { IUser } from "common/models/user.model";
import { Button, Card, CardBody, Col, Container, Input, Label, Row, Spinner } from "reactstrap";

// Images
import avatar1 from '../../../assets/images/users/avatar-company.jpg';
import XInput from 'Components/Common/XInput';
import PageHeader from 'Components/Common/PageHeader';
import PageCard from 'Components/Common/PageCard';
import XSelect from 'Components/Common/Select';
import XInputMask from 'Components/Common/XInputMask';

type UserEditorProps = {
    data: IUser
    onSubmit: (data: IUser) => void;
    onCancel: () => void;
    loading?: boolean;
};

const genderOptions = [
    { value: "male", label: "Masculino" },
    { value: "female", label: "Feminino" },
];

const roleOptions = [
    { value: "user", label: "Colaborador" },
    { value: "admin", label: "Administrador" },
];

export default function UserEditor({ data, onSubmit, onCancel, loading = false }: UserEditorProps) {
    const isEdit = Boolean((data as IUser)?.id);

    const [logoPreview, setLogoPreview] = useState<string | null>(
        `${data?.avatar ? String(process.env.REACT_APP_PUBLIC_URL) + "/storage/" + data?.avatar : avatar1} ` || null
    );

    const validationSchema = Yup.object({
        password: Yup.string()
            .nullable()
            .min(8, "A palavra-passe deve ter, no mínimo, 8 caracteres."),

        password_confirmation: Yup.string()
            .nullable()
            .oneOf([Yup.ref("password"), null], "As palavras-passe não coincidem."),
    });

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: data,
        validationSchema: validationSchema,
        onSubmit: (values) => onSubmit?.(values),
    });

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <PageHeader
                        title={formik.values.name || (isEdit ? "Colaborador" : "Novo colaborador")}
                        breadcrumbs={[{ label: "Configurações" }, { label: "Colaboradores", to: "/users" }]}
                        crumbLabel={isEdit ? "Colaborador" : "Novo"}
                        info="Dados da conta e palavra-passe do colaborador."
                    />
                    <Row>
                        <Col xxl={3}>
                            <Card>
                                <CardBody className="p-4">
                                    <div className="text-center">
                                        <div className="profile-user position-relative d-inline-block mx-auto  mb-4">
                                            <img
                                                src={logoPreview || avatar1}
                                                className="rounded-circle avatar-xl img-thumbnail user-profile-image"
                                                alt="company-logo"
                                            />
                                            <div className="avatar-xs p-0 rounded-circle profile-photo-edit">
                                                <Input
                                                    id="profile-img-file-input"
                                                    type="file"
                                                    accept="image/*"
                                                    className="profile-img-file-input"
                                                    onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                                                        const file = e.target.files?.[0];

                                                        if (!file) return;

                                                        // guarda o ficheiro no formik
                                                        formik.setFieldValue("avatar", file);

                                                        // preview imediato
                                                        const previewUrl = URL.createObjectURL(file);
                                                        setLogoPreview(previewUrl);
                                                    }}
                                                />

                                                <Label
                                                    htmlFor="profile-img-file-input"
                                                    className="profile-photo-edit avatar-xs"
                                                >
                                                    <span className="avatar-title rounded-circle bg-light text-body">
                                                        <i className="ri-camera-fill"></i>
                                                    </span>
                                                </Label>
                                            </div>
                                        </div>
                                        <h5 className="fs-16 mb-1">{formik.values.name || '-'}</h5>
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>

                        <Col xxl={9}>
                            <PageCard title="Conta" flush={false} bodyClassName="p-4"
                                actions={<>
                                    <Button size="sm" color="outline-primary" disabled={loading} onClick={() => onCancel()}>Cancelar</Button>
                                    <Button size="sm" color="primary" type="submit" form="user-editor-form" disabled={loading}>
                                        {loading ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />Guardar</>}
                                    </Button>
                                </>}>
                                    <FormikProvider value={formik}>
                                        <form id="user-editor-form" onSubmit={formik.handleSubmit}>
                                            <div className="mb-2 border-bottom pb-2">
                                                <h5 className="card-title mb-0">Alterar palavra-passe</h5>
                                            </div>
                                            <Row>
                                                <Col lg={6}>
                                                    <XInput
                                                        type='password'
                                                        className='mb-4'
                                                        name="password"
                                                        label="Nova palavra-passe"
                                                        placeholder="Nova palavra-passe"
                                                    />
                                                </Col>
                                                <Col lg={6}>
                                                    <XInput
                                                        className='mb-4'
                                                        placeholder="Confirmar palavra-passe"
                                                        type="password"
                                                        name="password_confirmation"
                                                        label="Confirmar palavra-passe"
                                                    />
                                                </Col>
                                            </Row>

                                            <div className="mb-2 border-bottom pb-2">
                                                <h5 className="card-title mb-0">Dados do colaborador</h5>
                                            </div>

                                            <Row>
                                                <Col lg={6}>
                                                    <XInput
                                                        className='mb-2'
                                                        name="name"
                                                        label="Nome"
                                                        placeholder="Nome"
                                                        required
                                                    />
                                                </Col>
                                                <Col lg={6}>
                                                    <XInput
                                                        className='mb-2'
                                                        name="email"
                                                        label="E-mail"
                                                        placeholder="E-mail"
                                                        required
                                                        disabled={isEdit}
                                                    />
                                                </Col>
                                                <Col lg={4}>
                                                    <XInput
                                                        type='date'
                                                        className='mb-2'
                                                        name="birthdate"
                                                        label="Data de Nascimento"
                                                        placeholder="Data de Nascimento"
                                                    />
                                                </Col>
                                                <Col lg={4}>
                                                    <Label for="gender">
                                                        Sexo
                                                    </Label>
                                                    <div className="mb-3">
                                                        <XSelect id="gender" options={genderOptions} value={formik.values.gender as string | null}
                                                            onChange={(v) => formik.setFieldValue("gender", v)} />
                                                    </div>
                                                </Col>
                                                <Col lg={4}>
                                                    <Label for="role">
                                                        Perfil
                                                    </Label>
                                                    <div className="mb-3">
                                                        <XSelect id="role" options={roleOptions} value={formik.values.role as string | null}
                                                            onChange={(v) => formik.setFieldValue("role", v)} />
                                                    </div>
                                                </Col>
                                                <Col lg={6}>
                                                    <XInputMask
                                                        className='mb-2'
                                                        name="mobile"
                                                        label="Telemóvel"
                                                        placeholder="123 456 789"
                                                        options={{
                                                            blocks: [3, 3, 3],
                                                            delimiter: " ",
                                                            numericOnly: true,
                                                        }}
                                                    />
                                                </Col>
                                                <Col lg={6}>
                                                    <XInputMask
                                                        className='mb-2'
                                                        name="whatsapp"
                                                        label="WhatsApp"
                                                        placeholder="123 456 789"
                                                        options={{
                                                            blocks: [3, 3, 3],
                                                            delimiter: " ",
                                                            numericOnly: true,
                                                        }}
                                                    />
                                                </Col>
                                            </Row>
                                        </form>
                                    </FormikProvider>
                            </PageCard>
                        </Col>
                    </Row>
                </Container>
            </div>
        </React.Fragment>
    );
}
