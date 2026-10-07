import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
    Badge, Button, Card, CardBody, Col, Container, Input, Label, Modal, ModalBody,
    ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner,
} from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import {
    getCollaborators, setCollaboratorActive, deleteCollaborator, grantCollaboratorAccess, resendCollaboratorInvite,
    cancelCollaboratorInvite, revokeCollaboratorAccess, restoreCollaboratorAccess,
    getDepartments, createDepartment, updateDepartment, deleteDepartment, createSuggestedDepartments,
} from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";
import {
    ACCESS_META, ICollaborator, IDepartment, PHONE_TYPE_LABEL, PhoneType, collaboratorPhoto, initials,
} from "common/models/collaborator.model";
import { getWorkingCompanyId } from "helpers/workingCompany";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu, { MenuAction } from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "pages/Editorial/XSelect";

/**
 * Colaboradores (equipa) e departamentos da empresa. A equipa XPLENDOR em sessão como
 * cliente pode ver e editar o conteúdo; as ações que mexem em contas (dar, reenviar,
 * cancelar ou retirar acesso) são só do administrador da própria empresa.
 */
const readAuth = () => {
    try { return JSON.parse(sessionStorage.getItem("authUser") || "null") ?? {}; } catch { return {}; }
};
const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type DeptDraft = { id?: number; name: string; whatsapp: string; phone: string; phone_type: PhoneType | ""; email: string; active: boolean };
const EMPTY_DEPT: DeptDraft = { name: "", whatsapp: "", phone: "", phone_type: "", email: "", active: true };

const CollaboratorsList = () => {
    document.title = "Colaboradores | Xplendor";
    const navigate = useNavigate();
    const auth = useMemo(readAuth, []);
    const companyId = getWorkingCompanyId();
    const impersonating = !!auth.impersonating;
    const canEdit = auth.role === "admin" || auth.role === "root" || impersonating;
    // Admin da própria empresa; o root conta como admin da SUA empresa. Nunca em impersonation.
    const canManageAccess = (auth.role === "admin" || auth.role === "root") && !impersonating;

    const [tab, setTab] = useState<"team" | "departments">("team");
    const [items, setItems] = useState<ICollaborator[]>([]);
    const [departments, setDepartments] = useState<IDepartment[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("active");
    const [accessModal, setAccessModal] = useState<{ c: ICollaborator; email: string } | null>(null);
    const [deptModal, setDeptModal] = useState<DeptDraft | null>(null);
    const [busy, setBusy] = useState(false);

    const loadDepartments = useCallback(() => {
        if (!companyId) return;
        getDepartments(companyId).then((r: any) => setDepartments(r?.data ?? [])).catch(() => setDepartments([]));
    }, [companyId]);

    const load = useCallback(() => {
        if (!companyId) return;
        setLoading(true);
        getCollaborators(companyId, { search: search.trim() || undefined, status: status || undefined })
            .then((r: any) => setItems(r?.data ?? []))
            .catch(() => setItems([]))
            .finally(() => setLoading(false));
    }, [companyId, search, status]);

    useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t); }, [load]);
    useEffect(() => { loadDepartments(); }, [loadDepartments]);

    const replace = (c: ICollaborator) => setItems((prev) => prev.map((x) => (x.id === c.id ? c : x)));

    const run = async (fn: () => Promise<any>, ok: string, after?: (data: any) => void) => {
        setBusy(true);
        try {
            const r: any = await fn();
            if (after) after(r?.data); else if (r?.data?.id) replace(r.data);
            toast.success(r?.message || ok);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível concluir a ação."));
        } finally {
            setBusy(false);
        }
    };

    const confirmRun = async (title: string, text: string, confirmText: string, fn: () => Promise<any>, ok: string, variant: "danger" | "primary" | "warning" = "primary", after?: (d: any) => void) => {
        if (!(await confirmAction({ title, text, confirmText, icon: variant === "danger" ? "warning" : "question", confirmVariant: variant }))) return;
        await run(fn, ok, after);
    };

    // ── Departamentos ────────────────────────────────────────────────────────
    const saveDepartment = async () => {
        if (!deptModal) return;
        const payload = {
            name: deptModal.name.trim(), whatsapp: deptModal.whatsapp.trim() || null, phone: deptModal.phone.trim() || null,
            phone_type: deptModal.phone.trim() ? (deptModal.phone_type || "mobile") : null, email: deptModal.email.trim() || null, active: deptModal.active,
        };
        await run(
            () => (deptModal.id ? updateDepartment(companyId, deptModal.id, payload) : createDepartment(companyId, payload)),
            "Departamento guardado.",
            () => { setDeptModal(null); loadDepartments(); load(); },
        );
    };

    // Menu "..." de cada colaborador: acesso à plataforma, ativar/desativar e, no fim, as destrutivas.
    const rowActions = (c: ICollaborator): MenuAction[] => [
        { label: "Dar acesso (convite)", icon: "ri-mail-send-line", hidden: !canManageAccess || c.access_status !== "none", onClick: () => setAccessModal({ c, email: c.email ?? "" }) },
        { label: "Reenviar convite", icon: "ri-refresh-line", hidden: !canManageAccess || c.access_status !== "invited", onClick: () => void run(() => resendCollaboratorInvite(companyId, c.id), "Convite reenviado.") },
        { label: "Repor acesso", icon: "ri-lock-unlock-line", hidden: !canManageAccess || c.access_status !== "revoked", onClick: () => void run(() => restoreCollaboratorAccess(companyId, c.id), "Acesso reposto.") },
        c.active
            ? { label: "Desativar", icon: "ri-eye-off-line", hidden: !canEdit, onClick: () => void confirmRun("Desativar o colaborador?", "Deixa de aparecer no site. Se tiver conta na plataforma, o acesso mantém-se até o retirar.", "Desativar", () => setCollaboratorActive(companyId, c.id, false), "Colaborador desativado.", "primary") }
            : { label: "Ativar", icon: "ri-eye-line", hidden: !canEdit, onClick: () => void run(() => setCollaboratorActive(companyId, c.id, true), "Colaborador ativado.") },
        { label: "Cancelar convite", icon: "ri-close-circle-line", danger: true, hidden: !canManageAccess || c.access_status !== "invited", onClick: () => void confirmRun("Cancelar o convite?", `O link enviado para ${c.invite?.email ?? "o email"} deixa de funcionar.`, "Cancelar convite", () => cancelCollaboratorInvite(companyId, c.id), "Convite cancelado.", "danger") },
        { label: "Retirar acesso", icon: "ri-lock-line", danger: true, hidden: !canManageAccess || c.access_status !== "active", onClick: () => void confirmRun("Retirar o acesso?", `${c.name} deixa de conseguir entrar na plataforma e as sessões abertas terminam. O colaborador mantém-se.`, "Retirar acesso", () => revokeCollaboratorAccess(companyId, c.id), "Acesso retirado.", "danger") },
        { label: "Apagar", icon: "ri-delete-bin-line", danger: true, hidden: !canEdit || !!c.user, onClick: () => void confirmRun("Apagar o colaborador?", "O registo e a foto são apagados.", "Apagar", () => deleteCollaborator(companyId, c.id), "Colaborador apagado.", "danger", () => setItems((p) => p.filter((x) => x.id !== c.id))) },
    ];

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Colaboradores" breadcrumbs={[{ label: "Configurações" }]}
                    description="A equipa da empresa. Quem tiver autorização e estiver marcado aparece na secção Equipa do site."
                    actions={canEdit ? (tab === "team" ? (
                        <Link to="/users/collaborators/new" className="btn btn-primary"><i className="ri-add-line me-1" />Novo colaborador</Link>
                    ) : (
                        <>
                            <Button color="outline-primary" disabled={busy} onClick={() => run(() => createSuggestedDepartments(companyId), "Departamentos criados.", () => loadDepartments())}>
                                <i className="ri-magic-line me-1" />Criar departamentos sugeridos
                            </Button>
                            <Button color="primary" onClick={() => setDeptModal({ ...EMPTY_DEPT })}><i className="ri-add-line me-1" />Novo departamento</Button>
                        </>
                    )) : undefined} />

                {impersonating && (
                    <div className="alert alert-info py-2 fs-13">Em sessão como cliente pode editar a equipa e os departamentos. As ações sobre contas (dar ou retirar acesso) ficam reservadas ao administrador da empresa.</div>
                )}

                <Card>
                    <CardBody>
                        <Nav className="nav-tabs nav-border-top nav-border-top-primary mb-3">
                            <NavItem><NavLink href="#" className={tab === "team" ? "active" : ""} onClick={(e) => { e.preventDefault(); setTab("team"); }}>Colaboradores</NavLink></NavItem>
                            <NavItem><NavLink href="#" className={tab === "departments" ? "active" : ""} onClick={(e) => { e.preventDefault(); setTab("departments"); }}>Departamentos</NavLink></NavItem>
                        </Nav>

                        {tab === "team" ? (
                            <>
                                <Row className="g-2 mb-3">
                                    <Col md={8}><Input type="search" placeholder="Pesquisar por nome ou função" value={search} onChange={(e) => setSearch(e.target.value)} /></Col>
                                    <Col md={4}>
                                        <XSelect ariaLabel="Estado" value={status} onChange={setStatus}
                                            options={[{ value: "active", label: "Ativos" }, { value: "inactive", label: "Desativados" }, { value: "", label: "Todos" }]} />
                                    </Col>
                                </Row>
                                {loading ? (
                                    <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                                ) : items.length === 0 ? (
                                    <p className="text-muted mb-0">Sem colaboradores. Crie o primeiro com "Novo colaborador".</p>
                                ) : (
                                    <div className="table-responsive">
                                        <table className="table align-middle table-hover mb-0">
                                            <thead className="table-light text-muted">
                                                <tr><th>Colaborador</th><th>Departamento</th><th>No site</th><th>Acesso</th><th /></tr>
                                            </thead>
                                            <tbody>
                                                {items.map((c) => {
                                                    const photo = collaboratorPhoto(c);
                                                    const am = ACCESS_META[c.access_status];
                                                    return (
                                                        <tr key={c.id} className={c.active ? "" : "opacity-50"}>
                                                            <td style={{ minWidth: 220 }} role="button" onClick={() => navigate(`/users/collaborators/${c.id}`)}>
                                                                <div className="d-flex align-items-center gap-2">
                                                                    {photo ? <img src={photo} alt="" width={36} height={36} className="rounded-circle" style={{ objectFit: "cover" }} />
                                                                        : <span className="avatar-xs"><span className="avatar-title rounded-circle bg-primary-subtle text-primary fs-12">{initials(c.name)}</span></span>}
                                                                    <div>
                                                                        <div className="fw-medium">{c.name}</div>
                                                                        <small className="text-muted">{c.role_title || "Sem função"}{!c.active ? " · desativado" : ""}</small>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>{c.department?.name ?? <span className="text-muted">Sem departamento</span>}</td>
                                                            <td>
                                                                {c.on_site ? <Badge color="success">Sim</Badge>
                                                                    : <span className="text-muted fs-12">{!c.publish_consent_at ? "Sem autorização" : !c.show_on_site ? "Não marcado" : "Desativado"}</span>}
                                                            </td>
                                                            <td><Badge color={am.color} className={am.color === "light" ? "text-body" : ""}>{am.label}</Badge></td>
                                                            <td className="text-end">
                                                                <div className="d-inline-flex gap-1">
                                                                    <Button size="sm" color="outline-primary" onClick={() => navigate(`/users/collaborators/${c.id}`)} aria-label={`${canEdit ? "Editar" : "Ver"}: ${c.name}`}>
                                                                        <i className={canEdit ? "ri-pencil-line" : "ri-eye-line"} />
                                                                    </Button>
                                                                    <ActionsMenu size="sm" label={`Mais ações: ${c.name}`} disabled={busy} items={rowActions(c)} />
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </>
                        ) : (
                            departments.length === 0 ? (
                                <p className="text-muted mb-0">Sem departamentos. Use "Criar departamentos sugeridos" (Comercial, Oficina, Pós-Venda, Admin) ou crie os seus.</p>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table align-middle mb-0">
                                        <thead className="table-light text-muted"><tr><th>Departamento</th><th>Contactos públicos (aparecem no site)</th><th>Colaboradores</th><th /></tr></thead>
                                        <tbody>
                                            {departments.map((d) => (
                                                <tr key={d.id} className={d.active ? "" : "opacity-50"}>
                                                    <td className="fw-medium">{d.name}{!d.active && <small className="text-muted ms-1">(inativo)</small>}</td>
                                                    <td className="fs-13">
                                                        {[d.whatsapp && `WhatsApp ${d.whatsapp}`, d.phone && `${d.phone} (${PHONE_TYPE_LABEL[d.phone_type ?? "mobile"]})`, d.email].filter(Boolean).join(" · ") || <span className="text-muted">Sem contactos</span>}
                                                    </td>
                                                    <td>{d.collaborators_count ?? 0}</td>
                                                    <td className="text-end">
                                                        {canEdit && (
                                                            <div className="d-flex gap-1 justify-content-end">
                                                                <Button size="sm" color="outline-primary" aria-label={`Editar: ${d.name}`} onClick={() => setDeptModal({ id: d.id, name: d.name, whatsapp: d.whatsapp ?? "", phone: d.phone ?? "", phone_type: d.phone_type ?? "", email: d.email ?? "", active: d.active })}><i className="ri-pencil-line" /></Button>
                                                                <ActionsMenu size="sm" label={`Mais ações: ${d.name}`} items={[
                                                                    { label: "Apagar", icon: "ri-delete-bin-line", danger: true, onClick: () => void confirmRun("Apagar o departamento?", "Os colaboradores deste departamento ficam sem departamento.", "Apagar", () => deleteDepartment(companyId, d.id), "Departamento apagado.", "danger", () => { loadDepartments(); load(); }) },
                                                                ]} />
                                                            </div>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )
                        )}
                    </CardBody>
                </Card>

                {/* Dar acesso: convite por email (a pessoa define a password). */}
                <Modal isOpen={accessModal !== null} toggle={() => !busy && setAccessModal(null)} centered>
                    <ModalHeader toggle={() => !busy && setAccessModal(null)}>Dar acesso à plataforma</ModalHeader>
                    {accessModal && (
                        <ModalBody>
                            <p className="text-muted fs-13">Enviamos um convite para {accessModal.c.name} criar a password. O convite é válido durante 7 dias e a conta fica com o perfil de colaborador.</p>
                            <Label className="form-label">Email</Label>
                            <Input type="email" value={accessModal.email} onChange={(e) => setAccessModal({ ...accessModal, email: e.target.value })} />
                        </ModalBody>
                    )}
                    <ModalFooter>
                        <Button color="light" onClick={() => setAccessModal(null)} disabled={busy}>Cancelar</Button>
                        <ReasonButton color="primary" disabled={busy} reason={!accessModal?.email.trim() ? "Indique o email." : null} onClick={async () => {
                            if (!accessModal) return;
                            await run(() => grantCollaboratorAccess(companyId, accessModal.c.id, accessModal.email.trim()), "Convite enviado.", (d) => { if (d?.id) replace(d); setAccessModal(null); });
                        }}>{busy ? <Spinner size="sm" /> : "Enviar convite"}</ReasonButton>
                    </ModalFooter>
                </Modal>

                {/* Departamento */}
                <Modal isOpen={deptModal !== null} toggle={() => !busy && setDeptModal(null)} centered>
                    <ModalHeader toggle={() => !busy && setDeptModal(null)}>{deptModal?.id ? "Editar departamento" : "Novo departamento"}</ModalHeader>
                    {deptModal && (
                        <ModalBody>
                            <Label className="form-label">Nome</Label>
                            <Input className="mb-3" value={deptModal.name} onChange={(e) => setDeptModal({ ...deptModal, name: e.target.value })} placeholder="Ex.: Oficina" />
                            <p className="text-muted fs-12 mb-2">Contactos públicos do departamento: aparecem no site para quem não tem contacto pessoal publicado.</p>
                            <Row className="g-2">
                                <Col xs={12}><Label className="form-label">WhatsApp</Label><Input value={deptModal.whatsapp} onChange={(e) => setDeptModal({ ...deptModal, whatsapp: e.target.value })} placeholder="916 644 780" /></Col>
                                <Col xs={8}><Label className="form-label">Telefone</Label><Input value={deptModal.phone} onChange={(e) => setDeptModal({ ...deptModal, phone: e.target.value })} placeholder="22 998 4130" /></Col>
                                <Col xs={4}>
                                    <Label className="form-label">Tipo</Label>
                                    <XSelect<PhoneType> ariaLabel="Tipo de telefone" value={deptModal.phone_type || null} placeholder="Escolher"
                                        onChange={(v) => setDeptModal({ ...deptModal, phone_type: v })}
                                        options={[{ value: "fixed", label: "Fixo" }, { value: "mobile", label: "Móvel" }]} />
                                </Col>
                                <Col xs={12}><Label className="form-label">Email</Label><Input type="email" value={deptModal.email} onChange={(e) => setDeptModal({ ...deptModal, email: e.target.value })} placeholder="oficina@empresa.pt" /></Col>
                                <Col xs={12}>
                                    <div className="form-check form-switch mt-2">
                                        <Input className="form-check-input" type="switch" id="dept-active" checked={deptModal.active} onChange={(e) => setDeptModal({ ...deptModal, active: e.target.checked })} />
                                        <Label className="form-check-label" for="dept-active">Ativo</Label>
                                    </div>
                                </Col>
                            </Row>
                            {deptModal.phone_type === "fixed" && <small className="text-muted d-block mt-2">Número fixo: o site mostra o aviso legal do custo da chamada.</small>}
                        </ModalBody>
                    )}
                    <ModalFooter>
                        <Button color="light" onClick={() => setDeptModal(null)} disabled={busy}>Cancelar</Button>
                        <ReasonButton color="primary" onClick={() => void saveDepartment()} disabled={busy} reason={!deptModal?.name.trim() ? "Indique o nome." : null}>{busy ? <Spinner size="sm" /> : "Guardar"}</ReasonButton>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
};

export default CollaboratorsList;
