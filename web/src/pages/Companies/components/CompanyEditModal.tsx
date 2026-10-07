import { useEffect, useState } from "react";
import { FormikProvider, useFormik } from "formik";
import * as Yup from "yup";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Spinner, TabContent, TabPane } from "reactstrap";
import { toast } from "react-toastify";
import CompanyGeneralDataFields from "pages/Companies/CompanyProfile/components/CompanyGeneralDataFields";
import AgencyCreateFields from "pages/Companies/CompanyProfile/components/AgencyCreateFields";
import { COMPANY_CREATE_DEFAULTS } from "slices/companies/company.defaults";
import { createCompany, getCompany, setAdminCompanyAgency, updateCompany } from "helpers/laravel_helper";
import { companyErrorText, companyFormData } from "pages/Companies/companyFormData";
import AgencyManagementPanel from "./AgencyManagementPanel";
import CompanyModulesPanel from "./CompanyModulesPanel";

/**
 * O modal da empresa no ecrã Empresas (só o root), o MESMO para criar e editar, com os
 * separadores Dados, Módulos e Agência. Ao criar, o ramo (em Dados) decide os módulos à
 * nascença e a agência escolhe-se no separador Agência; os módulos ajustam-se depois de a
 * empresa existir. Ao editar, Módulos e Agência gravam logo cada alteração.
 */
type Tab = "dados" | "modulos" | "agencia";

type Props = {
    isOpen: boolean;
    /** null: criar uma empresa nova. */
    companyId: number | null;
    initialTab?: Tab;
    onClose: () => void;
    onSaved: () => void;
};

const schema = Yup.object({
    fiscal_name: Yup.string().nullable().required("A designação fiscal é obrigatória."),
    nipc: Yup.string().nullable()
        .when("managed_by_company_id", ([managedBy]: any[], s: any) => (managedBy ? s.notRequired() : s.required("NIPC é obrigatório")))
        .test("nipc", "NIPC deve ter 9 dígitos", (v: any) => !v || /^\d{9}$/.test(String(v))),
});

export default function CompanyEditModal({ isOpen, companyId, initialTab = "dados", onClose, onSaved }: Props) {
    const [id, setId] = useState<number | null>(companyId);
    const [tab, setTab] = useState<Tab>(initialTab);
    const [data, setData] = useState<any>(null);
    const [busy, setBusy] = useState(false);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const isEdit = id !== null;

    useEffect(() => {
        if (!isOpen) return;
        setId(companyId);
        setTab(initialTab);
        setLogoPreview(null);
        if (companyId === null) { setData({ ...COMPANY_CREATE_DEFAULTS }); return; }
        setData(null);
        getCompany(companyId).then((r: any) => setData(r?.data ?? null)).catch(() => { toast.error("Não foi possível carregar a empresa."); onClose(); });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, companyId]);

    const formik = useFormik<any>({
        enableReinitialize: true,
        initialValues: data ?? COMPANY_CREATE_DEFAULTS,
        validationSchema: schema,
        onSubmit: async (values) => {
            setBusy(true);
            try {
                if (isEdit) {
                    await updateCompany(id as number, companyFormData(values, true));
                    toast.success("Empresa atualizada.");
                    onSaved();
                    return;
                }
                const r: any = await createCompany(companyFormData(values, false));
                const newId = Number(r?.data?.id || 0);
                if (values.is_agency && newId) {
                    try { await setAdminCompanyAgency(newId, true); } catch { toast.error("A empresa foi criada, mas não foi possível marcá-la como agência."); }
                }
                toast.success("Empresa criada. Pode ajustar os módulos e a agência nos separadores.");
                onSaved();
                if (newId) {
                    setId(newId);
                    const fresh: any = await getCompany(newId).catch(() => null);
                    if (fresh?.data) setData(fresh.data);
                }
            } catch (e: any) {
                toast.error(companyErrorText(e, isEdit ? "Não foi possível guardar a empresa." : "Não foi possível criar a empresa."));
            } finally {
                setBusy(false);
            }
        },
    });

    const submit = async () => {
        const errors = await formik.validateForm();
        if (Object.keys(errors).length) {
            setTab("dados");
            formik.setTouched(Object.keys(errors).reduce((a, k) => ({ ...a, [k]: true }), {}));
            toast.error(String(Object.values(errors)[0]));
            return;
        }
        formik.submitForm();
    };

    const name = data?.trade_name || data?.fiscal_name || (isEdit ? `Empresa #${id}` : "");
    const logo = logoPreview ?? (data?.logo_path ? `${process.env.REACT_APP_PUBLIC_URL ?? ""}${data.logo_path}` : null);
    const tabs: [Tab, string, string][] = [["dados", "Dados", "ri-building-line"], ["modulos", "Módulos", "ri-apps-2-line"], ["agencia", "Agência", "ri-team-line"]];
    const showSave = !isEdit || tab === "dados";

    return (
        <Modal isOpen={isOpen} toggle={onClose} size="xl" scrollable fullscreen="md" data-testid="company-modal">
            <ModalHeader toggle={onClose}>{isEdit ? `Empresa: ${name}` : "Nova empresa"}</ModalHeader>
            <ModalBody>
                {!data ? <div className="text-center py-5"><Spinner color="primary" /></div> : (
                    <FormikProvider value={formik}>
                        <Nav tabs className="nav-tabs-custom mb-3 flex-nowrap overflow-auto text-nowrap">
                            {tabs.map(([k, l, i]) => (
                                <NavItem key={k}>
                                    <NavLink href="#" active={tab === k} onClick={(e) => { e.preventDefault(); setTab(k); }}><i className={`${i} me-1`} />{l}</NavLink>
                                </NavItem>
                            ))}
                        </Nav>
                        <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
                            <TabContent activeTab={tab}>
                                <TabPane tabId="dados">
                                    <div className="d-flex align-items-center gap-3 mb-3">
                                        {logo
                                            ? <img src={logo} alt="" className="rounded-circle avatar-md object-fit-cover border" />
                                            : <span className="avatar-md rounded-circle bg-light d-inline-flex align-items-center justify-content-center"><i className="ri-image-line fs-22 text-muted" /></span>}
                                        <div>
                                            <Label for="company-logo" className="mb-1">Logótipo</Label>
                                            <Input id="company-logo" type="file" accept="image/*" bsSize="sm" onChange={(e) => {
                                                const file = e.target.files?.[0];
                                                if (!file) return;
                                                formik.setFieldValue("logo_file", file);
                                                setLogoPreview(URL.createObjectURL(file));
                                            }} />
                                        </div>
                                    </div>
                                    {!isEdit && <AgencyCreateFields part="sector" />}
                                    <CompanyGeneralDataFields isEdit={isEdit} managed={!isEdit && !!formik.values.managed_by_company_id} />
                                </TabPane>
                                <TabPane tabId="modulos">
                                    {isEdit
                                        ? tab === "modulos" && <CompanyModulesPanel companyId={id as number} />
                                        : <p className="text-muted mb-0"><i className="ri-information-line me-1" />Ao criar, os módulos ligam-se pelo ramo escolhido em Dados. Depois de criada a empresa, ajuste-os aqui.</p>}
                                </TabPane>
                                <TabPane tabId="agencia">
                                    {isEdit
                                        ? tab === "agencia" && <AgencyManagementPanel companyId={id as number} onChanged={onSaved} />
                                        : <AgencyCreateFields part="agency" />}
                                </TabPane>
                            </TabContent>
                            <button type="submit" hidden aria-hidden tabIndex={-1} />
                        </form>
                    </FormikProvider>
                )}
            </ModalBody>
            <ModalFooter>
                {isEdit && !showSave && <small className="text-muted me-auto">As alterações deste separador ficam guardadas logo.</small>}
                <Button color="light" onClick={onClose}>Fechar</Button>
                {showSave && data && (
                    <Button color="success" disabled={busy} onClick={submit}>
                        {busy ? <Spinner size="sm" /> : isEdit ? "Guardar" : "Criar empresa"}
                    </Button>
                )}
            </ModalFooter>
        </Modal>
    );
}
