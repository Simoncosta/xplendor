import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import Select from "react-select";
import { Badge, Button, Card, CardBody, CardHeader, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import BreadCrumb from "Components/Common/BreadCrumb";
import ClientMark from "Components/Common/ClientMark";
import { useWorkingCompany } from "contexts/WorkingCompanyContext";
import {
    createAgencyCompanyRequest, getAgencyAssignments, getAgencyCompanyRequests, getAgencyPanel, getEditorialSectors, setAgencyAssignment,
} from "helpers/laravel_helper";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import { readAuthUser } from "helpers/impersonation";
import XSelect from "pages/Editorial/XSelect";

/**
 * Painel da agência (a equipa da agência e o root, no contexto da agência): por cliente, o
 * que está para publicar hoje, atrasado, à espera de aprovação e em produção (cada número
 * abre a Linha Editorial já filtrada); os pedidos de nova empresa gerida; e as atribuições
 * (só o administrador da agência). O dashboard do root continua o da plataforma.
 */
type Client = { id: number; name: string; logo_path: string | null; is_agency: boolean };
type PanelRow = { company: Client; today: number; overdue: number; awaiting: number; production: number };
type Req = {
    id: number; status: "pending" | "approved" | "declined"; name: string; sector: { id: number; name: string } | null;
    contact_name: string | null; contact_email: string | null; contact_phone: string | null; note: string | null;
    requested_by: string | null; requested_at: string | null; decided_by: string | null; decided_at: string | null; decline_reason: string | null;
};
type Member = { id: number; name: string; role: string };
type Assignment = { company: Client; team_scope: "all" | "assigned"; member_ids: number[] };
type Tab = "clientes" | "pedidos" | "atribuicoes";

const STATUS: Record<Req["status"], { label: string; color: string }> = {
    pending: { label: "Por decidir", color: "warning" },
    approved: { label: "Aprovado", color: "success" },
    declined: { label: "Recusado", color: "danger" },
};
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
const thisMonth = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" }).slice(0, 7);
const errorText = (e: any, fallback: string) => {
    const errs = e?.errors ? Object.values(e.errors).flat() : [];
    return String(errs[0] ?? e?.message ?? fallback);
};
const emptyForm = { name: "", content_sector_id: 0, contact_name: "", contact_email: "", contact_phone: "", note: "", authorization_declared: false };

export default function AgencyPanelPage() {
    document.title = "Painel da agência | Xplendor";
    const wc = useWorkingCompany();
    const agencyId = wc?.agencyMode ? wc.workingId : 0;
    const user: any = readAuthUser();
    const isAgencyAdmin = !!wc?.isRoot || (user?.role === "admin" && !user?.impersonating);
    const [searchParams, setSearchParams] = useSearchParams();
    const tabParam = searchParams.get("tab") as Tab | null;
    const tab: Tab = tabParam === "pedidos" || (tabParam === "atribuicoes" && isAgencyAdmin) ? tabParam : "clientes";
    const setTab = (t: Tab) => setSearchParams((prev) => { const n = new URLSearchParams(prev); n.set("tab", t); return n; }, { replace: true });

    if (!agencyId) {
        return (
            <div className="page-content"><Container fluid>
                <BreadCrumb title="Painel da agência" pageTitle="Agência" />
                <Card><CardBody className="text-muted">O Painel da agência está disponível no contexto da agência. Escolha a agência no seletor "A trabalhar em".</CardBody></Card>
            </Container></div>
        );
    }

    const tabs: [Tab, string, string][] = [["clientes", "Clientes", "ri-dashboard-line"], ["pedidos", "Pedidos de empresa", "ri-file-add-line"]];
    if (isAgencyAdmin) tabs.push(["atribuicoes", "Atribuições", "ri-team-line"]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <BreadCrumb title="Painel da agência" pageTitle="Agência" />
                <Nav tabs className="nav-tabs-custom mb-3 flex-nowrap overflow-auto text-nowrap">
                    {tabs.map(([k, l, i]) => (
                        <NavItem key={k}><NavLink href="#" active={tab === k} onClick={(e) => { e.preventDefault(); setTab(k); }}><i className={`${i} me-1`} />{l}</NavLink></NavItem>
                    ))}
                </Nav>
                {tab === "clientes" && <ClientsTab agencyId={agencyId} />}
                {tab === "pedidos" && <RequestsTab agencyId={agencyId} />}
                {tab === "atribuicoes" && isAgencyAdmin && <AssignmentsTab agencyId={agencyId} />}
            </Container>
        </div>
    );
}

function ClientsTab({ agencyId }: { agencyId: number }) {
    const [rows, setRows] = useState<PanelRow[] | null>(null);
    useEffect(() => {
        getAgencyPanel(agencyId).then((r: any) => setRows(r?.data?.rows ?? [])).catch(() => setRows([]));
    }, [agencyId]);

    const mes = thisMonth();
    const cell = (row: PanelRow, n: number, vista: string, tone: string, label: string) => {
        const to = `/editorial?cliente=${row.company.id}&vista=${vista}&mes=${mes}`;
        return n > 0
            ? <Link to={to} className={`fw-semibold text-${tone}`} aria-label={`${label}: ${n}, abrir a Linha Editorial de ${row.company.name}`}>{n}</Link>
            : <Link to={to} className="text-muted" aria-label={`${label}: 0, abrir a Linha Editorial de ${row.company.name}`}>0</Link>;
    };
    const totals = useMemo(() => (rows ?? []).reduce((a, r) => ({ today: a.today + r.today, overdue: a.overdue + r.overdue, awaiting: a.awaiting + r.awaiting, production: a.production + r.production }), { today: 0, overdue: 0, awaiting: 0, production: 0 }), [rows]);

    if (rows === null) return <div className="text-center py-5"><Spinner color="primary" /></div>;

    return (
        <Card>
            <CardHeader className="d-flex flex-wrap align-items-center gap-2">
                <div className="me-auto">
                    <h5 className="mb-0">Clientes</h5>
                    <small className="text-muted">Clique num número para abrir a Linha Editorial desse cliente.</small>
                </div>
                <Link to="/editorial?cliente=todos" className="btn btn-soft-primary btn-sm"><i className="ri-calendar-2-line me-1" />Linha Editorial de todos</Link>
            </CardHeader>
            <CardBody>
                {rows.length === 0 ? (
                    <p className="text-muted mb-0">Ainda não há clientes com a Linha Editorial ativa nesta agência.</p>
                ) : (
                    <div className="table-responsive">
                        <Table className="align-middle mb-0" data-testid="agency-panel">
                            <thead className="table-light">
                                <tr>
                                    <th>Cliente</th>
                                    <th className="text-center">Para publicar hoje</th>
                                    <th className="text-center">Atrasadas</th>
                                    <th className="text-center">À espera de aprovação</th>
                                    <th className="text-center" title="Produção e Revisão interna">Em produção</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.company.id}>
                                        <td>
                                            <span className="d-inline-flex align-items-center gap-2">
                                                <ClientMark name={r.company.name} logoPath={r.company.logo_path} size={26} />
                                                <span>{r.company.name}{r.company.is_agency && <span className="text-muted fs-12 ms-1">(a agência)</span>}</span>
                                            </span>
                                        </td>
                                        <td className="text-center">{cell(r, r.today, "calendario", "success", "Para publicar hoje")}</td>
                                        <td className="text-center">{cell(r, r.overdue, "calendario", "danger", "Atrasadas")}</td>
                                        <td className="text-center">{cell(r, r.awaiting, "kanban", "warning", "À espera de aprovação")}</td>
                                        <td className="text-center">{cell(r, r.production, "kanban", "primary", "Em produção")}</td>
                                    </tr>
                                ))}
                            </tbody>
                            {rows.length > 1 && (
                                <tfoot>
                                    <tr className="fw-semibold">
                                        <td>Total</td>
                                        <td className="text-center">{totals.today}</td>
                                        <td className="text-center">{totals.overdue}</td>
                                        <td className="text-center">{totals.awaiting}</td>
                                        <td className="text-center">{totals.production}</td>
                                    </tr>
                                </tfoot>
                            )}
                        </Table>
                    </div>
                )}
                <p className="text-muted fs-12 mt-3 mb-0"><i className="ri-information-line me-1" />"Em produção" conta as publicações em Produção e em Revisão interna (o trabalho ainda do lado da equipa).</p>
            </CardBody>
        </Card>
    );
}

function RequestsTab({ agencyId }: { agencyId: number }) {
    const [list, setList] = useState<Req[] | null>(null);
    const [canRequest, setCanRequest] = useState(false);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState(emptyForm);
    const [sectors, setSectors] = useState<{ value: number; label: string }[]>([]);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        getAgencyCompanyRequests(agencyId).then((r: any) => { setList(r?.data?.requests ?? []); setCanRequest(!!r?.data?.can_request); }).catch(() => setList([]));
    }, [agencyId]);
    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        if (!open || sectors.length) return;
        getEditorialSectors(agencyId).then((r: any) => setSectors((r?.data?.sectors ?? []).map((s: any) => ({ value: s.id, label: s.name })))).catch(() => setSectors([]));
    }, [open, agencyId, sectors.length]);

    const set = (k: keyof typeof emptyForm, v: any) => setForm((f) => ({ ...f, [k]: v }));
    const missing = !form.name.trim() ? "Indique o nome da empresa." : !form.authorization_declared ? "Confirme que tem autorização do cliente." : "";
    const submit = async () => {
        setBusy(true);
        try {
            await createAgencyCompanyRequest(agencyId, { ...form, content_sector_id: form.content_sector_id || null });
            toast.success("Pedido enviado à XPLENDOR.");
            setOpen(false);
            setForm(emptyForm);
            load();
        } catch (e: any) { toast.error(errorText(e, "Não foi possível enviar o pedido.")); }
        finally { setBusy(false); }
    };

    if (list === null) return <div className="text-center py-5"><Spinner color="primary" /></div>;

    return (
        <Card>
            <CardHeader className="d-flex flex-wrap align-items-center gap-2">
                <div className="me-auto">
                    <h5 className="mb-0">Pedidos de nova empresa gerida</h5>
                    <small className="text-muted">A XPLENDOR aprova ou recusa cada pedido. Quando aprovado, a empresa aparece no seletor "A trabalhar em".</small>
                </div>
                {canRequest
                    ? <Button color="primary" size="sm" onClick={() => setOpen(true)}><i className="ri-add-line me-1" />Pedir nova empresa</Button>
                    : <small className="text-muted"><i className="ri-information-line me-1" />Só o administrador da agência pede novas empresas.</small>}
            </CardHeader>
            <CardBody>
                {list.length === 0 ? <p className="text-muted mb-0">Ainda não há pedidos.</p> : (
                    <div className="table-responsive">
                        <Table className="align-middle mb-0" data-testid="agency-requests">
                            <thead className="table-light"><tr><th>Empresa</th><th>Ramo</th><th>Pedido</th><th>Estado</th></tr></thead>
                            <tbody>
                                {list.map((r) => (
                                    <tr key={r.id}>
                                        <td><div className="fw-medium">{r.name}</div>{r.contact_name && <div className="text-muted fs-12">{r.contact_name}{r.contact_email ? `, ${r.contact_email}` : ""}</div>}</td>
                                        <td>{r.sector?.name ?? <span className="text-muted">Sem ramo</span>}</td>
                                        <td className="text-nowrap">{fmtDate(r.requested_at)}<div className="text-muted fs-12">{r.requested_by}</div></td>
                                        <td>
                                            <Badge color={STATUS[r.status].color} className="fw-normal">{STATUS[r.status].label}</Badge>
                                            {r.decided_at && <div className="text-muted fs-12">{fmtDate(r.decided_at)}</div>}
                                            {r.status === "declined" && r.decline_reason && <div className="fs-12 mt-1">Motivo: {r.decline_reason}</div>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </CardBody>

            <Modal isOpen={open} toggle={() => setOpen(false)} centered>
                <ModalHeader toggle={() => setOpen(false)}>Pedir nova empresa gerida</ModalHeader>
                <ModalBody>
                    <Row className="g-2">
                        <Col xs={12}><Label for="req-name" className="mb-1">Nome da empresa</Label><Input id="req-name" value={form.name} onChange={(e) => set("name", e.target.value)} maxLength={255} /></Col>
                        <Col xs={12}>
                            <Label for="req-sector" className="mb-1">Ramo</Label>
                            <XSelect id="req-sector" searchable options={[{ value: 0, label: "Sem ramo (módulos base)" }, ...sectors]} value={form.content_sector_id} onChange={(v) => set("content_sector_id", v)} />
                        </Col>
                        <Col sm={6}><Label for="req-contact" className="mb-1">Contacto (opcional)</Label><Input id="req-contact" value={form.contact_name} onChange={(e) => set("contact_name", e.target.value)} /></Col>
                        <Col sm={6}><Label for="req-phone" className="mb-1">Telefone (opcional)</Label><Input id="req-phone" value={form.contact_phone} onChange={(e) => set("contact_phone", e.target.value)} /></Col>
                        <Col xs={12}><Label for="req-email" className="mb-1">Email do contacto (opcional)</Label><Input id="req-email" type="email" value={form.contact_email} onChange={(e) => set("contact_email", e.target.value)} /></Col>
                        <Col xs={12}><Label for="req-note" className="mb-1">Nota (opcional)</Label><Input id="req-note" type="textarea" rows={3} value={form.note} onChange={(e) => set("note", e.target.value)} maxLength={2000} /></Col>
                        <Col xs={12}>
                            <div className="form-check mt-1">
                                <Input id="req-auth" type="checkbox" className="form-check-input" checked={form.authorization_declared} onChange={(e) => set("authorization_declared", e.target.checked)} />
                                <Label for="req-auth" className="form-check-label">Declaro que tenho autorização do cliente para o gerir na XPLENDOR.</Label>
                            </div>
                        </Col>
                    </Row>
                </ModalBody>
                <ModalFooter className="flex-wrap">
                    {missing && <small className="text-muted me-auto">{missing}</small>}
                    <Button color="light" onClick={() => setOpen(false)}>Cancelar</Button>
                    <Button color="primary" disabled={!!missing || busy} onClick={submit}>{busy ? <Spinner size="sm" /> : "Enviar pedido"}</Button>
                </ModalFooter>
            </Modal>
        </Card>
    );
}

function AssignmentsTab({ agencyId }: { agencyId: number }) {
    const [members, setMembers] = useState<Member[]>([]);
    const [rows, setRows] = useState<Assignment[] | null>(null);
    const [saving, setSaving] = useState(0);

    useEffect(() => {
        getAgencyAssignments(agencyId).then((r: any) => { setMembers(r?.data?.members ?? []); setRows(r?.data?.clients ?? []); }).catch(() => setRows([]));
    }, [agencyId]);

    const memberOptions = useMemo(() => members.filter((m) => m.role !== "admin").map((m) => ({ value: m.id, label: m.name })), [members]);
    const update = (companyId: number, patch: Partial<Assignment>) => setRows((list) => (list ?? []).map((r) => (r.company.id === companyId ? { ...r, ...patch } : r)));
    const save = async (row: Assignment) => {
        setSaving(row.company.id);
        try {
            await setAgencyAssignment(agencyId, row.company.id, row.team_scope, row.team_scope === "assigned" ? row.member_ids : []);
            toast.success(`Atribuições de ${row.company.name} guardadas.`);
        } catch (e: any) { toast.error(errorText(e, "Não foi possível guardar.")); }
        finally { setSaving(0); }
    };

    if (rows === null) return <div className="text-center py-5"><Spinner color="primary" /></div>;

    return (
        <Card>
            <CardHeader>
                <h5 className="mb-0">Atribuições</h5>
                <small className="text-muted">Por omissão toda a equipa vê todos os clientes. Pode limitar um cliente a pessoas escolhidas: quem não está atribuído deixa de o ver em todo o lado. Os administradores da agência veem sempre todos.</small>
            </CardHeader>
            <CardBody>
                {rows.length === 0 ? <p className="text-muted mb-0">A agência ainda não gere clientes.</p> : (
                    <div className="d-flex flex-column gap-3" data-testid="agency-assignments">
                        {rows.map((r) => (
                            <div key={r.company.id} className="border rounded p-3">
                                <Row className="g-2 align-items-center">
                                    <Col md={4} className="d-flex align-items-center gap-2">
                                        <ClientMark name={r.company.name} logoPath={r.company.logo_path} size={28} />
                                        <span className="fw-medium">{r.company.name}</span>
                                    </Col>
                                    <Col md={3}>
                                        <XSelect<"all" | "assigned"> small ariaLabel={`Quem vê ${r.company.name}`} value={r.team_scope}
                                            options={[{ value: "all", label: "Toda a equipa" }, { value: "assigned", label: "Só pessoas escolhidas" }]}
                                            onChange={(v) => update(r.company.id, { team_scope: v })} />
                                    </Col>
                                    <Col md={4}>
                                        {r.team_scope === "assigned" ? (
                                            <Select isMulti styles={reactSelectTheme} menuPortalTarget={document.body} aria-label={`Pessoas atribuídas a ${r.company.name}`}
                                                placeholder="Escolher pessoas…" noOptionsMessage={() => "Sem pessoas"} options={memberOptions}
                                                value={memberOptions.filter((o) => r.member_ids.includes(o.value))}
                                                onChange={(v: any) => update(r.company.id, { member_ids: (v ?? []).map((o: any) => o.value) })} />
                                        ) : <span className="text-muted fs-13">Toda a equipa da agência vê este cliente.</span>}
                                    </Col>
                                    <Col md={1} className="text-md-end">
                                        <Button color="soft-primary" size="sm" disabled={saving === r.company.id} onClick={() => save(r)}>{saving === r.company.id ? <Spinner size="sm" /> : "Guardar"}</Button>
                                    </Col>
                                </Row>
                                {r.team_scope === "assigned" && r.member_ids.length === 0 && (
                                    <div className="text-warning fs-12 mt-2"><i className="ri-error-warning-line me-1" />Sem pessoas escolhidas, só os administradores da agência veem este cliente.</div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </CardBody>
        </Card>
    );
}
