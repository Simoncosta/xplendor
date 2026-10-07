import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
    Badge, Card, CardBody, Col, Container, DropdownItem, DropdownMenu, DropdownToggle, Input, Label, Modal, ModalBody,
    ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner, UncontrolledDropdown,
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

    const accessActions = (c: ICollaborator) => {
        if (!canManageAccess) return null;
        const items: React.ReactNode[] = [<DropdownItem key="h" header>Acesso à plataforma</DropdownItem>];
        if (c.access_status === "none") {
            items.push(<DropdownItem key="g" onClick={() => setAccessModal({ c, email: c.email ?? "" })}><i className="ri-mail-send-line me-2" />Dar acesso (convite)</DropdownItem>);
        }
        if (c.access_status === "invited") {
            items.push(<DropdownItem key="r" onClick={() => run(() => resendCollaboratorInvite(companyId, c.id), "Convite reenviado.")}><i className="ri-refresh-line me-2" />Reenviar convite</DropdownItem>);
            items.push(<DropdownItem key="c" onClick={() => confirmRun("Cancelar o convite?", `O link enviado para ${c.invite?.email ?? "o email"} deixa de funcionar.`, "Cancelar convite", () => cancelCollaboratorInvite(companyId, c.id), "Convite cancelado.", "warning")}><i className="ri-close-circle-line me-2" />Cancelar convite</DropdownItem>);
        }
        if (c.access_status === "active") {
            items.push(<DropdownItem key="v" className="text-danger" onClick={() => confirmRun("Retirar o acesso?", `${c.name} deixa de conseguir entrar na plataforma e as sessões abertas terminam. O colaborador mantém-se.`, "Retirar acesso", () => revokeCollaboratorAccess(companyId, c.id), "Acesso retirado.", "danger")}><i className="ri-lock-line me-2" />Retirar acesso</DropdownItem>);
        }
        if (c.access_status === "revoked") {
            items.push(<DropdownItem key="s" onClick={() => run(() => restoreCollaboratorAccess(companyId, c.id), "Acesso reposto.")}><i className="ri-lock-unlock-line me-2" />Repor acesso</DropdownItem>);
        }
        items.push(<DropdownItem key="d" divider />);
        return items;
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3 align-items-center g-2">
                    <Col>
                        <h4 className="mb-1"><i className="ri-team-line text-primary me-2" />Colaboradores</h4>
                        <p className="text-muted mb-0">A equipa da empresa. Quem tiver autorização e estiver marcado aparece na secção Equipa do site.</p>
                    </Col>
                    {canEdit && tab === "team" && (
                        <Col xs="auto"><Link to="/users/collaborators/new" className="btn btn-primary btn-sm"><i className="ri-add-line me-1" />Novo colaborador</Link></Col>
                    )}
                    {canEdit && tab === "departments" && (
                        <Col xs="auto" className="d-flex gap-2">
                            <button className="btn btn-soft-secondary btn-sm" disabled={busy} onClick={() => run(() => createSuggestedDepartments(companyId), "Departamentos criados.", () => loadDepartments())}>
                                <i className="ri-magic-line me-1" />Criar departamentos sugeridos
                            </button>
                            <button className="btn btn-primary btn-sm" onClick={() => setDeptModal({ ...EMPTY_DEPT })}><i className="ri-add-line me-1" />Novo departamento</button>
                        </Col>
                    )}
                </Row>

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
                                        <Input type="select" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Estado">
                                            <option value="active">Ativos</option>
                                            <option value="inactive">Desativados</option>
                                            <option value="">Todos</option>
                                        </Input>
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
                                                                <UncontrolledDropdown>
                                                                    <DropdownToggle tag="button" className="btn btn-soft-secondary btn-sm" disabled={busy}><i className="ri-more-fill" /></DropdownToggle>
                                                                    <DropdownMenu end container="body">
                                                                        <DropdownItem onClick={() => navigate(`/users/collaborators/${c.id}`)}><i className="ri-edit-line me-2" />{canEdit ? "Editar" : "Ver"}</DropdownItem>
                                                                        {canEdit && <DropdownItem divider />}
                                                                        {accessActions(c)}
                                                                        {canEdit && (c.active
                                                                            ? <DropdownItem onClick={() => confirmRun("Desativar o colaborador?", "Deixa de aparecer no site. Se tiver conta na plataforma, o acesso mantém-se até o retirar.", "Desativar", () => setCollaboratorActive(companyId, c.id, false), "Colaborador desativado.", "warning")}><i className="ri-eye-off-line me-2" />Desativar</DropdownItem>
                                                                            : <DropdownItem onClick={() => run(() => setCollaboratorActive(companyId, c.id, true), "Colaborador ativado.")}><i className="ri-eye-line me-2" />Ativar</DropdownItem>)}
                                                                        {canEdit && !c.user && (
                                                                            <DropdownItem className="text-danger" onClick={() => confirmRun("Apagar o colaborador?", "O registo e a foto são apagados.", "Apagar", () => deleteCollaborator(companyId, c.id), "Colaborador apagado.", "danger", () => setItems((p) => p.filter((x) => x.id !== c.id)))}>
                                                                                <i className="ri-delete-bin-line me-2" />Apagar
                                                                            </DropdownItem>
                                                                        )}
                                                                    </DropdownMenu>
                                                                </UncontrolledDropdown>
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
                                                                <button className="btn btn-soft-secondary btn-sm" onClick={() => setDeptModal({ id: d.id, name: d.name, whatsapp: d.whatsapp ?? "", phone: d.phone ?? "", phone_type: d.phone_type ?? "", email: d.email ?? "", active: d.active })}><i className="ri-edit-line" /></button>
                                                                <button className="btn btn-soft-danger btn-sm" onClick={() => confirmRun("Apagar o departamento?", "Os colaboradores deste departamento ficam sem departamento.", "Apagar", () => deleteDepartment(companyId, d.id), "Departamento apagado.", "danger", () => { loadDepartments(); load(); })}><i className="ri-delete-bin-line" /></button>
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
                        <button className="btn btn-light" onClick={() => setAccessModal(null)} disabled={busy}>Cancelar</button>
                        <button className="btn btn-primary" disabled={busy || !accessModal?.email.trim()} onClick={async () => {
                            if (!accessModal) return;
                            await run(() => grantCollaboratorAccess(companyId, accessModal.c.id, accessModal.email.trim()), "Convite enviado.", (d) => { if (d?.id) replace(d); setAccessModal(null); });
                        }}>{busy ? <Spinner size="sm" /> : "Enviar convite"}</button>
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
                                    <Input type="select" value={deptModal.phone_type} onChange={(e) => setDeptModal({ ...deptModal, phone_type: e.target.value as PhoneType })}>
                                        <option value="">Escolher</option>
                                        <option value="fixed">Fixo</option>
                                        <option value="mobile">Móvel</option>
                                    </Input>
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
                        <button className="btn btn-light" onClick={() => setDeptModal(null)} disabled={busy}>Cancelar</button>
                        <button className="btn btn-primary" onClick={() => void saveDepartment()} disabled={busy || !deptModal?.name.trim()}>{busy ? <Spinner size="sm" /> : "Guardar"}</button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
};

export default CollaboratorsList;
