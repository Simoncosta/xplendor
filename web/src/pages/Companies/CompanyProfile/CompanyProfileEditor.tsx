// React
import React, { useState, useMemo, useEffect, useRef } from 'react';
// Images
import progileBg from '../../../assets/images/profile-company-bg.jpg';
import avatar1 from '../../../assets/images/users/avatar-company.jpg';
// Forms
import { FormikProvider, useFormik } from 'formik';
import * as Yup from "yup";
// Components
import XButton from 'Components/Common/XButton';
import CompanyGeneralDataFields from './components/CompanyGeneralDataFields';
import AgencyCreateFields from './components/AgencyCreateFields';
import ManagingAgencyCard from './components/ManagingAgencyCard';
import AgencyManagementPanel from 'pages/Companies/components/AgencyManagementPanel';
import IntegrationsSettings from './IntegrationsSettings';
import EditorialSectorSettings from 'pages/Editorial/EditorialSectorSettings';
import { getCompanyManagement, getMyModules } from 'helpers/laravel_helper';
import { Card, CardBody, CardHeader, Col, Container, Input, Label, Nav, NavItem, NavLink, Row, TabContent, TabPane } from 'reactstrap';
// Slices
import classnames from "classnames";
// Models
import { ICompanyUpdatePayload } from 'common/models/company.model';
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
    const companyId = Number((data as any)?.id) || 0;
    // Gestão por agências: o root marca agências e define a agência gestora.
    const isRoot = useMemo(() => {
        try { const a = JSON.parse(sessionStorage.getItem("authUser") || "null"); return a?.role === "root" && !a?.impersonating; } catch { return false; }
    }, []);
    // Aba só para quem tem o módulo, pelos módulos ATIVOS da empresa deste perfil (sem
    // pedidos que dão 403 enquanto não se sabem).
    const [profileModules, setProfileModules] = useState<string[] | null>(null);
    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        getMyModules(companyId).then((r: any) => { if (alive) setProfileModules(r?.data?.modules ?? []); }).catch(() => { if (alive) setProfileModules([]); });
        return () => { alive = false; };
    }, [companyId]);
    const showEditorial = !!profileModules?.includes('linha_editorial');
    // Quem não pode gravar os dados (ex.: a agência num cliente que já tem administrador) vê
    // o formulário só de leitura; a agência que ainda edita os dados básicos vê porquê.
    const [access, setAccess] = useState<{ canEdit: boolean; basicsOnly: boolean }>({ canEdit: true, basicsOnly: false });
    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        getCompanyManagement(companyId)
            .then((r: any) => { if (alive) setAccess({ canEdit: r?.data?.can_edit_company !== false, basicsOnly: !!r?.data?.can_edit_basics_only }); })
            .catch(() => { /* sem resposta: o servidor decide ao gravar */ });
        return () => { alive = false; };
    }, [companyId]);
    const readOnly = isEdit && !access.canEdit;
    // Carmine e PingWin passaram a viver como cartões na aba "Integrações"
    // (IntegrationsSettings), cada um gated pelo seu módulo — mostrado mas
    // BLOQUEADO a quem não o tem. O backend recusa na mesma (Fase 3).

    const [logoPreview, setLogoPreview] = useState<string | null>(
        `${data?.logo_path ? String(process.env.REACT_APP_PUBLIC_URL) + data?.logo_path : avatar1}` || null
    );

    // Abrir directamente o separador Integrações quando o link o pede
    // (?tab=integrations — ex.: "Escolher a conta"/"Reconectar" no ecrã Meta) ou
    // no retorno do OAuth Meta (?meta=…) ou das redes sociais (?social=…). Só em edição.
    const wantsIntegrations = useMemo(() => {
        const q = new URLSearchParams(window.location.search);
        return q.get('tab') === 'integrations' || q.has('meta') || q.has('social');
    }, []);
    const [activeTab, setActiveTab] = useState(() => (isEdit && wantsIntegrations ? "3" : "1"));
    const openedFromLink = useRef(false);
    useEffect(() => {
        if (isEdit && wantsIntegrations && !openedFromLink.current) {
            openedFromLink.current = true;
            setActiveTab("3");
        }
    }, [isEdit, wantsIntegrations]);

    const tabChange = (tab: any) => {
        if (activeTab !== tab) setActiveTab(tab);
    };

    // Uma empresa gerida por uma agência pode nascer sem NIPC (e sem utilizador de acesso).
    const validationSchema = Yup.object({
        nipc: Yup.string().nullable()
            .when("managed_by_company_id", ([managedBy]: any[], schema: any) => (managedBy ? schema.notRequired() : schema.required("NIPC é obrigatório")))
            .test("nipc", "NIPC deve ter 9 dígitos", (v: any) => !v || /^\d{9}$/.test(String(v))),
    });

    const formik = useFormik({
        enableReinitialize: true,
        initialValues: data,
        validationSchema,
        onSubmit: (values) => onSubmit?.(values),
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
                                                    disabled={readOnly}
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
                                                    className={`profile-photo-edit avatar-xs ${readOnly ? "d-none" : ""}`}
                                                >
                                                    <span className="avatar-title rounded-circle bg-light text-body">
                                                        <i className="ri-camera-fill"></i>
                                                    </span>
                                                </Label>
                                            </div>
                                        </div>
                                        <h5 className="fs-16 mb-2">{formik.values.fiscal_name || '-'}</h5>
                                        {/* @ts-ignore */}
                                        <p><b>API Key:</b> {data?.public_api_token || '-'}</p>
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

                            {isEdit && <ManagingAgencyCard companyId={companyId} />}
                            {isEdit && isRoot && (
                                <Card>
                                    <CardBody>
                                        <h5 className="card-title mb-3"><i className="ri-team-line me-1" />Gestão por agências</h5>
                                        <AgencyManagementPanel companyId={companyId} />
                                    </CardBody>
                                </Card>
                            )}
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
                                        {showEditorial && (
                                            <NavItem>
                                                <NavLink
                                                    className={classnames("text-body", { active: activeTab === "4" })}
                                                    onClick={() => { tabChange("4"); }}
                                                    disabled={!isEdit}
                                                >
                                                    Linha Editorial
                                                </NavLink>
                                            </NavItem>
                                        )}
                                        <NavItem>
                                            <NavLink
                                                className={classnames("text-body", { active: activeTab === "3" })}
                                                onClick={() => {
                                                    tabChange("3");
                                                }}
                                                disabled={!isEdit}
                                            >
                                                <i className="fas fa-home"></i>
                                                Integrações
                                            </NavLink>
                                        </NavItem>
                                    </Nav>
                                </CardHeader>
                                <CardBody className="p-4">
                                    <TabContent activeTab={activeTab}>
                                        <TabPane tabId="1">
                                            <FormikProvider value={formik}>
                                                <form onSubmit={formik.handleSubmit}>
                                                    {readOnly && (
                                                        <div className="alert alert-info d-flex gap-2 align-items-start" data-testid="profile-read-only">
                                                            <i className="ri-lock-line fs-16" />
                                                            <span>Só de leitura: os dados desta empresa são editados pelo administrador dela.</span>
                                                        </div>
                                                    )}
                                                    {access.basicsOnly && (
                                                        <div className="alert alert-light d-flex gap-2 align-items-start">
                                                            <i className="ri-information-line fs-16" />
                                                            <span>A agência edita os dados desta empresa enquanto o cliente não tiver um administrador. Depois disso, passam a ser só de leitura para a agência.</span>
                                                        </div>
                                                    )}
                                                    {!isEdit && isRoot && <AgencyCreateFields />}
                                                    <fieldset disabled={readOnly} style={readOnly ? { pointerEvents: "none" } : undefined} aria-readonly={readOnly || undefined}>
                                                        <CompanyGeneralDataFields
                                                            isEdit={isEdit}
                                                            managed={!isEdit && !!(formik.values as any).managed_by_company_id}
                                                        />
                                                    </fieldset>

                                                    <Col lg={12}>
                                                        <div className="hstack gap-2 justify-content-end">
                                                            {!readOnly && <XButton
                                                                variant="success"
                                                                type='submit'
                                                                outline
                                                                rounded
                                                                icon={<i className="ri-check-double-line" />}
                                                            >
                                                                Salvar
                                                            </XButton>}
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
                                        <TabPane tabId="3">
                                            <IntegrationsSettings
                                                companyId={companyId}
                                                dataCarmine={dataCarmine}
                                                onSubmitCarmine={onSubmitCarmine}
                                            />
                                        </TabPane>
                                        {showEditorial && (
                                            <TabPane tabId="4">
                                                <EditorialSectorSettings companyId={companyId} />
                                            </TabPane>
                                        )}
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