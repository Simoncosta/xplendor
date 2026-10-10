import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
    Badge, Button, Col, Container, Input, Label, Modal, ModalBody,
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
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import RestFilterBar from "Components/Common/RestFilterBar";
import ActionsMenu, { MenuAction } from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import { useModules } from "contexts/ModulesContext";
import XSelect from "Components/Common/Select";

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
    // ACL (F4): as permissões vêm do backend (/my-access), com as mesmas regras da API
    // (em sessão como cliente, a equipa edita os conteúdos mas nunca gere os acessos).
    const { can, reason } = useModules();
    const canEdit = can("utilizadores.editar");
    const canManageAccess = can("utilizadores.configurar");

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

    const teamCols = useDataColumns<ICollaborator>("configuracoes.colaboradores", [
        {
            id: "name", header: "Colaborador", value: (c) => `${c.name} ${c.role_title ?? ""}`, hideable: false, mobile: "title",
            cell: (c) => {
                const photo = collaboratorPhoto(c);
                return (
                    <div className="d-flex align-items-center gap-2" style={{ minWidth: 200 }}>
                        {photo ? <img src={photo} alt="" width={36} height={36} className="rounded-circle" style={{ objectFit: "cover" }} />
                            : <span className="avatar-xs"><span className="avatar-title rounded-circle bg-primary-subtle text-primary fs-12">{initials(c.name)}</span></span>}
                        <div>
                            <div className="fw-medium">{c.name}</div>
                            <small className="text-muted">{c.role_title || "Sem função"}{!c.active ? " · desativado" : ""}</small>
                        </div>
                    </div>
                );
            },
        },
        { id: "department", header: "Departamento", value: (c) => c.department?.name ?? "", cell: (c) => c.department?.name ?? <span className="text-muted">Sem departamento</span> },
        {
            id: "site", header: "No site", value: (c) => (c.on_site ? 1 : 0),
            cell: (c) => c.on_site ? <Badge color="success">Sim</Badge>
                : <span className="text-muted fs-12">{!c.publish_consent_at ? "Sem autorização" : !c.show_on_site ? "Não marcado" : "Desativado"}</span>,
        },
        {
            id: "access", header: "Acesso", value: (c) => ACCESS_META[c.access_status].label,
            cell: (c) => { const am = ACCESS_META[c.access_status]; return <Badge color={am.color} className={am.color === "light" ? "text-body" : ""}>{am.label}</Badge>; },
        },
    ] as DTColumn<ICollaborator>[]);

    const deptCols = useDataColumns<IDepartment>("configuracoes.departamentos", [
        { id: "name", header: "Departamento", value: (d) => d.name, hideable: false, mobile: "title", cell: (d) => <span className="fw-medium">{d.name}{!d.active && <small className="text-muted ms-1">(inativo)</small>}</span> },
        {
            id: "contacts", header: "Contactos públicos (aparecem no site)",
            value: (d) => [d.whatsapp, d.phone, d.email].filter(Boolean).join(" "),
            cell: (d) => <span className="fs-13">{[d.whatsapp && `WhatsApp ${d.whatsapp}`, d.phone && `${d.phone} (${PHONE_TYPE_LABEL[d.phone_type ?? "mobile"]})`, d.email].filter(Boolean).join(" · ") || <span className="text-muted">Sem contactos</span>}</span>,
        },
        { id: "count", header: "Colaboradores", value: (d) => d.collaborators_count ?? 0, align: "end" },
    ] as DTColumn<IDepartment>[]);

    const tabs = (
        <Nav className="nav-tabs-custom mb-3" tabs>
            <NavItem><NavLink href="#" className={tab === "team" ? "active" : ""} onClick={(e) => { e.preventDefault(); setTab("team"); }}>Colaboradores</NavLink></NavItem>
            <NavItem><NavLink href="#" className={tab === "departments" ? "active" : ""} onClick={(e) => { e.preventDefault(); setTab("departments"); }}>Departamentos</NavLink></NavItem>
        </Nav>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Colaboradores" breadcrumbs={[{ label: "Configurações" }]}
                    info="A equipa da empresa. Quem tiver autorização e estiver marcado aparece na secção Equipa do site." />

                {!canEdit && !loading && (
                    <p className="text-muted fs-13 mb-3"><i className="ri-lock-line me-1" />{reason("utilizadores.editar")}</p>
                )}
                {impersonating && (
                    <div className="alert alert-info py-2 fs-13">Em sessão como cliente pode editar a equipa e os departamentos. As ações sobre contas (dar ou retirar acesso) ficam reservadas ao administrador da empresa.</div>
                )}

                {tabs}

                {/* Um cartão por separador, cada um com as suas ações (design-system §2). */}
                {tab === "team" ? (
                    <PageCard
                        title="Colaboradores"
                        status={!loading ? <>{items.length} colaborador{items.length === 1 ? "" : "es"}</> : undefined}
                        loading={loading && items.length > 0}
                        actions={<>
                            {teamCols.selector}
                            {canEdit && <Link to="/users/collaborators/new" className="btn btn-primary btn-sm"><i className="ri-add-line me-1" />Novo colaborador</Link>}
                        </>}
                        filters={
                            <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar por nome ou função"
                                activeCount={(search ? 1 : 0) + (status !== "active" ? 1 : 0)} onClear={() => { setSearch(""); setStatus("active"); }}>
                                <div style={{ flex: "0 1 200px", minWidth: 0 }}>
                                    <XSelect small ariaLabel="Estado" value={status} onChange={setStatus} searchable={false}
                                        options={[{ value: "active", label: "Ativos" }, { value: "inactive", label: "Desativados" }, { value: "", label: "Todos" }]} />
                                </div>
                            </RestFilterBar>
                        }
                    >
                        <DataTable
                            columns={teamCols}
                            data={items}
                            rowKey={(c) => c.id}
                            loading={loading}
                            caption="Colaboradores"
                            rowClassName={(c) => (c.active ? undefined : "opacity-50")}
                            onRowClick={(c) => navigate(`/users/collaborators/${c.id}`)}
                            empty={{ message: <>Sem colaboradores. Crie o primeiro com "Novo colaborador".</> }}
                            rowActions={(c) => (
                                <>
                                    <Button size="sm" color="outline-primary" onClick={() => navigate(`/users/collaborators/${c.id}`)} aria-label={`${canEdit ? "Editar" : "Ver"}: ${c.name}`}>
                                        <i className={canEdit ? "ri-pencil-line" : "ri-eye-line"} />
                                    </Button>
                                    <ActionsMenu size="sm" label={`Mais ações: ${c.name}`} disabled={busy} items={rowActions(c)} />
                                </>
                            )}
                        />
                    </PageCard>
                ) : (
                    <PageCard
                        title="Departamentos"
                        status={<>{departments.length} departamento{departments.length === 1 ? "" : "s"}</>}
                        actions={<>
                            {deptCols.selector}
                            {canEdit && (
                                <>
                                    <Button size="sm" color="outline-primary" disabled={busy} onClick={() => run(() => createSuggestedDepartments(companyId), "Departamentos criados.", () => loadDepartments())}>
                                        <i className="ri-magic-line me-1" />Criar departamentos sugeridos
                                    </Button>
                                    <Button size="sm" color="primary" onClick={() => setDeptModal({ ...EMPTY_DEPT })}><i className="ri-add-line me-1" />Novo departamento</Button>
                                </>
                            )}
                        </>}
                    >
                        <DataTable
                            columns={deptCols}
                            data={departments}
                            rowKey={(d) => d.id}
                            caption="Departamentos"
                            rowClassName={(d) => (d.active ? undefined : "opacity-50")}
                            empty={{ message: <>Sem departamentos. Use "Criar departamentos sugeridos" (Comercial, Oficina, Pós-Venda, Admin) ou crie os seus.</> }}
                            rowActions={canEdit ? (d) => (
                                <>
                                    <Button size="sm" color="outline-primary" aria-label={`Editar: ${d.name}`} onClick={() => setDeptModal({ id: d.id, name: d.name, whatsapp: d.whatsapp ?? "", phone: d.phone ?? "", phone_type: d.phone_type ?? "", email: d.email ?? "", active: d.active })}><i className="ri-pencil-line" /></Button>
                                    <ActionsMenu size="sm" label={`Mais ações: ${d.name}`} items={[
                                        { label: "Apagar", icon: "ri-delete-bin-line", danger: true, onClick: () => void confirmRun("Apagar o departamento?", "Os colaboradores deste departamento ficam sem departamento.", "Apagar", () => deleteDepartment(companyId, d.id), "Departamento apagado.", "danger", () => { loadDepartments(); load(); }) },
                                    ]} />
                                </>
                            ) : undefined}
                        />
                    </PageCard>
                )}

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
