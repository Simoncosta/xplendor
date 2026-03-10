// React
import React, { useState } from 'react';
import Select from "react-select";
// Formik
import { FormikProvider, useFormik } from 'formik';
// Models
import { IUser } from "common/models/user.model";
import { Card, CardBody, Col, Container, Input, Label, Row } from "reactstrap";

// Images
import profileBg from "../../../assets/images/profile-employer-bg.jpg";
import avatar1 from '../../../assets/images/users/avatar-company.jpg';
import XButton from 'Components/Common/XButton';
import XInput from 'Components/Common/XInput';

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

export default function UserEditor({ data, onSubmit, onCancel }: UserEditorProps) {
    const isEdit = Boolean((data as IUser)?.id);

    const [logoPreview, setLogoPreview] = useState<string | null>(
        `${data?.avatar ? String(process.env.REACT_APP_PUBLIC_URL) + "/storage/" + data?.avatar : avatar1} ` || null
    );

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: data,
        // validationSchema,
        onSubmit: (values) => onSubmit?.(values),
    });

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <div className="position-relative mx-n4 mt-n4">
                        <div className="profile-wid-bg profile-setting-img">
                            <img src={profileBg} className="profile-wid-img" alt="" />
                        </div>
                    </div>
                    <Row>
                        <Col xxl={3}>
                            <Card className="mt-n5">
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
                            <Card className="mt-xxl-n5">
                                <CardBody className="p-4">
                                    <FormikProvider value={formik}>
                                        <form onSubmit={formik.handleSubmit}>
                                            <div className="mb-2 border-bottom pb-2">
                                                <h5 className="card-title">Dados do Colaborador</h5>
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
                                                        Sexo:
                                                    </Label>
                                                    <Select
                                                        id="gender"
                                                        name="gender"
                                                        options={genderOptions}
                                                        value={genderOptions.find(option => option.value === formik.values.gender) || null}
                                                        onChange={(option: any) => {
                                                            formik.setFieldValue("gender", option?.value ?? null);
                                                        }}
                                                        onBlur={() => formik.setFieldTouched("gender", true)}
                                                        className="mb-3"
                                                    />
                                                </Col>
                                                <Col lg={4}>
                                                    <Label for="role">
                                                        Perfil:
                                                    </Label>
                                                    <Select
                                                        id="role"
                                                        name="role"
                                                        options={roleOptions}
                                                        value={roleOptions.find(option => option.value === formik.values.role) || null}
                                                        onChange={(option: any) => {
                                                            formik.setFieldValue("role", option?.value ?? null);
                                                        }}
                                                        onBlur={() => formik.setFieldTouched("role", true)}
                                                        className="mb-3"
                                                    />
                                                </Col>
                                            </Row>
                                            <Col lg={12}>
                                                <div className="hstack gap-2 justify-content-end">
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
        </React.Fragment>
    );
}