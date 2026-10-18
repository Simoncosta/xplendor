import { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Badge, Button, Col, Container, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ActionsMenu from "Components/Common/ActionsMenu";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { useCan } from "contexts/ModulesContext";
import { confirmAction } from "helpers/swal";
import { getWorkingCompanyId } from "helpers/workingCompany";
import { deleteInboundContact, getInboundContact, getInboundContacts, updateInboundContact } from "helpers/laravel_helper";
import type { InboundContact, InboundContactOptions } from "common/models/consultantSite.model";

/**
 * Contactos recebidos: a central dos contactos que chegam (hoje, pelo site do consultor). São dados
 * pessoais: só quem tem a permissão "Contactos recebidos" os vê, e nunca a agência. Cada contacto
 * leva os consentimentos, a versão da política aceite e a origem da visita.
 */

const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;
const when = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "");
const STATUS_COLOR: Record<string, string> = { novo: "primary", tratado: "success", arquivado: "secondary" };
const DETAIL_LABELS: Record<string, string> = {
    property_kind: "Tipo de imóvel", best_time: "Melhor hora", typologies: "Tipologias", zones_text: "Zonas", budget_max: "Orçamento até",
    timeframe: "Prazo", needs_credit: "Precisa de crédito", property_value: "Valor do imóvel", preferred_time: "Disponibilidade para a visita",
};
const VALUE_LABELS: Record<string, string> = {
    manha: "Manhã", tarde: "Tarde", fim_do_dia: "Fim do dia", qualquer: "Qualquer hora", ate_3_meses: "Até 3 meses", "3_a_6_meses": "3 a 6 meses",
    "6_a_12_meses": "6 a 12 meses", mais_de_12_meses: "Mais de 12 meses",
};
const show = (k: string, v: any): string => {
    if (v === true) return "Sim";
    if (v === false) return "Não";
    if (Array.isArray(v)) return v.join(", ");
    if ((k === "budget_max" || k === "property_value") && typeof v === "number") return `${v.toLocaleString("pt-PT")} €`;
    return VALUE_LABELS[String(v)] ?? String(v);
};

function ContactModal({ companyId, id, canEdit, onClose, onChanged }: {
    companyId: number; id: number | null; canEdit: boolean; onClose: () => void; onChanged: () => void;
}) {
    const [c, setC] = useState<InboundContact | null>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        setC(null);
        if (id) getInboundContact(companyId, id).then((r: any) => setC(r.data)).catch((e: any) => toast.error(errorText(e, "Não foi possível abrir o contacto.")));
    }, [companyId, id]);
    const setStatus = async (status: string) => {
        if (!c) return;
        setBusy(true);
        try { const r: any = await updateInboundContact(companyId, c.id, status); setC(r.data); onChanged(); }
        catch (e: any) { toast.error(errorText(e, "Não foi possível atualizar o contacto.")); }
        finally { setBusy(false); }
    };
    const details = Object.entries(c?.details ?? {}).filter(([k]) => k !== "parish_id" && k !== "parish_ids");
    const parishes = Object.values(c?.parishes ?? {});
    return (
        <Modal isOpen={!!id} toggle={onClose} size="lg" centered scrollable data-testid="contact-modal">
            <ModalHeader toggle={onClose}>{c ? c.summary ?? c.type_label : "Contacto"}</ModalHeader>
            <ModalBody>
                {!c ? <div className="text-center py-4"><Spinner /></div> : (
                    <Row className="g-3 fs-13">
                        <Col md={4}><div className="text-muted">Nome</div>{c.name}</Col>
                        <Col md={4}><div className="text-muted">Email</div>{c.email ? <a href={`mailto:${c.email}`}>{c.email}</a> : null}</Col>
                        <Col md={4}><div className="text-muted">Telefone</div>{c.phone ? <a href={`tel:${c.phone.replace(/\s/g, "")}`}>{c.phone}</a> : null}</Col>
                        <Col md={4}><div className="text-muted">Recebido</div>{when(c.created_at)} ({c.channel_label})</Col>
                        <Col md={4}><div className="text-muted">Pedido</div>{c.type_label}</Col>
                        <Col md={4}><div className="text-muted">Assunto</div>{c.property ? `${c.property.reference} ${c.property.title}` : c.development ? c.development.name : parishes.join(", ") || "Geral"}</Col>
                        {c.message && <Col md={12}><div className="text-muted">Mensagem</div><div style={{ whiteSpace: "pre-wrap" }}>{c.message}</div></Col>}
                        {details.map(([k, v]) => <Col key={k} md={4}><div className="text-muted">{DETAIL_LABELS[k] ?? k}</div>{show(k, v)}</Col>)}
                        {parishes.length > 0 && c.details?.parish_ids && <Col md={12}><div className="text-muted">Zonas</div>{parishes.join(", ")}</Col>}
                        <Col md={12}><h6 className="mt-2 mb-1">Consentimentos</h6>
                            <div>Política de privacidade (versão {c.policy_version ?? "por indicar"}): aceite a {when(c.consent_privacy_at)}</div>
                            <div>Comunicações de marketing: {c.consent_marketing ? `sim, a ${when(c.consent_marketing_at)}` : "não"}</div>
                            {c.type === "credito" && <div>Partilha com o parceiro de crédito: {c.consent_credit_share ? `sim, a ${when(c.consent_credit_share_at)}` : "não"}</div>}
                            {c.retain_until && <div className="text-muted mt-1">Conservado até {new Date(`${c.retain_until}T12:00:00`).toLocaleDateString("pt-PT")}.</div>}</Col>
                        <Col md={12}><h6 className="mt-2 mb-1">Origem</h6>
                            <div className="text-muted">{[c.origin?.utm_source && `fonte ${c.origin.utm_source}`, c.origin?.utm_medium && `meio ${c.origin.utm_medium}`, c.origin?.utm_campaign && `campanha ${c.origin.utm_campaign}`,
                                c.origin?.gclid && "anúncio Google", c.origin?.fbclid && "anúncio Meta", c.origin?.referrer_host && `vindo de ${c.origin.referrer_host}`,
                                c.origin?.landing_path && `entrada em ${c.origin.landing_path}`].filter(Boolean).join(" · ") || "Acesso direto."}</div></Col>
                    </Row>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose}>Fechar</Button>
                {c && canEdit && c.status !== "arquivado" && <Button color="outline-primary" disabled={busy} onClick={() => void setStatus("arquivado")}>Arquivar</Button>}
                {c && canEdit && c.status !== "novo" && <Button color="outline-primary" disabled={busy} onClick={() => void setStatus("novo")}>Marcar como novo</Button>}
                {c && canEdit && c.status !== "tratado" && <Button color="success" disabled={busy} onClick={() => void setStatus("tratado")} data-testid="contact-handled">Marcar como tratado</Button>}
            </ModalFooter>
        </Modal>
    );
}

export default function ContactosPage() {
    document.title = "Contactos recebidos | Xplendor";
    const companyId = getWorkingCompanyId();
    const [params] = useSearchParams();
    const [rows, setRows] = useState<InboundContact[]>([]);
    const [options, setOptions] = useState<InboundContactOptions | null>(null);
    const [loading, setLoading] = useState(true);
    const [status, setStatus] = useState("");
    const [type, setType] = useState("");
    const [open, setOpen] = useState<number | null>(null);
    const [canEdit] = useCan("contactos.editar");
    const [canDelete] = useCan("contactos.apagar");

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getInboundContacts(companyId, { ...(status ? { status } : {}), ...(type ? { type } : {}) });
            setRows(r?.data?.contacts ?? []);
            setOptions(r?.data?.options ?? null);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível carregar os contactos."));
        } finally {
            setLoading(false);
        }
    }, [companyId, status, type]);
    useEffect(() => { void load(); }, [load]);
    useEffect(() => { const id = Number(params.get("id")); if (id) setOpen(id); }, [params]);

    const remove = async (c: InboundContact) => {
        if (!(await confirmAction({ title: `Apagar o contacto de ${c.name}?`, text: "Os dados pessoais deixam de aparecer na XPLENDOR.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" }))) return;
        try { await deleteInboundContact(companyId, c.id); toast.success("Contacto apagado."); void load(); }
        catch (e: any) { toast.error(errorText(e, "Não foi possível apagar o contacto.")); }
    };

    const cols = useDataColumns<InboundContact>("contactos.recebidos", [
        { id: "created", header: "Recebido", value: (c) => c.created_at ?? "", cell: (c) => when(c.created_at), nowrap: true, mobile: "subtitle" },
        { id: "name", header: "Nome", value: (c) => c.name, mobile: "title", hideable: false },
        { id: "type", header: "Pedido", value: (c) => c.type_label },
        { id: "subject", header: "Assunto", value: (c) => c.property?.reference ?? c.development?.name ?? "", cell: (c) => c.property ? <>{c.property.reference}<div className="text-muted fs-12">{c.property.title}</div></> : c.development ? c.development.name : <span className="text-muted">Geral</span> },
        { id: "contact", header: "Contacto", value: (c) => `${c.email ?? ""} ${c.phone ?? ""}`, cell: (c) => <>{c.email}<div className="text-muted fs-12">{c.phone}</div></> },
        { id: "origin", header: "Origem", value: (c) => c.utm_source ?? "", cell: (c) => <>{c.channel_label}{c.utm_source ? <div className="text-muted fs-12">{c.utm_source}{c.utm_campaign ? ` · ${c.utm_campaign}` : ""}</div> : null}</>, defaultVisible: false },
        { id: "status", header: "Estado", value: (c) => c.status, cell: (c) => <Badge color={`${STATUS_COLOR[c.status]}-subtle`} className={`text-${STATUS_COLOR[c.status]} fw-normal`}>{options?.statuses[c.status] ?? c.status}</Badge> },
        { id: "actions", header: "", hideable: false, cell: (c) => (
            <div className="d-flex gap-1 justify-content-end">
                <Button size="sm" color="secondary" outline onClick={() => setOpen(c.id)} aria-label={`Abrir o contacto de ${c.name}`}><i className="ri-eye-line" /></Button>
                {canDelete && <ActionsMenu size="sm" label={`Mais ações: ${c.name}`} items={[{ label: "Apagar", icon: "ri-delete-bin-line", danger: true, onClick: () => void remove(c) }]} />}
            </div>
        ) },
    ] as DTColumn<InboundContact>[]);
    const statusOptions = useMemo(() => [{ value: "", label: "Todos os estados" }, ...Object.entries(options?.statuses ?? {}).map(([value, label]) => ({ value, label }))], [options]);
    const typeOptions = useMemo(() => [{ value: "", label: "Todos os pedidos" }, ...Object.entries(options?.types ?? {}).map(([value, label]) => ({ value, label }))], [options]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Contactos recebidos" breadcrumbs={[{ label: "Imobiliário" }]}
                    info="Os pedidos que chegam pelo site: contacto, visita, estudo da zona, avaliação, procura e crédito. São dados pessoais: só os vê quem tem esta permissão." />
                <PageCard title="Contactos" data-testid="contacts-page"
                    actions={<>
                        {cols.selector}
                        <div style={{ minWidth: 170 }}><XSelect<string> small ariaLabel="Pedido" options={typeOptions} value={type} onChange={setType} /></div>
                        <div style={{ minWidth: 160 }}><XSelect<string> small ariaLabel="Estado" options={statusOptions} value={status} onChange={setStatus} /></div>
                    </>}>
                    <DataTable columns={cols} data={rows} rowKey={(c) => c.id} loading={loading} caption="Contactos recebidos" empty={{ message: "Ainda não chegaram contactos." }} />
                </PageCard>
            </Container>
            <ContactModal companyId={companyId} id={open} canEdit={canEdit} onClose={() => setOpen(null)} onChanged={() => void load()} />
        </div>
    );
}
