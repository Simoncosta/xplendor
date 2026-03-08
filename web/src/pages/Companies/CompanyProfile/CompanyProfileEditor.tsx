// React
import React, { useState, useMemo } from 'react';
// Images
import progileBg from '../../../assets/images/profile-company-bg.jpg';
import avatar1 from '../../../assets/images/users/avatar-company.jpg';
// Forms
import { FormikProvider, useFormik } from 'formik';
import * as Yup from "yup";
// Components
import XButton from 'Components/Common/XButton';
import CompanyGeneralDataFields from './components/CompanyGeneralDataFields';
import { Card, CardBody, CardHeader, Col, Container, Input, Label, Nav, NavItem, NavLink, Row, TabContent, TabPane } from 'reactstrap';
// Slices
import classnames from "classnames";
// Models
import { ICompanyUpdatePayload } from 'common/models/company.model';
import XInput from 'Components/Common/XInput';
import { ICarmineApi } from 'common/models/carmine-api.model';

type CompanyProfileEditorProps = {
    data: ICompanyUpdatePayload;
    dataCarmine: ICarmineApi;
    onSubmit: (data: ICompanyUpdatePayload) => void;
    onSubmitCarmine: (data: ICarmineApi) => void;
    onCancel: () => void;
    loading?: boolean;
};

export default function CompanyProfileEditor({
    data,
    dataCarmine,
    onSubmit,
    onSubmitCarmine,
    onCancel
}: CompanyProfileEditorProps) {
    const isEdit = Boolean((data as any)?.id);
    const [tokenCarmineShow, setTokenCarmineShow] = useState<boolean>(true);

    const [logoPreview, setLogoPreview] = useState<string | null>(
        `${data?.logo_path ? String(process.env.REACT_APP_PUBLIC_URL) + data?.logo_path : avatar1}` || null
    );

    const [activeTab, setActiveTab] = useState("1");

    const tabChange = (tab: any) => {
        if (activeTab !== tab) setActiveTab(tab);
    };

    const validationSchema = Yup.object({
        nipc: Yup.string()
            .required("NIPC é obrigatório")
            .matches(/^\d+$/, "NIPC deve conter apenas números")
            .length(9, "NIPC deve ter 9 dígitos"),
    });

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: data,
        validationSchema,
        onSubmit: (values) => onSubmit?.(values),
    });

    const formikCarmine = useFormik({
        enableReinitialize: true,
        initialValues: dataCarmine,
        onSubmit: (values) => onSubmitCarmine?.(values),
    });

    const progress = useMemo(() => {
        // Campos que contam para o progresso
        const baseFields: (keyof ICompanyUpdatePayload)[] = [
            // Dados Gerais
            "nipc",
            "fiscal_name",
            "trade_name",
            "responsible_name",
            "phone",
            "mobile",
            "email",
            "invoice_email",

            // Social
            "website",
            "instagram",
            "facebook",
            "youtube",
            "google",

            // Endereço
            "postal_code",
            "address",
            "district_id",
            "municipality_id",
            "parish_id",
        ];

        // Campos de login só no CREATE
        const loginFields = ["name_user", "email_user"] as const;

        // “Imagem” conta também
        // - No update pode vir logo_path
        // - No create/update pode vir logo_file
        const total =
            baseFields.length +
            (!isEdit ? loginFields.length : 0) +
            1; // +1 para logo

        const isFilled = (v: any) => {
            if (v === null || v === undefined) return false;

            // file
            if (v instanceof File) return true;
            if (v instanceof FileList) return v.length > 0;

            // number (inclui ids)
            if (typeof v === "number") return v > 0;

            // boolean
            if (typeof v === "boolean") return true;

            // string
            if (typeof v === "string") return v.trim().length > 0;

            // arrays
            if (Array.isArray(v)) return v.length > 0;

            // objetos (raramente aqui)
            return true;
        };

        let filled = 0;

        // base
        baseFields.forEach((key) => {
            if (isFilled((formik.values as any)[key])) filled += 1;
        });

        // login (create)
        if (!isEdit) {
            loginFields.forEach((key) => {
                if (isFilled((formik.values as any)[key])) filled += 1;
            });
        }

        // logo (file OU path)
        const hasLogo =
            isFilled((formik.values as any).logo_file) || isFilled((formik.values as any).logo_path);
        if (hasLogo) filled += 1;

        const percent = Math.round((filled / total) * 100);

        return {
            total,
            filled,
            percent,
        };
    }, [formik.values, isEdit]);

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <div className="position-relative mx-n4 mt-n4">
                        <div className="profile-wid-bg profile-setting-img">
                            <img src={progileBg} className="profile-wid-img" alt="" />
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
                                                        formik.setFieldValue("logo_file", file);

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
                                        <h5 className="fs-16 mb-1">{formik.values.fiscal_name || '-'}</h5>
                                    </div>
                                </CardBody>
                            </Card>

                            <Card>
                                <CardBody>
                                    <div className="d-flex align-items-center mb-5">
                                        <div className="flex-grow-1">
                                            <h5 className="card-title mb-0">Complete seu perfil empresarial</h5>
                                        </div>
                                    </div>
                                    <div className="progress animated-progress custom-progress progress-label">
                                        <div className="progress-bar bg-primary" role="progressbar" style={{ width: `${progress.percent}%` }}>
                                            <div className="label">{progress.percent}%</div>
                                        </div>
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>

                        <Col xxl={9}>
                            <Card className="mt-xxl-n5">
                                <CardHeader>
                                    <Nav className="nav-tabs-custom rounded card-header-tabs border-bottom-0"
                                        role="tablist">
                                        <NavItem>
                                            <NavLink
                                                className={classnames("text-body", { active: activeTab === "1" })}
                                                onClick={() => {
                                                    tabChange("1");
                                                }}>
                                                <i className="fas fa-home"></i>
                                                Dados Gerais
                                            </NavLink>
                                        </NavItem>
                                        <NavItem>
                                            <NavLink
                                                className={classnames("text-body", { active: activeTab === "2" })}
                                                onClick={() => {
                                                    tabChange("2");
                                                }}
                                                disabled={!isEdit}
                                            >
                                                <i className="fas fa-home"></i>
                                                API Carmine
                                            </NavLink>
                                        </NavItem>
                                    </Nav>
                                </CardHeader>
                                <CardBody className="p-4">
                                    <TabContent activeTab={activeTab}>
                                        <TabPane tabId="1">
                                            <FormikProvider value={formik}>
                                                <form onSubmit={formik.handleSubmit}>
                                                    <CompanyGeneralDataFields
                                                        isEdit={isEdit}
                                                    />

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
                                        </TabPane>
                                        <TabPane tabId="2">
                                            <FormikProvider value={formikCarmine}>
                                                <form onSubmit={formikCarmine.handleSubmit}>
                                                    <div className="mb-2 border-bottom pb-2">
                                                        <h5 className="card-title">Carmine</h5>
                                                    </div>

                                                    <Row>
                                                        <Col lg={6}>
                                                            <XInput
                                                                className='mb-2'
                                                                name="dealer_id"
                                                                label="ID do Dealer"
                                                                placeholder="ID do Dealer"
                                                                required
                                                            />
                                                        </Col>
                                                        <Col lg={6}>
                                                            <div className="position-relative auth-pass-inputgroup mb-3">
                                                                <XInput
                                                                    type={tokenCarmineShow ? "text" : "password"}
                                                                    className='mb-2 '
                                                                    name="token"
                                                                    label={"Token"}
                                                                    placeholder="Token"
                                                                    required
                                                                />
                                                            </div>
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
                                        </TabPane>
                                    </TabContent>
                                </CardBody>
                            </Card>
                        </Col>
                    </Row>
                </Container>
            </div>
        </React.Fragment>
    );
}