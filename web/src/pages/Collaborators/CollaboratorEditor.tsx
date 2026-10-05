import React, { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Badge, Card, CardBody, CardHeader, Col, Container, Input, Label, Row, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import {
    createCollaborator, getCollaborator, updateCollaborator, uploadCollaboratorPhoto, deleteCollaboratorPhoto, getDepartments,
} from "helpers/laravel_helper";
import { ACCESS_META, ContactMode, ICollaborator, IDepartment, PhoneType, collaboratorPhoto, initials } from "common/models/collaborator.model";

/**
 * Criar ou editar um colaborador. Dados e foto (400x400 WebP, gerada no servidor);
 * site (autorização de publicação, mostrar no site, contacto por departamento ou
 * pessoal com autorização própria). Ao criar: "Criar acesso à plataforma?" (convite
 * por email), só disponível para o administrador da própria empresa.
 */
const readAuth = () => {
    try { return JSON.parse(sessionStorage.getItem("authUser") || "null") ?? {}; } catch { return {}; }
};
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
    const auth = useMemo(readAuth, []);
    const companyId = Number(auth.company_id || 0);
    const impersonating = !!auth.impersonating;
    const canEdit = auth.role === "admin" || auth.role === "root" || impersonating;
    // Admin da própria empresa; o root conta como admin da SUA empresa. Nunca em impersonation.
    const canManageAccess = (auth.role === "admin" || auth.role === "root") && !impersonating;

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
                <Row className="mb-3 align-items-center g-2">
                    <Col>
                        <Link to="/users" className="text-muted fs-13"><i className="ri-arrow-left-line me-1" />Colaboradores</Link>
                        <h4 className="mb-0 mt-1 d-flex align-items-center gap-2 flex-wrap">
                            {isNew ? "Novo colaborador" : collaborator?.name}
                            {am && <Badge color={am.color} className={`fs-12 ${am.color === "light" ? "text-body" : ""}`}>{am.label}</Badge>}
                            {collaborator && !collaborator.active && <Badge color="secondary" className="fs-12">Desativado</Badge>}
                        </h4>
                    </Col>
                    {canEdit && (
                        <Col xs="auto">
                            <button className="btn btn-primary btn-sm" onClick={() => void save()} disabled={saving || !form.name.trim() || (form.create_access && !form.access_email.trim())}>
                                {saving ? <Spinner size="sm" /> : <><i className="ri-save-line me-1" />Guardar</>}
                            </button>
                        </Col>
                    )}
                </Row>

                <fieldset disabled={!canEdit}>
                    <Row className="g-3">
                        <Col xl={8}>
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Dados</h6></CardHeader>
                                <CardBody>
                                    <Row className="g-3">
                                        <Col md={6}><Label className="form-label">Nome</Label><Input value={form.name} onChange={(e) => set("name", e.target.value)} /></Col>
                                        <Col md={6}><Label className="form-label">Função</Label><Input value={form.role_title} onChange={(e) => set("role_title", e.target.value)} placeholder="Ex.: Consultor comercial" /></Col>
                                        <Col md={6}>
                                            <Label className="form-label">Departamento</Label>
                                            <Input type="select" value={form.department_id} onChange={(e) => set("department_id", e.target.value)}>
                                                <option value="">Sem departamento</option>
                                                {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                                            </Input>
                                            {departments.length === 0 && <small className="text-muted">Crie os departamentos no separador "Departamentos" da lista.</small>}
                                        </Col>
                                        <Col md={6}><Label className="form-label">Ordem no site</Label><Input type="number" min={0} value={form.sort} onChange={(e) => set("sort", e.target.value)} /></Col>
                                        <Col xs={12}>
                                            <Label className="form-label">Apresentação</Label>
                                            <Input type="textarea" rows={3} maxLength={BIO_MAX} value={form.bio} onChange={(e) => set("bio", e.target.value)} placeholder="Uma ou duas frases sobre a pessoa (aparece no site)." />
                                            <small className="text-muted">{form.bio.length}/{BIO_MAX}</small>
                                        </Col>
                                    </Row>
                                </CardBody>
                            </Card>

                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Contactos pessoais</h6></CardHeader>
                                <CardBody>
                                    <p className="text-muted fs-13">Só aparecem no site se a pessoa autorizar (secção Site). Sem essa autorização, o site mostra os contactos do departamento.</p>
                                    <Row className="g-3">
                                        <Col md={6}><Label className="form-label">WhatsApp</Label><Input value={form.whatsapp} onChange={(e) => set("whatsapp", e.target.value)} placeholder="912 345 678" /></Col>
                                        <Col md={6}><Label className="form-label">Email</Label><Input type="email" value={form.email} onChange={(e) => set("email", e.target.value)} /></Col>
                                        <Col md={8}><Label className="form-label">Telefone</Label><Input value={form.phone} onChange={(e) => set("phone", e.target.value)} /></Col>
                                        <Col md={4}>
                                            <Label className="form-label">Tipo</Label>
                                            <Input type="select" value={form.phone_type} onChange={(e) => set("phone_type", e.target.value as PhoneType)}>
                                                <option value="">Escolher</option>
                                                <option value="mobile">Móvel</option>
                                                <option value="fixed">Fixo</option>
                                            </Input>
                                        </Col>
                                    </Row>
                                </CardBody>
                            </Card>

                            {isNew && (
                                <Card className="mb-3">
                                    <CardHeader><h6 className="mb-0">Acesso à plataforma</h6></CardHeader>
                                    <CardBody>
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
                                    </CardBody>
                                </Card>
                            )}
                        </Col>

                        <Col xl={4}>
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Foto</h6></CardHeader>
                                <CardBody className="text-center">
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
                                        <button type="button" className="btn btn-link btn-sm text-danger mt-1" onClick={() => void removePhoto()}>Remover foto</button>
                                    )}
                                </CardBody>
                            </Card>

                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Site (secção Equipa)</h6></CardHeader>
                                <CardBody>
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
                                </CardBody>
                            </Card>
                        </Col>
                    </Row>
                </fieldset>
            </Container>
        </div>
    );
};

export default CollaboratorEditor;
