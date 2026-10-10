import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Badge, Col, Container, Input, Label, Row, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import {
    createCollaborator, getCollaborator, updateCollaborator, uploadCollaboratorPhoto, deleteCollaboratorPhoto, getDepartments,
} from "helpers/laravel_helper";
import { ACCESS_META, ContactMode, ICollaborator, IDepartment, PhoneType, collaboratorPhoto, initials } from "common/models/collaborator.model";
import { getWorkingCompanyId } from "helpers/workingCompany";
import { confirmAction } from "helpers/swal";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import ReasonButton from "Components/Common/ReasonButton";
import { useModules } from "contexts/ModulesContext";
import XSelect from "pages/Editorial/XSelect";

/**
 * Criar ou editar um colaborador. Dados e foto (400x400 WebP, gerada no servidor);
 * site (autorização de publicação, mostrar no site, contacto por departamento ou
 * pessoal com autorização própria). Ao criar: "Criar acesso à plataforma?" (convite
 * por email), só disponível para o administrador da própria empresa.
 */
const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "numeric", month: "long", year: "numeric" }) : "");

interface Form {
    name: string; role_title: string; bio: string; department_id: string;
    whatsapp: string; phone: string; phone_type: PhoneType | ""; email: string;
    contact_mode: ContactMode; show_on_site: boolean; publish_consent: boolean; personal_contact_consent: boolean; sort: string;
    create_access: boolean; access_email: string;
}
const EMPTY: Form = {
    name: "", role_title: "", bio: "", department_id: "", whatsapp: "", phone: "", phone_type: "", email: "",
    contact_mode: "department", show_on_site: false, publish_consent: false, personal_contact_consent: false, sort: "0",
    create_access: false, access_email: "",
};
const fromCollaborator = (c: ICollaborator): Form => ({
    name: c.name, role_title: c.role_title ?? "", bio: c.bio ?? "", department_id: c.department_id ? String(c.department_id) : "",
    whatsapp: c.whatsapp ?? "", phone: c.phone ?? "", phone_type: c.phone_type ?? "", email: c.email ?? "",
    contact_mode: c.contact_mode, show_on_site: c.show_on_site, publish_consent: !!c.publish_consent_at,
    personal_contact_consent: !!c.personal_contact_consent_at, sort: String(c.sort ?? 0), create_access: false, access_email: "",
});

const BIO_MAX = 600;

const CollaboratorEditor = () => {
    const { id } = useParams();
    const navigate = useNavigate();
    const isNew = !id || id === "new";
    const companyId = getWorkingCompanyId();
    // ACL (F4): as permissões vêm do backend (/my-access), com as mesmas regras da API
    // (em sessão como cliente, a equipa edita os conteúdos mas nunca gere os acessos).
    const { can } = useModules();
    const canEdit = can("utilizadores.editar");
    const canManageAccess = can("utilizadores.configurar");

    const [collaborator, setCollaborator] = useState<ICollaborator | null>(null);
    const [form, setForm] = useState<Form>(EMPTY);
    const [departments, setDepartments] = useState<IDepartment[]>([]);
    const [photoFile, setPhotoFile] = useState<File | null>(null);
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const [loading, setLoading] = useState(!isNew);
    const [saving, setSaving] = useState(false);

    document.title = `${isNew ? "Novo colaborador" : collaborator?.name ?? "Colaborador"} | Xplendor`;

    useEffect(() => {
        if (!companyId) return;
        getDepartments(companyId).then((r: any) => setDepartments((r?.data ?? []).filter((d: IDepartment) => d.active))).catch(() => setDepartments([]));
        if (isNew) return;
        setLoading(true);
        getCollaborator(companyId, Number(id))
            .then((r: any) => { setCollaborator(r.data); setForm(fromCollaborator(r.data)); })
            .catch(() => toast.error("Colaborador não encontrado."))
            .finally(() => setLoading(false));
    }, [companyId, id, isNew]);

    useEffect(() => () => { if (photoPreview) URL.revokeObjectURL(photoPreview); }, [photoPreview]);

    const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }));

    const save = async () => {
        setSaving(true);
        const payload: any = {
            name: form.name.trim(), role_title: form.role_title.trim() || null, bio: form.bio.trim() || null,
            department_id: form.department_id ? Number(form.department_id) : null,
            whatsapp: form.whatsapp.trim() || null, phone: form.phone.trim() || null,
            phone_type: form.phone.trim() ? (form.phone_type || "mobile") : null, email: form.email.trim() || null,
            publish_consent: form.publish_consent, personal_contact_consent: form.personal_contact_consent,
            show_on_site: form.publish_consent && form.show_on_site,
            contact_mode: form.contact_mode === "personal" && form.personal_contact_consent ? "personal" : "department",
            sort: Number(form.sort || 0),
        };
        if (isNew && form.create_access) {
            payload.create_access = true;
            payload.access_email = form.access_email.trim();
        }
        try {
            const r: any = isNew ? await createCollaborator(companyId, payload) : await updateCollaborator(companyId, Number(id), payload);
            let saved: ICollaborator = r.data;
            if (photoFile) {
                const p: any = await uploadCollaboratorPhoto(companyId, saved.id, photoFile);
                saved = p.data;
                setPhotoFile(null);
                setPhotoPreview(null);
            }
            setCollaborator(saved);
            setForm(fromCollaborator(saved));
            toast.success(isNew && form.create_access ? "Colaborador criado e convite enviado." : "Colaborador guardado.");
            if (isNew) navigate(`/users/collaborators/${saved.id}`, { replace: true });
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar o colaborador."));
        } finally {
            setSaving(false);
        }
    };

    const removePhoto = async () => {
        if (!collaborator) return;
        if (!(await confirmAction({ title: "Remover a foto?", text: "A foto deixa de aparecer no site.", confirmText: "Remover", icon: "warning", confirmVariant: "danger" }))) return;
        try {
            const r: any = await deleteCollaboratorPhoto(companyId, collaborator.id);
            setCollaborator(r.data);
            toast.success("Foto removida.");
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível remover a foto."));
        }
    };

    if (loading) {
        return <div className="page-content"><Container fluid><div className="text-center py-5"><Spinner color="primary" /></div></Container></div>;
    }

    const currentPhoto = photoPreview || (collaborator ? collaboratorPhoto(collaborator) : null);
    const selectedDept = departments.find((d) => String(d.id) === form.department_id);
    const am = collaborator ? ACCESS_META[collaborator.access_status] : null;

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title={isNew ? "Novo colaborador" : collaborator?.name ?? "Colaborador"}
                    breadcrumbs={[{ label: "Configurações" }, { label: "Colaboradores", to: "/users" }]}
                    crumbLabel={isNew ? "Novo" : "Colaborador"} />

                <fieldset disabled={!canEdit}>
                    <Row className="g-3">
                        <Col xl={8}>
                            <PageCard className="mb-3" title="Dados" flush={false}
                                status={(am || (collaborator && !collaborator.active)) ? (
                                    <span className="d-inline-flex align-items-center gap-2 flex-wrap">
                                        {am && <Badge color={am.color} className={`fs-12 ${am.color === "light" ? "text-body" : ""}`}>{am.label}</Badge>}
                                        {collaborator && !collaborator.active && <Badge color="light" className="fs-12 text-body">Desativado</Badge>}
                                    </span>
                                ) : undefined}
                                actions={canEdit ? (
                                    <ReasonButton size="sm" color="primary" onClick={() => void save()} disabled={saving}
                                        reason={!form.name.trim() ? "Indique o nome." : form.create_access && !form.access_email.trim() ? "Indique o email para o convite." : null}>
                                        {saving ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />Guardar</>}
                                    </ReasonButton>
                                ) : undefined}>
                                    <Row className="g-3">
                                        <Col md={6}><Label className="form-label">Nome</Label><Input value={form.name} onChange={(e) => set("name", e.target.value)} /></Col>
                                        <Col md={6}><Label className="form-label">Função</Label><Input value={form.role_title} onChange={(e) => set("role_title", e.target.value)} placeholder="Ex.: Consultor comercial" /></Col>
                                        <Col md={6}>
                                            <Label className="form-label">Departamento</Label>
                                            <XSelect ariaLabel="Departamento" value={form.department_id} onChange={(v) => set("department_id", v)} disabled={!canEdit}
                                                options={[{ value: "", label: "Sem departamento" }, ...departments.map((d) => ({ value: String(d.id), label: d.name }))]} />
                                            {departments.length === 0 && <small className="text-muted">Crie os departamentos no separador "Departamentos" da lista.</small>}
                                        </Col>
                                        <Col md={6}><Label className="form-label">Ordem no site</Label><Input type="number" min={0} value={form.sort} onChange={(e) => set("sort", e.target.value)} /></Col>
                                        <Col xs={12}>
                                            <Label className="form-label">Apresentação</Label>
                                            <Input type="textarea" rows={3} maxLength={BIO_MAX} value={form.bio} onChange={(e) => set("bio", e.target.value)} placeholder="Uma ou duas frases sobre a pessoa (aparece no site)." />
                                            <small className="text-muted">{form.bio.length}/{BIO_MAX}</small>
                                        </Col>
                                    </Row>
                            </PageCard>

                            <PageCard className="mb-3" title="Contactos pessoais" flush={false} info="Só aparecem no site se a pessoa autorizar (secção Site). Sem essa autorização, o site mostra os contactos do departamento.">
                                    <Row className="g-3">
                                        <Col md={6}><Label className="form-label">WhatsApp</Label><Input value={form.whatsapp} onChange={(e) => set("whatsapp", e.target.value)} placeholder="912 345 678" /></Col>
                                        <Col md={6}><Label className="form-label">Email</Label><Input type="email" value={form.email} onChange={(e) => set("email", e.target.value)} /></Col>
                                        <Col md={8}><Label className="form-label">Telefone</Label><Input value={form.phone} onChange={(e) => set("phone", e.target.value)} /></Col>
                                        <Col md={4}>
                                            <Label className="form-label">Tipo</Label>
                                            <XSelect<PhoneType> ariaLabel="Tipo de telefone" value={form.phone_type || null} placeholder="Escolher" disabled={!canEdit}
                                                onChange={(v) => set("phone_type", v)} options={[{ value: "mobile", label: "Móvel" }, { value: "fixed", label: "Fixo" }]} />
                                        </Col>
                                    </Row>
                            </PageCard>

                            {isNew && (
                                <PageCard className="mb-3" title="Acesso à plataforma" flush={false}>
                                        {canManageAccess ? (
                                            <>
                                                <Label className="form-label d-block">Criar acesso à plataforma para este colaborador?</Label>
                                                <div className="d-flex gap-3 mb-2">
                                                    <div className="form-check">
                                                        <Input className="form-check-input" type="radio" id="acc-no" checked={!form.create_access} onChange={() => set("create_access", false)} />
                                                        <Label className="form-check-label" for="acc-no">Não, só o registo</Label>
                                                    </div>
                                                    <div className="form-check">
                                                        <Input className="form-check-input" type="radio" id="acc-yes" checked={form.create_access} onChange={() => set("create_access", true)} />
                                                        <Label className="form-check-label" for="acc-yes">Sim, enviar convite</Label>
                                                    </div>
                                                </div>
                                                {form.create_access && (
                                                    <>
                                                        <Label className="form-label">Email para o convite</Label>
                                                        <Input type="email" value={form.access_email} onChange={(e) => set("access_email", e.target.value)} />
                                                        <small className="text-muted">A pessoa recebe um link para definir a password (válido 7 dias) e fica com o perfil de colaborador.</small>
                                                    </>
                                                )}
                                            </>
                                        ) : (
                                            <p className="text-muted fs-13 mb-0">O acesso à plataforma é dado depois, pelo administrador da empresa, na lista de colaboradores.</p>
                                        )}
                                </PageCard>
                            )}
                        </Col>

                        <Col xl={4}>
                            <PageCard className="mb-3" bodyClassName="text-center" title="Foto" flush={false}>
                                    {currentPhoto
                                        ? <img src={currentPhoto} alt="" width={160} height={160} className="rounded-circle mb-3" style={{ objectFit: "cover" }} />
                                        : <div className="avatar-xl mx-auto mb-3"><span className="avatar-title rounded-circle bg-primary-subtle text-primary fs-24">{initials(form.name || "?")}</span></div>}
                                    <Input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => {
                                        const f = e.target.files?.[0] ?? null;
                                        setPhotoFile(f);
                                        setPhotoPreview(f ? URL.createObjectURL(f) : null);
                                    }} />
                                    <small className="text-muted d-block mt-2">Fica quadrada, com 400 x 400 píxeis. O original não é guardado.</small>
                                    {collaborator?.photo_path && !photoFile && canEdit && (
                                        <button type="button" className="btn btn-link btn-sm mt-1" onClick={() => void removePhoto()}>Remover foto</button>
                                    )}
                            </PageCard>

                            <PageCard className="mb-3" title="Site (secção Equipa)" flush={false}>
                                    <div className="form-check mb-2">
                                        <Input className="form-check-input" type="checkbox" id="consent" checked={form.publish_consent}
                                            onChange={(e) => setForm((f) => ({ ...f, publish_consent: e.target.checked, show_on_site: e.target.checked ? f.show_on_site : false }))} />
                                        <Label className="form-check-label" for="consent">A pessoa autorizou a publicação no site</Label>
                                        {collaborator?.publish_consent_at && form.publish_consent && <small className="text-muted d-block">Registado a {fmtDate(collaborator.publish_consent_at)}</small>}
                                    </div>
                                    <div className="form-check form-switch mb-3">
                                        <Input className="form-check-input" type="switch" id="onsite" disabled={!form.publish_consent} checked={form.show_on_site} onChange={(e) => set("show_on_site", e.target.checked)} />
                                        <Label className="form-check-label" for="onsite">Mostrar no site</Label>
                                        {!form.publish_consent && <small className="text-muted d-block">Sem autorização, a pessoa não aparece no site.</small>}
                                    </div>

                                    <Label className="form-label">Contacto no site</Label>
                                    <div className="form-check">
                                        <Input className="form-check-input" type="radio" id="cm-dept" checked={form.contact_mode === "department"} onChange={() => set("contact_mode", "department")} />
                                        <Label className="form-check-label" for="cm-dept">Do departamento{selectedDept ? ` (${selectedDept.name})` : ""}</Label>
                                    </div>
                                    <div className="form-check">
                                        <Input className="form-check-input" type="radio" id="cm-personal" checked={form.contact_mode === "personal"} onChange={() => set("contact_mode", "personal")} />
                                        <Label className="form-check-label" for="cm-personal">Pessoal</Label>
                                    </div>
                                    {form.contact_mode === "personal" && (
                                        <div className="form-check mt-2 ms-3">
                                            <Input className="form-check-input" type="checkbox" id="pconsent" checked={form.personal_contact_consent} onChange={(e) => set("personal_contact_consent", e.target.checked)} />
                                            <Label className="form-check-label fs-13" for="pconsent">A pessoa autorizou a publicação do contacto pessoal</Label>
                                            {collaborator?.personal_contact_consent_at && form.personal_contact_consent && <small className="text-muted d-block">Registado a {fmtDate(collaborator.personal_contact_consent_at)}</small>}
                                            {!form.personal_contact_consent && <small className="text-warning d-block">Sem esta autorização, o site mostra o contacto do departamento.</small>}
                                        </div>
                                    )}
                                    {form.phone_type === "fixed" && form.contact_mode === "personal" && <small className="text-muted d-block mt-2">Número fixo: o site mostra o aviso legal do custo da chamada.</small>}
                            </PageCard>
                        </Col>
                    </Row>
                </fieldset>
            </Container>
        </div>
    );
};

export default CollaboratorEditor;
